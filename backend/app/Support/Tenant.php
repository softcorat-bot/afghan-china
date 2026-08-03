<?php

namespace App\Support;

/**
 * Holds the active company id for the current request lifecycle.
 */
class Tenant
{
    protected static ?int $companyId = null;

    public static function set(?int $companyId): void
    {
        static::$companyId = $companyId;
    }

    public static function id(): ?int
    {
        return static::$companyId;
    }

    public static function check(): bool
    {
        return static::$companyId !== null;
    }

    public static function clear(): void
    {
        static::$companyId = null;
    }

    public static function company(): ?\App\Models\Company
    {
        $id = static::$companyId;
        return $id ? \App\Models\Company::find($id) : null;
    }
}
