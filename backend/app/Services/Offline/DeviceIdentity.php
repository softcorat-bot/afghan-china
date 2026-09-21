<?php

namespace App\Services\Offline;

use Illuminate\Support\Str;

/**
 * This installation's device identity.
 *
 * Precedence: environment (OFFLINE_DEVICE_ID / OFFLINE_DEVICE_TOKEN) wins when
 * set — containers and tests — otherwise storage/app/offline/device.json, which
 * `offline:register` writes with 0600 permissions. The installer generates the
 * device id on first boot so no sale is ever rung before the till knows who it is.
 */
class DeviceIdentity
{
    public static function file(): string
    {
        return storage_path('app/offline/device.json');
    }

    /** @return array{device_id:?string, device_token:?string, company_id:?int, branch_id:?int, name:?string} */
    public static function load(): array
    {
        $stored = [];

        if (is_file(static::file())) {
            $decoded = json_decode((string) file_get_contents(static::file()), true);
            $stored = is_array($decoded) ? $decoded : [];
        }

        return [
            'device_id' => config('offline.device_id') ?: ($stored['device_id'] ?? null),
            'device_token' => config('offline.device_token') ?: ($stored['device_token'] ?? null),
            'company_id' => isset($stored['company_id']) ? (int) $stored['company_id'] : null,
            'branch_id' => isset($stored['branch_id']) ? (int) $stored['branch_id'] : null,
            'name' => $stored['name'] ?? null,
        ];
    }

    public static function isRegistered(): bool
    {
        $identity = static::load();

        return ! empty($identity['device_id']) && ! empty($identity['device_token']);
    }

    public static function save(array $data): void
    {
        $dir = dirname(static::file());

        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $merged = array_merge(static::load(), $data);

        file_put_contents(static::file(), json_encode([
            'device_id' => $merged['device_id'] ?? null,
            'device_token' => $merged['device_token'] ?? null,
            'company_id' => $merged['company_id'] ?? null,
            'branch_id' => $merged['branch_id'] ?? null,
            'name' => $merged['name'] ?? null,
            'saved_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT));

        @chmod(static::file(), 0600);
    }

    /** A fresh, human-distinguishable device id for first boot / registration. */
    public static function suggest(?string $branchName = null): string
    {
        $branch = $branchName ? strtoupper(preg_replace('/[^A-Z0-9]+/i', '', $branchName)) : 'KBL';

        return 'AC-'.substr($branch, 0, 12).'-'.strtoupper(Str::random(6));
    }
}
