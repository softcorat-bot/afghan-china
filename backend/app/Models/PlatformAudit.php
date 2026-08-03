<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/** Immutable audit trail of platform-level (Platform Owner) actions. */
class PlatformAudit extends Model
{
    protected $table = 'platform_audit_logs';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];
    }

    /**
     * Record a platform action with actor identity, affected resource and
     * before/after snapshots. Never throws — auditing must not block the action.
     */
    public static function record(string $action, ?Model $resource = null, ?array $before = null, ?array $after = null): void
    {
        try {
            $user = Auth::user();
            self::create([
                'actor_id' => $user?->id,
                'actor_email' => $user?->email,
                'action' => $action,
                'resource_type' => $resource ? class_basename($resource) : null,
                'resource_id' => $resource?->getKey(),
                'before' => $before,
                'after' => $after,
                'ip' => Request::ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // swallow — auditing failure must not break the platform operation
        }
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
