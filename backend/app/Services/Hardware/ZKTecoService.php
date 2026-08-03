<?php

namespace App\Services\Hardware;

use Illuminate\Support\Facades\Http;

class ZKTecoService implements VendorServiceInterface
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct(string $ipAddress, string $apiKey = 'zkserver')
    {
        $this->baseUrl = "http://{$ipAddress}:8080/api";
        $this->apiKey = $apiKey;
    }

    public function authenticate(string $ipAddress, string $apiKey): bool
    {
        try {
            $response = Http::timeout(5)->post(
                "http://{$ipAddress}:8080/api/login",
                ['username' => 'admin', 'password' => $apiKey]
            );

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getAttendanceRecords(string $ipAddress, ?int $lastSyncTimestamp = null): array
    {
        try {
            $params = ['pageSize' => 1000];
            if ($lastSyncTimestamp) {
                $params['startTime'] = $lastSyncTimestamp;
            }

            $response = Http::timeout(10)->get(
                "http://{$ipAddress}:8080/api/attendance/records",
                $params
            );

            if (!$response->successful()) {
                return [];
            }

            $records = [];
            foreach ($response->json('data', []) as $record) {
                $records[] = [
                    'user_id' => $record['employeeId'] ?? null,
                    'fingerprint_id' => $record['fingerID'] ?? null,
                    'scanned_at' => $record['checkTime'] ?? now(),
                    'action' => $record['checkType'] == 0 ? 'check_in' : 'check_out',
                    'device_id' => $record['deviceSN'] ?? null,
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
                $fingerprints[$fp['fingerID']] = [
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
                "http://{$ipAddress}:8080/api/device/info"
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
                "http://{$ipAddress}:8080/api/device/info"
            );

            if (!$response->successful()) {
                return [];
            }

            $data = $response->json();

            return [
                'device_id' => $data['deviceSN'] ?? null,
                'device_name' => $data['deviceName'] ?? 'ZKTeco Device',
                'model' => 'ZKTeco MB460',
                'firmware' => $data['fwVersion'] ?? null,
                'user_count' => $data['userCount'] ?? 0,
                'online' => true,
            ];
        } catch (\Exception $e) {
            return [];
        }
    }
}
