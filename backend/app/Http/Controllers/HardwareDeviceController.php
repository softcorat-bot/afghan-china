<?php

namespace App\Http\Controllers;

use App\Models\HardwareDevice;
use App\Models\HardwareAttendanceRecord;
use App\Models\Attendance;
use App\Services\Hardware\VendorServiceFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HardwareDeviceController extends Controller
{
    public function index(Request $request)
    {
        $query = HardwareDevice::with('branch')
            ->where('company_id', auth()->user()->company_id);

        if ($request->has('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->has('type')) {
            $query->where('device_type', $request->type);
        }

        return response()->json([
            'devices' => $query->paginate(15),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'device_id' => 'required|unique:hardware_devices,device_id',
            'device_type' => 'required|in:biometric,rfid,qr_code,manual',
            'device_name' => 'required|string',
            'location' => 'required|string',
            'branch_id' => 'nullable|exists:branches,id',
            'ip_address' => 'required|ip',
            'api_key' => 'nullable|string',
            'settings' => 'nullable|array',
            'notes' => 'nullable|string',
        ]);

        $device = HardwareDevice::create([
            'company_id' => auth()->user()->company_id,
            ...$validated,
        ]);

        return response()->json(['device' => $device], 201);
    }

    public function show(HardwareDevice $device)
    {
        $this->authorize('view', $device);

        return response()->json([
            'device' => $device->load('branch', 'attendanceRecords'),
        ]);
    }

    public function update(Request $request, HardwareDevice $device)
    {
        $this->authorize('update', $device);

        $validated = $request->validate([
            'device_name' => 'sometimes|string',
            'location' => 'sometimes|string',
            'branch_id' => 'sometimes|nullable|exists:branches,id',
            'ip_address' => 'sometimes|ip',
            'api_key' => 'sometimes|nullable|string',
            'active' => 'sometimes|boolean',
            'settings' => 'sometimes|array',
            'notes' => 'sometimes|nullable|string',
        ]);

        $device->update($validated);

        return response()->json(['device' => $device]);
    }

    public function destroy(HardwareDevice $device)
    {
        $this->authorize('delete', $device);

        $device->delete();

        return response()->json(['message' => 'Device deleted']);
    }

    public function testConnection(HardwareDevice $device)
    {
        $this->authorize('update', $device);

        try {
            $service = VendorServiceFactory::create($device);
            $isOnline = $service->verifyConnection($device->ip_address);

            if (!$isOnline) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot reach device at this IP address',
                ], 422);
            }

            $info = $service->getDeviceInfo($device->ip_address);

            return response()->json([
                'success' => true,
                'message' => 'Connection successful',
                'device_info' => $info,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
            ], 422);
        }
    }

    public function syncRecords(HardwareDevice $device)
    {
        $this->authorize('update', $device);

        if ($device->sync_in_progress) {
            return response()->json([
                'success' => false,
                'message' => 'Sync already in progress',
            ], 422);
        }

        $device->update(['sync_in_progress' => true]);

        try {
            $service = VendorServiceFactory::create($device);
            $records = $service->getAttendanceRecords(
                $device->ip_address,
                $device->last_sync?->timestamp
            );

            DB::transaction(function () use ($device, $records) {
                foreach ($records as $record) {
                    $attendanceRecord = HardwareAttendanceRecord::firstOrCreate(
                        [
                            'hardware_device_id' => $device->id,
                            'fingerprint_id' => $record['fingerprint_id'],
                            'scanned_at' => $record['scanned_at'],
                        ],
                        [
                            'company_id' => $device->company_id,
                            'user_id' => $record['user_id'],
                            'action' => $record['action'],
                            'rfid_card' => $record['rfid_card'] ?? null,
                        ]
                    );

                    if ($record['user_id'] && !$attendanceRecord->synced_to_attendance) {
                        $this->syncToAttendance($device, $attendanceRecord);
                    }
                }
            });

            $device->update([
                'last_sync' => now(),
                'sync_in_progress' => false,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Sync completed',
                'records_synced' => count($records),
            ]);
        } catch (\Exception $e) {
            $device->update(['sync_in_progress' => false]);

            return response()->json([
                'success' => false,
                'message' => 'Sync failed: ' . $e->getMessage(),
            ], 422);
        }
    }

    public function getUnsyncedRecords(HardwareDevice $device)
    {
        $this->authorize('view', $device);

        $records = $device->unsyncedRecords()
            ->with('user')
            ->orderBy('scanned_at', 'desc')
            ->paginate(20);

        return response()->json(['records' => $records]);
    }

    private function syncToAttendance(HardwareDevice $device, HardwareAttendanceRecord $record): void
    {
        if (!$record->user_id) {
            return;
        }

        Attendance::updateOrCreate(
            [
                'user_id' => $record->user_id,
                'attendance_date' => $record->scanned_at->format('Y-m-d'),
            ],
            [
                'company_id' => $device->company_id,
                'check_in' => $record->action === 'check_in' ? $record->scanned_at : null,
                'check_out' => $record->action === 'check_out' ? $record->scanned_at : null,
                'status' => 'present',
                'source' => 'hardware_device',
            ]
        );

        $record->update(['synced_to_attendance' => true]);
    }
}
