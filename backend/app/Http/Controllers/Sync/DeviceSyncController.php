<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
use App\Models\PosDevice;
use App\Services\Sync\DeviceRegistrar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Device-facing lifecycle endpoints.
 *
 * `register` is the only unauthenticated call: the installation proves it is
 * allowed to exist by presenting the activation code an administrator generated
 * for it. Everything else in the sync API requires the token it gets back.
 */
class DeviceSyncController extends Controller
{
    public function __construct(private readonly DeviceRegistrar $devices)
    {
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'string', 'max:64'],
            'activation_code' => ['nullable', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:120'],
            'platform' => ['nullable', 'string', 'max:32'],
            'os_version' => ['nullable', 'string', 'max:64'],
            'app_version' => ['nullable', 'string', 'max:32'],
        ]);

        try {
            ['device' => $device, 'token' => $token] = $this->devices->register($data);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'device_registration_refused',
            ], 422);
        }

        return response()->json([
            'device' => [
                'device_id' => $device->device_id,
                'name' => $device->name,
                'status' => $device->status,
                'branch_id' => $device->branch_id,
            ],
            // Shown exactly once — the installation stores it in the OS keychain
            // (or, where that is unavailable, in a 0600 file in its data folder).
            'device_token' => $token,
            'company_id' => $device->company_id,
            'branch_id' => $device->branch_id,
            'server_time' => now()->toIso8601String(),
            'limits' => [
                'pull' => (int) config('sync.pull_limit', 500),
                'push' => (int) config('sync.max_push_batch', 500),
            ],
        ], 201);
    }

    /** Rotation: a device may ask for a fresh token using its current one. */
    public function rotate(Request $request): JsonResponse
    {
        /** @var PosDevice $device */
        $device = $request->attributes->get('pos_device');
        $token = $this->devices->issueToken($device);

        return response()->json(['device_token' => $token, 'server_time' => now()->toIso8601String()]);
    }
}
