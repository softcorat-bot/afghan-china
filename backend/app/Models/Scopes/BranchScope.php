<?php

namespace App\Models\Scopes;

use App\Support\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope that constrains every query to the active branch. When no branch
 * is active (Branch::id() === null) the query is unrestricted — that is the
 * "all branches" view available to super admins.
 */
class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (Branch::check()) {
            $builder->where($model->getTable().'.branch_id', Branch::id());
        }
    }
}
