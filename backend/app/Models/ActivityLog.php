<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    protected $appends = ['created_at_human'];

    public function getCreatedAtHumanAttribute(): string
    {
        return $this->created_at?->diffForHumans() ?? '';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function log(string $action, string $module, string $description): void
    {
        try {
            static::create([
                'company_id'  => \App\Support\Tenant::id(),
                'user_id'     => auth()->id(),
                'action'      => $action,
                'module'      => $module,
                'description' => $description,
            ]);
        } catch (\Throwable) {
            // Never let logging break the main request
        }
    }
}
