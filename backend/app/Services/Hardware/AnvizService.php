<?php

namespace App\Services\Hardware;

use Illuminate\Support\Facades\Http;

class AnvizService implements VendorServiceInterface
{
    private string $baseUrl;

    public function __construct(string $ipAddress, string $apiKey = '')
    {
        $this->baseUrl = "http://{$ipAddress}:8080/api";
    }

    public function authenticate(string $ipAddress, string $apiKey): bool
    {
        try {
            $response = Http::timeout(5)->post(
                "http://{$ipAddress}:8080/api/login",
                ['token' => $apiKey]
            );

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getAttendanceRecords(string $ipAddress, ?int $lastSyncTimestamp = null): array
    {
        try {
            $params = ['limit' => 1000, 'offset' => 0];
            if ($lastSyncTimestamp) {
                $params['fromTime'] = $lastSyncTimestamp;
            }

            $response = Http::timeout(10)->get(
                "http://{$ipAddress}:8080/api/attendance",
                $params
            );

            if (!$response->successful()) {
                return [];
            }

            $records = [];
            foreach ($response->json('data', []) as $record) {
                $records[] = [
                    'user_id' => $record['employeeId'] ?? null,
                    'fingerprint_id' => $record['fpId'] ?? null,
                    'scanned_at' => $record['checkTime'] ?? now(),
                    'action' => $record['checkType'] == 0 ? 'check_in' : 'check_out',
                    'device_id' => $record['deviceId'] ?? null,
                ];
            }

            return $records;
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getUserFingerprints(string $ipAddress): array
    {
        try {
            $response = Http::timeout(10)->get(
                "http://{$ipAddress}:8080/api/users/fingerprints"
            );

            if (!$response->successful()) {
                return [];
            }

            $fingerprints = [];
            foreach ($response->json('data', []) as $fp) {
                $fingerprints[$fp['fpId']] = [
                    'user_id' => $fp['employeeId'] ?? null,
                    'name' => $fp['employeeName'] ?? 'Unknown',
                ];
            }

            return $fingerprints;
        } catch (\Exception $e) {
            return [];
        }
    }

    public function verifyConnection(string $ipAddress): bool
    {
        try {
            $response = Http::timeout(5)->get(
                "http://{$ipAddress}:8080/api/device/status"
            );

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getDeviceInfo(string $ipAddress): array
    {
        try {
            $response = Http::timeout(5)->get(
                "http://{$ipAddress}:8080/api/device/status"
            );

            if (!$response->successful()) {
                return [];
            }

            $data = $response->json('data');

            return [
                'device_id' => $data['deviceId'] ?? null,
                'device_name' => $data['deviceName'] ?? 'Anviz Device',
                'model' => 'Anviz EF500',
                'firmware' => $data['fwVersion'] ?? null,
                'user_count' => $data['userCount'] ?? 0,
                'online' => true,
            ];
        } catch (\Exception $e) {
            return [];
        }
    }
}
