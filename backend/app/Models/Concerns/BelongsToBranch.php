<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Models\Scopes\BranchScope;
use App\Support\Branch as BranchContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Apply to models that live inside a single branch (projects, assets, …).
 * Adds a global scope that filters by the active branch and auto-fills
 * branch_id on create from the active branch when one is set.
 */
trait BelongsToBranch
{
    public static function bootBelongsToBranch(): void
    {
        static::addGlobalScope(new BranchScope);

        static::creating(function ($model) {
            if (empty($model->branch_id) && BranchContext::check()) {
                $model->branch_id = BranchContext::id();
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
