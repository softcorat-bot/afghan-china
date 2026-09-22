<?php

namespace App\Services\Offline;

use App\Models\OfflineMeta;
use App\Models\OfflineOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * One full synchronization cycle. The "Sync Now" button, the CLI command and
 * the scheduler all run this — there is exactly one implementation:
 *
 *   1. reachability check (short timeout — offline must fail fast, not hang)
 *   2. heartbeat (tell Central who is syncing and what is queued)
 *   3. push pending outbox rows, dependency-ordered, in batches
 *   4. refresh company/RBAC context (cheap, keeps roles fresh)
 *   5. pull loop (while has_more) → transactional apply → cursor advance
 *   6. ack the cursor + per-row failures
 *   7. record meta + logs, return the summary
 *
 * Transport failures leave the outbox and cursor untouched: everything stays
 * retryable, and a retry can never apply anything twice (server ledger).
 */
class SyncRunner
{
    public function __construct(
        private readonly CentralClient $central,
        private readonly PullApplier $applier,
    ) {
    }

    public static function make(): self
    {
        return new self(CentralClient::fromIdentity(), new PullApplier);
    }

    /**
     * @param  array{push?:bool, pull?:bool, tables?:array, reason?:string}  $options
     * @return array summary of the run
     *
     * @throws OfflineSyncException
     */
    public function run(array $options = []): array
    {
        if (! config('offline.enabled')) {
            throw OfflineSyncException::notConfigured('Offline Mode is not enabled on this installation (OFFLINE_MODE=true).');
        }

        $doPush = (bool) ($options['push'] ?? true);
        $doPull = (bool) ($options['pull'] ?? true);
        $reason = (string) ($options['reason'] ?? 'manual');
        $started = now();
        $log = Log::channel('offline');

        $summary = [
            'ok' => false,
            'reason' => $reason,
            'started_at' => $started->toIso8601String(),
            'cursor_before' => (int) OfflineMeta::get('sync.cursor', 0),
            'push' => ['pending' => 0, 'applied' => 0, 'duplicates' => 0, 'conflicts' => 0, 'rejected' => 0, 'errors' => 0],
            'pull' => ['applied' => 0, 'failed' => 0, 'conflicts' => 0],
            'central_conflicts_pending' => 0,
        ];

        $log->info('sync started', ['reason' => $reason, 'cursor' => $summary['cursor_before']]);

        try {
            // 1–2. Reachability + heartbeat.
            $status = $this->central->status((int) config('offline.connect_timeout', 8));
            $summary['server_seq'] = $status['server_seq'] ?? null;
            $summary['central_conflicts_pending'] = (int) ($status['conflicts_pending'] ?? 0);

            $counts = $this->counts();
            $this->central->heartbeat(['pending' => $counts['pending'], 'failed' => $counts['failed']]);

            // 3. Push.
            if ($doPush) {
                $summary['push'] = array_merge($summary['push'], $this->pushAll($log));
            }

            // 4. Context (company/RBAC) refresh.
            if ($doPull) {
                try {
                    $this->applier->applyContext($this->central->context());
                } catch (OfflineSyncException $e) {
                    // An older Central has no context endpoint: seed stays as it
                    // was, the sequenced pull below still runs.
                    $log->warning('context refresh skipped: '.$e->getMessage());
                }

                // 5–6. Pull loop + ack.
                $summary['pull'] = array_merge($summary['pull'], $this->pullAll($options['tables'] ?? [], $log));
            }

            try {
                $conflicts = $this->central->conflicts(true, 1);
                $summary['central_conflicts_pending'] = (int) ($conflicts['pending'] ?? $summary['central_conflicts_pending']);
            } catch (OfflineSyncException $e) {
                // Non-critical; the counts above already tell the story.
            }

            $failed = $summary['push']['rejected'] + $summary['push']['errors'] + $summary['pull']['failed'];
            $summary['ok'] = true;
            $summary['finished_at'] = now()->toIso8601String();
            $summary['cursor_after'] = (int) OfflineMeta::get('sync.cursor', 0);

            OfflineMeta::set('sync.last_run_at', now()->toIso8601String());
            OfflineMeta::set('sync.last_summary', $summary);
            OfflineMeta::set('sync.last_error', $failed ? 'Last sync completed with failures — see Sync Center.' : null);

            if ($failed === 0 && $summary['push']['conflicts'] === 0 && $summary['pull']['conflicts'] === 0) {
                OfflineMeta::set('sync.last_ok_at', now()->toIso8601String());
            }

            $log->info('sync completed', $summary);

            return $summary;
        } catch (OfflineSyncException $e) {
            OfflineMeta::set('sync.last_run_at', now()->toIso8601String());
            OfflineMeta::set('sync.last_error', $e->getMessage());
            $log->warning('sync failed: '.$e->getMessage(), ['code' => $e->syncCode]);

            throw $e;
        }
    }

    // ── Push ─────────────────────────────────────────────────────────────────

    private function pushAll($log): array
    {
        $result = ['pending' => 0, 'applied' => 0, 'duplicates' => 0, 'conflicts' => 0, 'rejected' => 0, 'errors' => 0];
        $order = array_flip(config('offline.push_order', []));

        $rows = OfflineOutbox::pending()->orderBy('id')->get()
            ->sortBy(fn ($r) => [$order[$r->entity_type] ?? 99, $r->id])->values();

        $result['pending'] = $rows->count();

        if ($rows->isEmpty()) {
            return $result;
        }

        foreach ($rows->chunk((int) config('offline.push_batch', 200)) as $batch) {
            $ids = $batch->pluck('id')->all();
            OfflineOutbox::query()->whereIn('id', $ids)->update(['status' => OfflineOutbox::STATUS_PROCESSING]);

            foreach ($batch as $row) {
                $this->refreshPayload($row);
            }

            $changes = $batch->map(fn ($row) => [
                'change_uuid' => $row->change_uuid,
                'entity_type' => $row->entity_type,
                'operation' => $row->operation,
                'uuid' => $row->entity_uuid,
                'base_revision' => $row->base_revision,
                'captured_at' => optional($row->captured_at)->toIso8601String(),
                'payload' => $row->payload ?? [],
            ])->values()->all();

            try {
                $response = $this->central->push($changes, 'offline-'.(string) Str::uuid());
            } catch (OfflineSyncException $e) {
                // Transport died mid-batch: everything goes back to pending.
                // The server may have applied some of them — the ledger replays
                // those as duplicates on retry instead of doubling them.
                OfflineOutbox::query()->whereIn('id', $ids)
                    ->where('status', OfflineOutbox::STATUS_PROCESSING)
                    ->update(['status' => OfflineOutbox::STATUS_PENDING]);

                throw $e;
            }

            $byKey = collect($response['results'] ?? [])->keyBy('change_uuid');

            foreach ($batch as $row) {
                /** @var OfflineOutbox $fresh */
                $fresh = $row->fresh();

                $answer = $byKey->get($row->change_uuid);

                if (! $answer) {
                    $fresh->forceFill(['status' => OfflineOutbox::STATUS_PENDING])->save();
                    $result['errors']++;

                    continue;
                }

                $status = (string) ($answer['status'] ?? 'error');

                match ($status) {
                    'applied' => $this->markSynced($fresh, $answer, $result, 'applied'),
                    'duplicate' => $this->markSynced($fresh, $answer, $result, 'duplicates'),
                    'conflict' => $this->mark($fresh, OfflineOutbox::STATUS_CONFLICT, $answer, $result, 'conflicts'),
                    'rejected' => $this->mark($fresh, OfflineOutbox::STATUS_FAILED, $answer, $result, 'rejected'),
                    default => $this->mark($fresh, OfflineOutbox::STATUS_FAILED, $answer, $result, 'errors'),
                };
            }

            $log->info('push batch', ['batch' => $response['batch_uuid'] ?? '?', 'summary' => $response['summary'] ?? []]);
        }

        return $result;
    }

    /**
     * Rebuild the payload from the live row just before pushing.
     *
     * The outbox snapshot is captured when the model event fires — for a sale
     * that is *before* its lines and payments are written (same transaction,
     * later statements). Rebuilding at push time means Central always receives
     * the committed, complete business document, including any later edits.
     * When the row cannot be resolved (or the rebuild fails), the stored
     * snapshot is pushed instead — a stale payload beats no payload, and the
     * server still validates everything. Tombstones are never rebuilt.
     */
    private function refreshPayload(OfflineOutbox $row): void
    {
        if ($row->operation === 'delete') {
            return;
        }

        $models = array_flip(config('offline.observed', []));
        $class = $models[$row->entity_type] ?? null;

        if (! $class || ! class_exists($class)) {
            return;
        }

        $query = $class::withoutGlobalScopes()->where('uuid', $row->entity_uuid);

        if (in_array('Illuminate\Database\Eloquent\SoftDeletes', class_uses_recursive($class), true)) {
            $query = $query->withTrashed();
        }

        $model = $query->first();

        if (! $model) {
            return;
        }

        try {
            $change = app(ChangeBuilder::class)->build($model, $row->entity_type, $row->operation);
        } catch (\Throwable $e) {
            report($e);

            return;
        }

        $row->forceFill([
            'payload' => $change['payload'],
            'base_revision' => $change['base_revision'] ?? $row->base_revision,
        ])->save();
    }

    private function markSynced(OfflineOutbox $row, array $answer, array &$result, string $bucket): void
    {
        $row->forceFill([
            'status' => OfflineOutbox::STATUS_SYNCED,
            'attempts' => $row->attempts + 1,
            'last_error' => null,
            'server_id' => isset($answer['server_id']) ? (string) $answer['server_id'] : $row->server_id,
            'server_uuid' => $answer['server_uuid'] ?? $row->server_uuid,
            'processed_at' => now(),
        ])->save();

        $result[$bucket]++;
    }

    private function mark(OfflineOutbox $row, string $status, array $answer, array &$result, string $bucket): void
    {
        $row->forceFill([
            'status' => $status,
            'attempts' => $row->attempts + 1,
            'last_error' => mb_substr((string) ($answer['message'] ?? $status), 0, 2000),
            'server_id' => isset($answer['server_id']) ? (string) $answer['server_id'] : $row->server_id,
            'processed_at' => now(),
        ])->save();

        $result[$bucket]++;
    }

    // ── Pull ─────────────────────────────────────────────────────────────────

    private function pullAll(array $tables, $log): array
    {
        $result = ['applied' => 0, 'failed' => 0, 'conflicts' => 0];
        $cursor = (int) OfflineMeta::get('sync.cursor', 0);
        $pages = 0;

        do {
            $pages++;

            $response = $this->central->pull($cursor, $tables);

            $page = $this->applier->applyPage(
                $response['data'] ?? [],
                $response['deleted'] ?? [],
                $cursor
            );

            $result['applied'] += count($page['applied']);
            $result['failed'] += count($page['failed']);
            $result['conflicts'] += count($page['conflicts']);

            $cursor = (int) $page['cursor'];
            OfflineMeta::set('sync.cursor', $cursor);

            try {
                $this->central->ack($cursor, $page['applied'], $page['failed']);
            } catch (OfflineSyncException $e) {
                $log->warning('ack failed (cursor kept locally): '.$e->getMessage());
            }

            $hasMore = (bool) ($response['has_more'] ?? false);

            // Safety valve: a runaway server must not loop this worker forever.
            if ($pages >= 200) {
                $log->warning('pull stopped after 200 pages; resume on next sync', ['cursor' => $cursor]);

                break;
            }
        } while ($hasMore);

        return $result;
    }

    private function counts(): array
    {
        $rows = OfflineOutbox::query()
            ->select('status', DB::raw('count(*) as n'))
            ->groupBy('status')->pluck('n', 'status');

        return [
            'pending' => (int) ($rows[OfflineOutbox::STATUS_PENDING] ?? 0),
            'failed' => (int) ($rows[OfflineOutbox::STATUS_FAILED] ?? 0),
        ];
    }
}
