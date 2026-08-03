<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CounterEndOfDay extends Model
{
    use BelongsToBranch, BelongsToCompany, SoftDeletes;

    protected $table = 'counter_end_of_day';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'submitted_at' => 'datetime',
            'opening_float' => 'decimal:2',
            'cash_sales' => 'decimal:2',
            'card_sales' => 'decimal:2',
            'mobile_sales' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'variance' => 'decimal:2',
            'cash_in' => 'decimal:2',
            'cash_out' => 'decimal:2',
            'total_income' => 'decimal:2',
            'total_expense' => 'decimal:2',
            'net_profit' => 'decimal:2',
        ];
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(Counter::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Calculate variance automatically
    protected static function booted(): void
    {
        static::saving(function ($model) {
            $model->variance = $model->expected_cash - $model->counted_cash;
            if (!$model->net_profit) {
                $model->net_profit = $model->total_income - $model->total_expense;
            }
        });
    }
}
