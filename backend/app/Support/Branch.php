<?php

namespace App\Support;

/**
 * Holds the active branch id for the current request lifecycle. Null means
 * "all branches" (only super admins / admins may see across branches).
 */
class Branch
{
    protected static ?int $branchId = null;

    public static function set(?int $branchId): void
    {
        static::$branchId = $branchId;
    }

    public static function id(): ?int
    {
        return static::$branchId;
    }

    public static function check(): bool
    {
        return static::$branchId !== null;
    }

    public static function clear(): void
    {
        static::$branchId = null;
    }
}
