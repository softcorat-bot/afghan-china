<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The few places this app has to speak SQL directly.
 *
 * Every report groups sales by day, week, month, hour or year, and the function
 * that does that is **not portable**: SQLite has `strftime()`, MySQL has
 * `DATE_FORMAT()`, and PostgreSQL has neither. The app develops on SQLite and
 * deploys to MySQL on shared hosting, so hard-coding `strftime` meant every
 * chart and every report threw a SQL error the moment it went live.
 *
 * One helper, so the difference is stated once instead of fourteen times.
 */
class Sql
{
    /**
     * A portable date-format expression, written in strftime patterns.
     *
     * Only the pattern letters that mean the same thing in every engine are
     * accepted — `%Y %m %d %H`. Week numbers are deliberately *not* handled
     * here, because no two engines agree on them; use {@see weekStart()}.
     *
     * @param  string  $column  a column or expression, e.g. `sales.sold_at`
     * @param  string  $pattern  a strftime pattern, e.g. `%Y-%m`
     */
    public static function dateFormat(string $column, string $pattern): string
    {
        return match (self::driver()) {
            'sqlite' => "strftime('{$pattern}', {$column})",
            'pgsql' => sprintf("to_char(%s, '%s')", $column, self::toPostgres($pattern)),
            default => sprintf("DATE_FORMAT(%s, '%s')", $column, $pattern),
        };
    }

    /**
     * The Monday of `$column`'s week, as `YYYY-MM-DD` — the key weekly reports
     * group by.
     *
     * Weeks are bucketed by their start date rather than by a week *number*
     * because week numbering is where the engines quietly disagree. SQLite's
     * `%W` counts complete weeks since Jan 1, MySQL's `%W` is the weekday
     * *name* (`%v` is its ISO week), and PHP's `format('W')` is the ISO week —
     * so `%Y-W%W` and Carbon's `Y-\WW` produced keys that were off by one for
     * most of the year even on SQLite alone, leaving every weekly bucket
     * reading zero. A date is unambiguous, sorts correctly as a string, and
     * comes out byte-identical on all three engines.
     */
    public static function weekStart(string $column): string
    {
        return match (self::driver()) {
            // '-6 days' then 'weekday 1' lands on this week's Monday: a Monday
            // steps back into last week and forward onto itself, and a Sunday
            // steps back onto its own Monday and stays there.
            'sqlite' => "date({$column}, '-6 days', 'weekday 1')",
            'pgsql' => "to_char(date_trunc('week', {$column}), 'YYYY-MM-DD')",
            // WEEKDAY() is 0 for Monday, so subtracting it rewinds to Monday.
            default => "DATE_FORMAT(DATE_SUB({$column}, INTERVAL WEEKDAY({$column}) DAY), '%Y-%m-%d')",
        };
    }

    /** The connection's driver, so callers do not each reach for the facade. */
    public static function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    /** Translate the handful of strftime patterns this app uses to Postgres. */
    private static function toPostgres(string $pattern): string
    {
        return str_replace(
            ['%Y', '%m', '%d', '%H'],
            ['YYYY', 'MM', 'DD', 'HH24'],
            $pattern
        );
    }
}
