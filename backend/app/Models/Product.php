<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use BelongsToCompany, SoftDeletes, HasAttachments;

    protected $guarded = ['id'];

    /**
     * The owner's private real buy price. Never serialized on normal
     * endpoints — only the gated Main Cost controller makes it visible.
     */
    protected $hidden = ['main_price'];

    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2',
            'main_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'wholesale_price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'stock_qty' => 'decimal:3',
            'warehouse_qty' => 'decimal:3',
            'min_stock' => 'decimal:3',
            'track_inventory' => 'boolean',
            'active' => 'boolean',
            'track_expiry' => 'boolean',
            'expiry_date' => 'date',
            'manufacture_date' => 'date',
            'shelf_life_days' => 'integer',
            'tags' => 'array',
        ];
    }

    protected $appends = ['low_stock', 'margin', 'image_url'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function expiryBatches(): HasMany
    {
        return $this->hasMany(InventoryExpiryBatch::class);
    }

    // Check if this product requires expiry tracking
    public function requiresExpiryTracking(): bool
    {
        return ExpiryTrackedCategory::where('category_id', $this->category_id)
            ->where('require_expiry', true)
            ->exists();
    }

    // Get active batches (not expired, not discarded)
    public function getActiveBatches()
    {
        return $this->expiryBatches()
            ->where('status', 'active')
            ->orderBy('expiry_date', 'asc')
            ->get();
    }

    // Get expired batches
    public function getExpiredBatches()
    {
        return $this->expiryBatches()
            ->where('status', 'expired')
            ->get();
    }

    // Total available quantity across all active batches (FIFO)
    public function getTotalAvailableExpiry(): float
    {
        return $this->expiryBatches()
            ->where('status', 'active')
            ->sum('quantity_available');
    }

    public function getLowStockAttribute(): bool
    {
        return $this->track_inventory && $this->stock_qty <= $this->min_stock;
    }

    public function getMarginAttribute(): float
    {
        if ($this->sale_price <= 0) {
            return 0;
        }

        return round((($this->sale_price - $this->cost_price) / $this->sale_price) * 100, 1);
    }

    /**
     * Photos ride the API (`/api/catalog-photo/…`), not the `/storage` static
     * path — the same approach as attachments. A static path only works when
     * the storage symlink and the web server's document root are both exactly
     * right, and on the machines where they were not, every product photo
     * 404ed while the API itself worked fine. A route has no such dependency.
     */
    public function getImageUrlAttribute(): ?string
    {
        return $this->image ? '/api/catalog-photo/'.$this->image : null;
    }
}
