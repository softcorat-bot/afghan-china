<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpiryTrackedCategory extends Model
{
    use BelongsToCompany;

    protected $table = 'expiry_tracked_categories';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['require_expiry' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    // Predefined expiry-tracked categories
    public static $EXPIRY_CATEGORIES = [
        'Medicine' => ['warning_days' => 60, 'require_expiry' => true],
        'Food' => ['warning_days' => 14, 'require_expiry' => true],
        'Beverages' => ['warning_days' => 30, 'require_expiry' => true],
        'Cosmetics' => ['warning_days' => 30, 'require_expiry' => true],
        'Dairy' => ['warning_days' => 7, 'require_expiry' => true],
        'Frozen Foods' => ['warning_days' => 30, 'require_expiry' => true],
        'Condiments' => ['warning_days' => 30, 'require_expiry' => true],
        'Vitamins' => ['warning_days' => 60, 'require_expiry' => true],
        'Shampoo' => ['warning_days' => 24, 'require_expiry' => true],
        'Lotion' => ['warning_days' => 24, 'require_expiry' => true],
        // Non-expiry categories
        'Electronics' => ['warning_days' => 0, 'require_expiry' => false],
        'Stationery' => ['warning_days' => 0, 'require_expiry' => false],
        'Household' => ['warning_days' => 0, 'require_expiry' => false],
    ];
}
