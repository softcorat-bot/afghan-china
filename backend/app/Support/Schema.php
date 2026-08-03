<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema as DbSchema;

/**
 * Schema tolerance. A machine that pulls new code but forgets
 * `php artisan migrate` used to 500 on writes that mention a brand-new
 * column. These helpers let a write degrade gracefully instead: the row
 * saves without the unknown field, and `acsc:doctor` reports the drift.
 * Column lists are cached per request.
 */
class Schema
{
    private static array $columns = [];

    public static function has(string $table, string $column): bool
    {
        if (! isset(self::$columns[$table])) {
            try {
                self::$columns[$table] = DbSchema::getColumnListing($table);
            } catch (\Throwable $e) {
                self::$columns[$table] = [];
            }
        }

        return in_array($column, self::$columns[$table], true);
    }

    /** Drop keys the table doesn't have (keeps everything when in doubt). */
    public static function only(string $table, array $attributes): array
    {
        if (! isset(self::$columns[$table])) {
            self::has($table, '__probe__');
        }
        if (empty(self::$columns[$table])) {
            return $attributes;
        }

        return array_filter(
            $attributes,
            fn ($key) => in_array($key, self::$columns[$table], true),
            ARRAY_FILTER_USE_KEY
        );
    }
}
