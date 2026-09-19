<?php

namespace App\Providers;

use App\Services\Sync\SyncSequence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Stamps identity and change-tracking on every synchronizable row.
 *
 * A row created by the web POS and a row created by an offline till end up with
 * the same three things: a globally unique `uuid`, a `revision` that rises on
 * every change, and a `sync_seq` placing it in the one ordered stream devices
 * pull from. On PostgreSQL the database repeats this work in a trigger, so even
 * a write that never passes through PHP enters the stream.
 */
class SyncServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $key = config('sync.key_column', 'uuid');
        $rev = config('sync.revision_column', 'revision');
        $seq = config('sync.seq_column', 'sync_seq');

        foreach (array_values(config('sync.tables', [])) as $class) {
            if (! class_exists($class)) {
                continue;
            }

            $class::creating(function (Model $model) use ($key, $rev, $seq) {
                if (empty($model->{$key})) {
                    $model->{$key} = (string) Str::uuid();
                }
                if (empty($model->{$rev})) {
                    $model->{$rev} = 1;
                }
                if ($this->hasColumn($model, $seq) && empty($model->{$seq})) {
                    $model->{$seq} = app(SyncSequence::class)->next();
                }
                if ($this->hasColumn($model, 'origin') && empty($model->origin)) {
                    $model->origin = 'web';
                }
            });

            $class::updating(function (Model $model) use ($rev, $seq) {
                if (! $model->isDirty($rev)) {
                    $model->{$rev} = (int) $model->{$rev} + 1;
                }
                if ($this->hasColumn($model, $seq)) {
                    $model->{$seq} = app(SyncSequence::class)->next();
                }
            });
        }
    }

    /** Column presence is cached per request (App\Support\Schema) — cheap and safe. */
    private function hasColumn(Model $model, string $column): bool
    {
        try {
            return \App\Support\Schema::has($model->getTable(), $column);
        } catch (\Throwable) {
            return false;
        }
    }
}
