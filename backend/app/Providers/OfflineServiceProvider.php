<?php

namespace App\Providers;

use App\Services\Offline\DeviceIdentity;
use App\Services\Offline\OutboxRecorder;
use App\Support\Schema as ColumnGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

/**
 * Offline Mode wiring. When OFFLINE_MODE is false this provider does nothing
 * at all — the Online application cannot tell it exists.
 *
 * When enabled it:
 *  - stamps origin/device_id on locally-created rows (alongside the uuid /
 *    revision / sync_seq that SyncServiceProvider already stamps everywhere);
 *  - observes the configured models and captures writes into offline_outbox.
 */
class OfflineServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! config('offline.enabled')) {
            return;
        }

        foreach (config('offline.observed', []) as $class => $entity) {
            if (! class_exists($class)) {
                continue;
            }

            $class::creating(function (Model $model) {
                $table = $model->getTable();

                // Unconditional: this listener runs AFTER SyncServiceProvider's
                // (provider order), which stamps origin='web' — on an offline
                // box the truth is 'offline'. Pull-applied rows bypass events
                // entirely, so Central's own origin values are never clobbered.
                if (ColumnGuard::has($table, 'origin')) {
                    $model->origin = 'offline';
                }

                if (ColumnGuard::has($table, 'device_id') && empty($model->device_id)) {
                    $model->device_id = DeviceIdentity::load()['device_id'];
                }
            });

            $class::created(fn (Model $model) => OutboxRecorder::record($model, 'create'));
            $class::updated(fn (Model $model) => OutboxRecorder::record($model, 'update'));
            $class::deleted(fn (Model $model) => OutboxRecorder::record($model, 'delete'));
        }
    }
}
