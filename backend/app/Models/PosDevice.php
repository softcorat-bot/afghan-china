<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * An offline POS installation.
 *
 * Two secrets matter and neither is ever stored in clear text:
 *  - the activation code, handed to the installer by an administrator, and
 *  - the device api token, issued once at registration (and on rotation).
 *
 * Disabling or revoking a device takes effect on the very next sync call, so a
 * stolen till can be cut off centrally.
 */
class PosDevice extends Model
{
    use BelongsToCompany, SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_REVOKED = 'revoked';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'last_pull_at' => 'datetime',
            'registered_at' => 'datetime',
            'disabled_at' => 'datetime',
            'revoked_at' => 'datetime',
            'activation_expires_at' => 'datetime',
            'token_issued_at' => 'datetime',
            'token_rotated_at' => 'datetime',
            'token_last_used_at' => 'datetime',
            'last_error' => 'array',
            'last_ip' => 'string',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $device) {
            $device->uuid ??= (string) Str::uuid();
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function blockedReason(): ?string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => null,
            self::STATUS_PENDING => 'The device has not been activated yet.',
            self::STATUS_DISABLED => 'The device has been disabled by an administrator.',
            self::STATUS_REVOKED => $this->revoked_reason ?: 'The device has been revoked.',
            default => 'Unknown device status.',
        };
    }

    public function maskedToken(): ?string
    {
        return $this->api_token_hash ? substr($this->api_token_hash, 0, 12).'…' : null;
    }
}
