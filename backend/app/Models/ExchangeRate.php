<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeRate extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rate_to_base' => 'decimal:4',
            // date:Y-m-d so it stores/serialises as a pure date — otherwise the
            // 00:00:00 suffix breaks firstOrCreate idempotency against the
            // (company, currency, date) unique index on re-seed.
            'rate_date' => 'date:Y-m-d',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
