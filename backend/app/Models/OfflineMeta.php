<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The Offline agent's key/value store. Keys in use:
 *
 *  sync.cursor        last fully-applied Central sync_seq (pull checkpoint)
 *  sync.seeded_at     when the baseline snapshot finished (null = never)
 *  sync.last_run_at   last SyncRunner execution (any outcome)
 *  sync.last_ok_at    last fully-successful sync (no failures, no conflicts)
 *  sync.last_summary  json summary of the last run
 *  sync.last_error    short human description of the last failure
 *  device.company_id  company id from registration
 *  device.branch_id   branch id from registration
 */
class OfflineMeta extends Model
{
    protected $table = 'offline_meta';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = true;

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::query()->where('key', $key)->first();

        return $row ? $row->value : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], [
            'value' => is_scalar($value) || $value === null ? (string) $value : json_encode($value),
        ]);
    }
}
