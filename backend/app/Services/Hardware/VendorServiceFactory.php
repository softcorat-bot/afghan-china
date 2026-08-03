<?php

namespace App\Services\Hardware;

use App\Models\HardwareDevice;
use InvalidArgumentException;

class VendorServiceFactory
{
    public static function create(HardwareDevice $device): VendorServiceInterface
    {
        return self::createForVendor(
            self::getVendorName($device->settings['vendor'] ?? 'unknown'),
            $device->ip_address,
            $device->api_key
        );
    }

    public static function createForVendor(string $vendor, string $ipAddress, ?string $apiKey = null): VendorServiceInterface
    {
        return match (strtolower($vendor)) {
            'zkteco' => new ZKTecoService($ipAddress, $apiKey ?? 'zkserver'),
            'suprema' => new SupremaService($ipAddress, $apiKey ?? ''),
            'anviz' => new AnvizService($ipAddress, $apiKey ?? ''),
            default => throw new InvalidArgumentException("Unsupported vendor: {$vendor}"),
        };
    }

    private static function getVendorName(string $vendor): string
    {
        return match (strtolower($vendor)) {
            'zkteco', 'zk' => 'ZKTeco',
            'suprema' => 'Suprema',
            'anviz' => 'Anviz',
            default => throw new InvalidArgumentException("Unknown vendor: {$vendor}"),
        };
    }

    public static function getSupportedVendors(): array
    {
        return ['ZKTeco', 'Suprema', 'Anviz'];
    }
}
