<?php

namespace App\Http\Middleware;

use App\Services\Sync\DeviceRegistrar;
use App\Support\Branch;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates an offline installation and puts the request in that device's
 * tenant/branch context — the same context the web app's `tenant` + `branch`
 * middleware would set from a user session.
 *
 * A device holds its own credential, never a user's password, and a device that
 * has been disabled or revoked stops working on the very next call.
 */
class DeviceAuth
{
    public function __construct(private readonly DeviceRegistrar $devices)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $deviceId = $request->header('X-Device-Id') ?: $request->input('device_id');

        if (! $token || ! $deviceId) {
            return response()->json([
                'message' => 'This endpoint is for registered offline devices: send the device token and the X-Device-Id header.',
                'code' => 'device_auth_required',
            ], 401);
        }

        $device = $this->devices->authenticate($token, $deviceId);

        if (! $device) {
            return response()->json([
                'message' => 'Unknown device or invalid device token. Re-register the installation in Settings → Devices.',
                'code' => 'device_token_invalid',
            ], 401);
        }

        if (! $device->isActive()) {
            return response()->json([
                'message' => $device->blockedReason(),
                'code' => 'device_'.$device->status,
                'status' => $device->status,
            ], 403);
        }

        // Everything downstream (models, scopes, the sync engine) now behaves as
        // if the request had come from a signed-in user of that company/branch.
        Tenant::set($device->company_id);
        Branch::set($device->branch_id);

        $request->attributes->set('pos_device', $device);
        $request->setUserResolver(fn () => null);

        $device->forceFill(['token_last_used_at' => now(), 'last_seen_at' => now()])->saveQuietly();

        return $next($request);
    }
}
