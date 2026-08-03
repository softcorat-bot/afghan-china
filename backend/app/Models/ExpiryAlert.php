<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpiryAlert extends Model
{
    use BelongsToCompany;

    protected $table = 'expiry_alerts';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'acknowledged' => 'boolean',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryExpiryBatch::class, 'batch_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    // Mark as acknowledged
    public function acknowledge(): void
    {
        $this->update([
            'acknowledged' => true,
            'acknowledged_at' => now(),
            'acknowledged_by' => auth()->id(),
        ]);
    }
}
