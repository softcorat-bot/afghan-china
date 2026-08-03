<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryExpiryBatch extends Model
{
    use BelongsToBranch, BelongsToCompany, SoftDeletes;

    protected $table = 'inventory_expiry_batches';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'manufacture_date' => 'date',
            'expiry_date' => 'date',
            'expired_at' => 'datetime',
            'quantity_received' => 'decimal:2',
            'quantity_available' => 'decimal:2',
            'quantity_sold' => 'decimal:2',
            'quantity_discarded' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'total_cost' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(ExpiryAlert::class, 'batch_id');
    }

    // Days until expiry
    public function getDaysUntilExpiryAttribute(): int
    {
        return $this->expiry_date->diffInDays(today(), false); // Negative if expired
    }

    // Is this batch expired?
    public function getIsExpiredAttribute(): bool
    {
        return $this->expiry_date < today();
    }

    // Is expiry coming soon?
    public function getIsExpiringAttribute(): bool
    {
        $warningDays = ExpiryTrackedCategory::where('category_id', $this->product->category_id)
            ->value('warning_days') ?? 30;

        return $this->getDaysUntilExpiryAttribute() <= $warningDays && $this->getDaysUntilExpiryAttribute() > 0;
    }

    // Auto-create expiry alerts when batch is created or updated
    protected static function booted(): void
    {
        static::creating(function ($model) {
            if ($model->expiry_date < today()) {
                $model->status = 'expired';
                $model->expired_at = now();
            }
        });

        static::created(function ($model) {
            static::createAlerts($model);
        });

        static::updated(function ($model) {
            if ($model->status === 'expired' && !$model->expired_at) {
                $model->update(['expired_at' => now()]);
            }

            static::createAlerts($model);
        });
    }

    private static function createAlerts($model): void
    {
        if (!$model->expiry_date) return;

        $daysUntilExpiry = $model->expiry_date->diffInDays(today(), false);

        if ($daysUntilExpiry < 0) {
            // Expired
            ExpiryAlert::updateOrCreate(
                ['batch_id' => $model->id, 'alert_type' => 'expired'],
                ['days_until_expiry' => $daysUntilExpiry, 'company_id' => $model->company_id]
            );
        } else {
            // Expiring soon
            $warningDays = ExpiryTrackedCategory::where('category_id', $model->product->category_id)
                ->value('warning_days') ?? 30;

            if ($daysUntilExpiry <= $warningDays) {
                ExpiryAlert::updateOrCreate(
                    ['batch_id' => $model->id, 'alert_type' => 'expiring_soon'],
                    ['days_until_expiry' => $daysUntilExpiry, 'company_id' => $model->company_id]
                );
            }
        }
    }

    // Mark as discarded (expired stock write-off)
    public function discard(int $quantity): void
    {
        $this->quantity_available -= $quantity;
        $this->quantity_discarded += $quantity;
        $this->status = $this->quantity_available <= 0 ? 'discarded' : 'active';
        $this->save();
    }

    // Sell from this batch (FIFO principle)
    public function sell(int $quantity): void
    {
        $this->quantity_available -= $quantity;
        $this->quantity_sold += $quantity;
        $this->save();
    }
}
