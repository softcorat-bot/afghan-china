<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $guarded = ['id'];

    /** Business name for wholesale accounts, person name otherwise. */
    public function getDisplayNameAttribute(): string
    {
        return $this->company_name ?: $this->name;
    }

    protected function casts(): array
    {
        return [
            'total_spent' => 'decimal:2',
            'active' => 'boolean',
        ];
    }
}
