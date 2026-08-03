<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class SyncServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $key = config('sync.key_column', 'uuid');
        $rev = config('sync.revision_column', 'revision');

        foreach (array_values(config('sync.tables', [])) as $class) {
            if (! class_exists($class)) {
                continue;
            }

            $class::creating(function (Model $model) use ($key, $rev) {
                if (empty($model->{$key})) {
                    $model->{$key} = (string) Str::uuid();
                }
                if (empty($model->{$rev})) {
                    $model->{$rev} = 1;
                }
            });

            $class::updating(function (Model $model) use ($rev) {
                if (! $model->isDirty($rev)) {
                    $model->{$rev} = (int) $model->{$rev} + 1;
                }
            });
        }
    }
}
