<?php

namespace App\Services\Hardware;

interface VendorServiceInterface
{
    public function authenticate(string $ipAddress, string $apiKey): bool;

    public function getAttendanceRecords(string $ipAddress, ?int $lastSyncTimestamp = null): array;

    public function getUserFingerprints(string $ipAddress): array;

    public function verifyConnection(string $ipAddress): bool;

    public function getDeviceInfo(string $ipAddress): array;
}
