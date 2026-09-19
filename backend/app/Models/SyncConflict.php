<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A detected conflict between what a device believes and what the server holds.
 *
 * Nothing is resolved by writing over data silently: with `last write wins` a
 * price set centrally can be rolled back by a till that was offline for a month,
 * which is the failure mode this project must not have. Financial conflicts
 * (severity `critical`) can only be resolved by a user holding
 * `resolve-financial-sync-conflicts`.
 */
class SyncConflict extends Model
{
    use BelongsToCompany;

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED_SERVER = 'accepted_server';
    public const STATUS_KEPT_LOCAL = 'kept_local';
    public const STATUS_MERGED = 'merged';
    public const STATUS_DISMISSED = 'dismissed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'local_payload' => 'array',
            'server_payload' => 'array',
            'differing_fields' => 'array',
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $conflict) {
            $conflict->uuid ??= (string) Str::uuid();
            $conflict->detected_at ??= now();
        });
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(PosDevice::class, 'device_id', 'device_id');
    }

    public function isFinancial(): bool
    {
        return $this->severity === 'critical';
    }
}
