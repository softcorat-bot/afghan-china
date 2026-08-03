<?php

namespace App\Support;

use App\Models\User;

/**
 * THE single cost resolver. Every financial calculation in the system asks
 * this class which cost basis applies to the current viewer:
 *
 *   Effective Cost = main_price ?? cost_price   (owner / finance view)
 *   Effective Cost = cost_price                 (everyone else)
 *
 * `main_price` is the owner's confidential real cost. `cost_price` is the
 * operational cost employees work with. Products stay a single table — this
 * is a perspective, never a duplicate.
 */
class EffectiveCost
{
    /** May this user see the confidential Main Cost perspective? */
    public static function canView(?User $user): bool
    {
        return (bool) ($user && ($user->isPlatformOwner() || $user->can('main-cost')));
    }

    /** May this user change Main Cost values? */
    public static function canEdit(?User $user): bool
    {
        return (bool) ($user && ($user->isPlatformOwner() || $user->can('main-cost-edit')));
    }

    /** SQL cost expression against the products table. */
    public static function productExpr(?User $user, string $table = 'products'): string
    {
        return self::canView($user)
            ? "COALESCE({$table}.main_price, {$table}.cost_price)"
            : "{$table}.cost_price";
    }

    /**
     * SQL COGS expression for sale lines: staff use the sale-time snapshot;
     * the owner sees today's real cost of what was sold (requires a join on
     * products as `products`).
     */
    public static function saleItemExpr(?User $user): string
    {
        return self::canView($user)
            ? 'COALESCE(products.main_price, sale_items.cost_price)'
            : 'sale_items.cost_price';
    }

    /** PHP-side resolver for a single pair of values. */
    public static function resolve(?User $user, $mainPrice, $operationalCost): float
    {
        if (self::canView($user) && $mainPrice !== null) {
            return (float) $mainPrice;
        }

        return (float) $operationalCost;
    }
}
