<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One local write waiting for (or already acknowledged by) Central.
 *
 * Deliberately NOT tenant-scoped: an Offline installation belongs to exactly
 * one company/branch (its device registration), and the sync agent must work
 * in CLI with no request context at all.
 */
class OfflineOutbox extends Model
{
    protected $table = 'offline_outbox';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SYNCED = 'synced';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CONFLICT = 'conflict';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'captured_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeUnresolved($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_FAILED, self::STATUS_CONFLICT]);
    }
}
