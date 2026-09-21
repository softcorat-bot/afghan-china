<?php

namespace App\Services\Offline;

use App\Models\OfflineOutbox;
use App\Models\SyncConflict;
use App\Support\Schema as ColumnGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Applies Central's rows to the local SQLite database.
 *
 * Rules:
 *  - inserts preserve Central's ids (the seed guarantees FK compatibility);
 *  - matches are by uuid, never by integer;
 *  - one transaction per page: apply + cursor advance commit together;
 *  - the cursor advances ONLY past applied rows — skipped/failed rows are
 *    re-offered by Central on the next pull (apply is idempotent, so the
 *    re-send is harmless);
 *  - rows with a still-pending local change are skipped and recorded as local
 *    conflicts (local sync_conflicts table: the existing Conflict Center UI
 *    reads it unchanged). The pending push carries the authoritative decision.
 */
class PullApplier
{
    /** Table → conflict entity name (falls back to the table name). */
    private const ENTITIES = [
        'products' => 'product',
        'product_categories' => 'product_category',
        'customers' => 'customer',
        'suppliers' => 'supplier',
        'users' => 'user',
        'counters' => 'counter',
        'branches' => 'branch',
        'sales' => 'sale',
        'refunds' => 'refund',
    ];

    /**
     * @return array{applied:array, failed:array, conflicts:array, cursor:int}
     */
    public function applyPage(array $data, array $deleted, int $since): array
    {
        $applied = [];
        $failed = [];
        $conflicts = [];
        $maxSeq = $since;

        DB::transaction(function () use ($data, $deleted, $since, &$applied, &$failed, &$conflicts, &$maxSeq) {
            OutboxRecorder::withoutRecording(function () use ($data, $deleted, $since, &$applied, &$failed, &$conflicts, &$maxSeq) {
                foreach ($this->orderedTables(array_keys($data)) as $table) {
                    foreach ($data[$table] ?? [] as $row) {
                        $this->applyRow($table, (array) $row, $since, $applied, $failed, $conflicts, $maxSeq);
                    }
                }

                foreach ($deleted as $table => $tombs) {
                    foreach ((array) $tombs as $tomb) {
                        $this->applyTombstone($table, (array) $tomb, $since, $applied, $failed, $maxSeq);
                    }
                }
            });
        });

        return ['applied' => $applied, 'failed' => $failed, 'conflicts' => $conflicts, 'cursor' => $maxSeq];
    }

    /**
     * Seed/refresh the rows that live outside the sequenced stream: the company
     * + branch rows and the RBAC snapshot. Idempotent — safe on every sync.
     */
    public function applyContext(array $context): void
    {
        DB::transaction(function () use ($context) {
            OutboxRecorder::withoutRecording(function () use ($context) {
                $identity = DeviceIdentity::load();

                if (! empty($context['company']['id'])) {
                    if ($identity['company_id'] && (int) $context['company']['id'] !== (int) $identity['company_id']) {
                        throw new \RuntimeException('Central answered for a different company than this installation is registered to. Refusing to seed.');
                    }

                    DB::table('companies')->upsert([$context['company']], ['id']);
                }

                foreach (['company_user', 'roles', 'permissions'] as $table) {
                    $rows = array_values((array) ($context[$table] ?? []));

                    if ($rows === []) {
                        continue;
                    }

                    if ($table === 'company_user') {
                        DB::table($table)->where('company_id', $identity['company_id'] ?? -1)->delete();
                        DB::table($table)->insert($rows);
                    } else {
                        DB::table($table)->upsert($rows, ['id']);
                    }
                }

                // Small global pivots: replace wholesale (they are tiny and this
                // is the documented RBAC refresh; no per-row merge to get wrong).
                if (array_key_exists('role_has_permissions', $context)) {
                    DB::table('role_has_permissions')->delete();
                    $rows = array_values((array) $context['role_has_permissions']);
                    if ($rows !== []) {
                        DB::table('role_has_permissions')->insert($rows);
                    }
                }

                foreach (['model_has_roles', 'model_has_permissions'] as $table) {
                    if (! array_key_exists($table, $context)) {
                        continue;
                    }
                    DB::table($table)->where('model_type', \App\Models\User::class)->delete();
                    $rows = array_values((array) $context[$table]);
                    if ($rows !== []) {
                        DB::table($table)->insert($rows);
                    }
                }

                try {
                    app(PermissionRegistrar::class)->forgetCachedPermissions();
                } catch (\Throwable $e) {
                    // Cache store missing on a fresh box — non-fatal.
                }
            });
        });
    }

    // ── Rows ─────────────────────────────────────────────────────────────────

    private function applyRow(string $table, array $row, int $since, array &$applied, array &$failed, array &$conflicts, int &$maxSeq): void
    {
        $class = config('sync.tables.'.$table);
        $uuid = (string) ($row['uuid'] ?? '');
        $seq = (int) ($row['sync_seq'] ?? $since);

        if (! $class || ! class_exists($class) || $uuid === '') {
            $failed[] = ['table' => $table, 'uuid' => $uuid ?: '?', 'error' => 'Unknown table or row without a uuid.'];

            return;
        }

        try {
            /** @var Model|null $existing */
            $existing = $class::withoutGlobalScopes()
                ->when(method_exists($class, 'booted') && in_array('Illuminate\Database\Eloquent\SoftDeletes', class_uses_recursive($class), true), fn ($q) => $q->withTrashed())
                ->where('uuid', $uuid)->first();

            // A local change that has not been pushed yet wins the right to be
            // heard first: skip, record, and let the pending push decide.
            if ($existing && OfflineOutbox::query()->where('entity_uuid', $uuid)->where('status', OfflineOutbox::STATUS_PENDING)->exists()) {
                $conflicts[] = $this->recordLocalConflict($table, $existing, $row);

                return;
            }

            $attributes = ColumnGuard::only($table, $row);
            unset($attributes['id']);

            $hasDeletedAt = ColumnGuard::has($table, 'deleted_at');

            Model::withoutEvents(function () use ($class, $table, $row, $attributes, $existing, $hasDeletedAt, &$applied) {
                if ($existing) {
                    $existing->timestamps = false;
                    $existing->forceFill($attributes);
                    // A row arriving in `data` is by definition not deleted.
                    if ($hasDeletedAt) {
                        $existing->forceFill(['deleted_at' => null]);
                    }
                    $existing->save();
                } else {
                    /** @var Model $fresh */
                    $fresh = new $class;
                    $fresh->timestamps = false;
                    $fresh->forceFill($attributes);
                    // Preserve Central's id: the whole FK graph below depends on it.
                    if (isset($row['id'])) {
                        $fresh->forceFill(['id' => $row['id']]);
                    }
                    $fresh->exists = false;
                    $fresh->save();
                }

                $applied[] = ['table' => $table, 'uuid' => (string) ($row['uuid'] ?? '')];
            });

            $maxSeq = max($maxSeq, $seq);
        } catch (\Throwable $e) {
            report($e);
            $failed[] = ['table' => $table, 'uuid' => $uuid, 'error' => $e->getMessage()];
        }
    }

    private function applyTombstone(string $table, array $tomb, int $since, array &$applied, array &$failed, int &$maxSeq): void
    {
        $class = config('sync.tables.'.$table);
        $uuid = (string) ($tomb['uuid'] ?? '');
        $seq = (int) ($tomb['sync_seq'] ?? $since);

        if (! $class || ! class_exists($class) || $uuid === '') {
            return;
        }

        try {
            $query = $class::withoutGlobalScopes()->where('uuid', $uuid);

            if (in_array('Illuminate\Database\Eloquent\SoftDeletes', class_uses_recursive($class), true)) {
                $query = $query->withTrashed();
            }

            $existing = $query->first();

            if (! $existing) {
                $maxSeq = max($maxSeq, $seq); // already gone locally — still progress
                $applied[] = ['table' => $table, 'uuid' => $uuid, 'tombstone' => true];

                return;
            }

            if (OfflineOutbox::query()->where('entity_uuid', $uuid)->where('status', OfflineOutbox::STATUS_PENDING)->exists()) {
                return; // local pending change: same rule as rows — push decides
            }

            Model::withoutEvents(function () use ($existing, $class, $table, $uuid, &$applied) {
                if (in_array('Illuminate\Database\Eloquent\SoftDeletes', class_uses_recursive($class), true)) {
                    if (! $existing->trashed()) {
                        $existing->delete();
                    }
                } else {
                    // Table has no soft deletes and Central says the row is gone.
                    $existing->forceDelete();
                }

                $applied[] = ['table' => $table, 'uuid' => $uuid, 'tombstone' => true];
            });

            $maxSeq = max($maxSeq, $seq);
        } catch (\Throwable $e) {
            report($e);
            $failed[] = ['table' => $table, 'uuid' => $uuid, 'error' => $e->getMessage()];
        }
    }

    // ── Local conflicts ──────────────────────────────────────────────────────

    private function recordLocalConflict(string $table, Model $local, array $server): array
    {
        $entity = self::ENTITIES[$table] ?? $table;
        $uuid = (string) $local->uuid;

        $pending = SyncConflict::withoutGlobalScopes()
            ->where('entity_type', $entity)
            ->where('entity_uuid', $uuid)
            ->where('status', SyncConflict::STATUS_PENDING)
            ->latest('id')->first();

        if ($pending) {
            return ['table' => $table, 'uuid' => $uuid, 'conflict_id' => $pending->id, 'deduped' => true];
        }

        $policy = config('sync.conflict_policies.'.$entity, 'server_wins');
        $financial = in_array($policy, ['append_only', 'manual'], true);

        $conflict = new SyncConflict;
        $conflict->timestamps = true;
        $conflict->forceFill([
            'uuid' => (string) Str::uuid(),
            'company_id' => $local->company_id ?? DeviceIdentity::load()['company_id'],
            'branch_id' => $local->branch_id ?? DeviceIdentity::load()['branch_id'],
            'device_id' => DeviceIdentity::load()['device_id'],
            'entity_type' => $entity,
            'entity_uuid' => $uuid,
            'severity' => $financial ? 'critical' : 'warning',
            'policy' => $policy,
            'reason' => 'Changed on this PC and changed centrally before sync. The pending local change will be pushed first; an administrator reviews the outcome.',
            'local_payload' => $local->attributesToArray(),
            'server_payload' => $server,
            'differing_fields' => $this->diff($local->attributesToArray(), $server),
            'status' => SyncConflict::STATUS_PENDING,
            'detected_at' => now(),
        ]);
        OutboxRecorder::withoutRecording(fn () => $conflict->save());

        return ['table' => $table, 'uuid' => $uuid, 'conflict_id' => $conflict->id];
    }

    private function diff(array $local, array $server): array
    {
        $skip = ['id', 'created_at', 'updated_at', 'deleted_at', 'revision', 'sync_seq', 'origin', 'device_id', 'synced_at'];
        $diff = [];

        foreach ($server as $key => $value) {
            if (in_array($key, $skip, true)) {
                continue;
            }

            $left = $local[$key] ?? null;

            if ((string) $left !== (string) $value) {
                $diff[$key] = ['local' => $left, 'server' => $value];
            }
        }

        return $diff;
    }

    private function orderedTables(array $tables): array
    {
        $order = array_flip(config('offline.pull_order', []));

        return collect($tables)->sortBy(fn ($t) => $order[$t] ?? 999)->values()->all();
    }
}
