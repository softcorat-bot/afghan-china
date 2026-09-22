<?php

namespace App\Services\Sync;

use App\Models\PosDevice;
use App\Models\SyncBatch;
use App\Support\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Server → POS: everything that changed since the device last looked.
 *
 * Rows carry a `sync_seq` stamp from one global counter, so "what changed" is a
 * single ordered stream rather than a per-table guess. A device sends the last
 * cursor it stored, the server answers with the next slice and the new cursor,
 * and repeats while `has_more` is true. One record or ten thousand — the same
 * request, and never the whole database again.
 */
class SyncPuller
{
    /** @return array{data:array, next_cursor:int, has_more:bool, server_time:string, deleted:array} */
    public function pull(PosDevice $device, int $sinceSeq, array $tables, int $limit, ?string $appVersion, ?string $ip): array
    {
        $seqColumn = config('sync.seq_column', 'sync_seq');
        $limit = max(1, min($limit, (int) config('sync.max_pull_limit', 2000)));
        $allowed = array_values(array_intersect($tables ?: config('sync.pull_tables', []), array_keys(config('sync.tables'))));

        $batch = SyncBatch::create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $device->company_id,
            'branch_id' => $device->branch_id,
            'device_id' => $device->device_id,
            'direction' => 'pull',
            'status' => 'completed',
            'cursor_before' => $sinceSeq,
            'app_version' => $appVersion,
            'ip' => $ip,
            'started_at' => now(),
        ]);

        $data = [];
        $deleted = [];
        $skipped = [];
        $maxSeq = $sinceSeq;
        $rowsSent = 0;

        // One ordered stream across all requested tables: the device can stop at
        // any point and resume exactly where it stopped.
        foreach ($allowed as $table) {
            $class = config('sync.tables')[$table] ?? null;

            if (! $class || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            // A table that has not been migrated into the sync stream cannot be
            // served; saying so beats failing every other table's pull with it.
            if (! \Illuminate\Support\Facades\Schema::hasColumn($table, $seqColumn)) {
                $skipped[] = $table;

                continue;
            }

            $query = $this->query($class, $table, $device)
                ->where($table.'.'.$seqColumn, '>', $sinceSeq)
                ->orderBy($table.'.'.$seqColumn)
                ->limit($limit + 1);

            $rows = $query->get();
            $extra = $rows->count() > $limit;
            $rows = $rows->take($limit);

            foreach ($rows as $row) {
                // An offline installation is the shop's own machine inside its own
                // trust boundary: without the credential hashes, the same staff
                // could not sign in to the same app while offline. This stream is
                // device-authenticated and must always travel over TLS.
                if ($table === 'users') {
                    $row->makeVisible(['password', 'pin']);
                }

                $payload = $row->attributesToArray();
                $maxSeq = max($maxSeq, (int) ($row->{$seqColumn} ?? $sinceSeq));
                $rowsSent++;

                // Soft-deleted rows travel as tombstones so the device can do
                // the same instead of keeping a ghost product for sale.
                if (method_exists($row, 'trashed') && $row->trashed()) {
                    $deleted[$table][] = ['uuid' => $row->uuid, 'sync_seq' => (int) $row->{$seqColumn}];

                    continue;
                }

                $data[$table][] = $payload;
            }

            if ($extra) {
                // This table has more to give; report the cursor as the last row
                // actually delivered so the next call resumes cleanly.
                $lastSeq = collect($data[$table] ?? [])->last()['sync_seq'] ?? $sinceSeq;
                $maxSeq = max($sinceSeq, (int) $lastSeq);
            }
        }

        $hasMore = $rowsSent >= $limit;

        $batch->fill([
            'rows_sent' => $rowsSent,
            'cursor_after' => $maxSeq,
            'finished_at' => now(),
        ])->save();

        $device->forceFill([
            'last_seen_at' => now(),
            'last_pull_at' => now(),
            'last_pull_seq' => $maxSeq,
            'last_ip' => $ip,
            'app_version' => $appVersion ?: $device->app_version,
        ])->save();

        return [
            'data' => $data,
            'deleted' => $deleted,
            'skipped_tables' => $skipped,
            'next_cursor' => $maxSeq,
            'has_more' => $hasMore,
            'server_time' => now()->toIso8601String(),
            'server_seq' => app(SyncSequence::class)->current(),
        ];
    }

    /** A device sees its own branch's rows, its own company's shared masters, nothing else. */
    private function query(string $class, string $table, PosDevice $device)
    {
        $query = $class::query();

        if (method_exists($class, 'booted') && in_array('Illuminate\Database\Eloquent\SoftDeletes', class_uses_recursive($class), true)) {
            $query->withTrashed();
        }

        $columns = \Illuminate\Support\Facades\Schema::getColumnListing($table);

        if (in_array('company_id', $columns, true)) {
            $query->where($table.'.company_id', $device->company_id);
        }

        if (in_array($table, config('sync.branch_scoped', []), true)
            && in_array('branch_id', $columns, true)
            && Branch::id() !== null) {
            $query->where(function ($q) use ($table, $device) {
                $q->where($table.'.branch_id', $device->branch_id)->orWhereNull($table.'.branch_id');
            });
        }

        // A till has no business seeing another tenant's users, and it only needs
        // the people who can actually sign in on it.
        if ($table === 'users' && in_array('active', $columns, true)) {
            $query->where($table.'.active', true);
        }

        return $query;
    }
}
