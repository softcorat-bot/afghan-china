<?php

namespace App\Services\Offline;

use App\Models\OfflineOutbox;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Captures local writes into offline_outbox (Offline Mode only).
 *
 * Three coalescing rules keep the queue small and ordered without losing truth:
 *  1. created → one `create` row (full snapshot payload).
 *  2. updated → if a pending `create` exists, its payload is rebuilt (the create
 *     already carries full state); else the pending `update` is rebuilt, else a
 *     new `update` row. Updates are only captured for updatable entities —
 *     financial documents are append-only.
 *  3. deleted → if a pending `create` exists it is removed (the row never left
 *     this device: net effect zero); else a `delete` tombstone row.
 *
 * Recording is suspended while the pull applier writes, so applying Central's
 * rows never queues them back up again.
 */
class OutboxRecorder
{
    public static bool $suspended = false;

    public static function withoutRecording(callable $fn): mixed
    {
        $previous = static::$suspended;
        static::$suspended = true;

        try {
            return $fn();
        } finally {
            static::$suspended = $previous;
        }
    }

    public static function record(Model $model, string $operation): void
    {
        if (static::$suspended || ! config('offline.enabled')) {
            return;
        }

        $entity = config('offline.observed.'.$model::class);

        if (! $entity || ! static::tableReady()) {
            return;
        }

        if ($operation === 'update' && ! in_array($entity, config('offline.updatable_entities', []), true)) {
            return;
        }

        if ($operation === 'delete' && ! in_array($entity, config('offline.deletable_entities', []), true)) {
            return;
        }

        $uuid = (string) ($model->uuid ?? '');

        if ($uuid === '') {
            return;
        }

        $pending = fn () => OfflineOutbox::query()
            ->where('entity_uuid', $uuid)
            ->where('entity_type', $entity)
            ->where('status', OfflineOutbox::STATUS_PENDING);

        // A row created and deleted before ever syncing never existed centrally.
        if ($operation === 'delete' && (clone $pending())->where('operation', 'create')->exists()) {
            (clone $pending())->where('operation', 'create')->delete();

            return;
        }

        $builder = app(ChangeBuilder::class);

        if ($operation === 'update') {
            $create = (clone $pending())->where('operation', 'create')->first();

            if ($create) {
                $change = $builder->build($model, $entity, 'create');
                $create->forceFill(['payload' => $change['payload']])->save();

                return;
            }

            $existing = (clone $pending())->where('operation', 'update')->first();

            if ($existing) {
                $change = $builder->build($model, $entity, 'update');
                $existing->forceFill([
                    'payload' => $change['payload'],
                    'base_revision' => $change['base_revision'],
                ])->save();

                return;
            }
        }

        $change = $builder->build($model, $entity, $operation);

        OfflineOutbox::query()->create([
            'change_uuid' => $change['change_uuid'],
            'entity_type' => $entity,
            'entity_uuid' => $uuid,
            'operation' => $operation,
            'payload' => $change['payload'],
            'base_revision' => $change['base_revision'],
            'status' => OfflineOutbox::STATUS_PENDING,
            'captured_at' => $change['captured_at'],
        ]);
    }

    /** The outbox table may not exist yet (fresh install mid-migration). */
    private static function tableReady(): bool
    {
        try {
            return Schema::hasTable('offline_outbox');
        } catch (\Throwable $e) {
            return false;
        }
    }
}
