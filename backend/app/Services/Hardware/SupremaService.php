<?php

namespace App\Services\Hardware;

use Illuminate\Support\Facades\Http;

class SupremaService implements VendorServiceInterface
{
    private string $baseUrl;

    public function __construct(string $ipAddress, string $apiKey = '')
    {
        $this->baseUrl = "http://{$ipAddress}:4000/api/v1";
    }

    public function authenticate(string $ipAddress, string $apiKey): bool
    {
        try {
            $response = Http::timeout(5)->post(
                "http://{$ipAddress}:4000/api/v1/session",
                ['apiKey' => $apiKey]
            );

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getAttendanceRecords(string $ipAddress, ?int $lastSyncTimestamp = null): array
    {
        try {
            $params = ['size' => 1000];
            if ($lastSyncTimestamp) {
                $params['from'] = $lastSyncTimestamp;
            }

            $response = Http::timeout(10)->get(
                "http://{$ipAddress}:4000/api/v1/event/log",
                $params
            );

            if (!$response->successful()) {
                return [];
            }

            $records = [];
            foreach ($response->json('data.events', []) as $event) {
                if ($event['eventType'] != 'ATTENDANCE') {
                    continue;
                }

                $records[] = [
                    'user_id' => $event['userID'] ?? null,
                    'fingerprint_id' => $event['fingerID'] ?? null,
                    'scanned_at' => $event['timestamp'] ?? now(),
                    'action' => 'unknown',
                    'device_id' => $event['deviceID'] ?? null,
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
                "http://{$ipAddress}:4000/api/v1/user/biometrics"
            );

            if (!$response->successful()) {
                return [];
            }

            $fingerprints = [];
            foreach ($response->json('data.biometrics', []) as $bio) {
                $fingerprints[$bio['bioID']] = [
                    'user_id' => $bio['userID'] ?? null,
                    'name' => $bio['userName'] ?? 'Unknown',
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
                "http://{$ipAddress}:4000/api/v1/device/info"
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
                "http://{$ipAddress}:4000/api/v1/device/info"
            );

            if (!$response->successful()) {
                return [];
            }

            $data = $response->json('data');

            return [
                'device_id' => $data['deviceID'] ?? null,
                'device_name' => $data['deviceName'] ?? 'Suprema Device',
                'model' => 'Suprema RealScan',
                'firmware' => $data['firmwareVersion'] ?? null,
                'user_count' => $data['enrolledUsers'] ?? 0,
                'online' => true,
            ];
        } catch (\Exception $e) {
            return [];
        }
    }
}
