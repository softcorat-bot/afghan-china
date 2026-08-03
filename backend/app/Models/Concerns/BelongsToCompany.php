<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Apply to every tenant-scoped model. Adds a global scope that filters by the
 * active company and auto-fills company_id on create.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function ($model) {
            if (empty($model->company_id) && Tenant::check()) {
                $model->company_id = Tenant::id();
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
