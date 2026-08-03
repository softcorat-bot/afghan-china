<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use BelongsToBranch, BelongsToCompany, SoftDeletes;

    protected $guarded = ['id'];

    /** The running costs a shop actually books. */
    public const CATEGORIES = [
        'rent', 'electricity', 'water', 'internet', 'transport',
        'wages', 'repairs', 'cleaning', 'marketing', 'government', 'other',
    ];

    protected function casts(): array
    {
        return [
            'spent_on' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
