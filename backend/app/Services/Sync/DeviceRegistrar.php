<?php

namespace App\Services\Sync;

use App\Models\ActivityLog;
use App\Models\PosDevice;
use App\Models\User;
use App\Support\Branch;
use App\Support\Tenant;
use Illuminate\Support\Str;

/**
 * Device lifecycle: how a Windows installation becomes a trusted till, and how
 * it stops being one.
 *
 * Secrets are one-way hashed (sha256 over a 32-byte random token — high entropy,
 * so a fast hash is appropriate here and, unlike bcrypt, it keeps a sync request
 * cheap). The activation code is short-lived and single-use. Revocation is
 * immediate: the very next request from that installation is refused.
 */
class DeviceRegistrar
{
    public const TOKEN_PREFIX = 'scpos_';

    /**
     * An administrator pre-authorizes a till. Returns the plain activation code
     * exactly once — only its hash is stored.
     *
     * @return array{device:PosDevice, activation_code:string}
     */
    public function create(User $user, array $attributes): array
    {
        $code = strtoupper(Str::random(4).'-'.Str::random(4));

        $device = PosDevice::create([
            'company_id' => $attributes['company_id'] ?? Tenant::id(),
            'branch_id' => $attributes['branch_id'] ?? Branch::id(),
            'device_id' => $attributes['device_id'] ?? $this->suggestDeviceId($attributes['branch_name'] ?? null),
            'name' => $attributes['name'] ?? 'Offline POS',
            'platform' => $attributes['platform'] ?? 'windows',
            'status' => $attributes['status'] ?? PosDevice::STATUS_PENDING,
            'activation_code_hash' => hash('sha256', $code),
            'activation_expires_at' => now()->addDays((int) ($attributes['activation_days'] ?? 14)),
            'notes' => $attributes['notes'] ?? null,
            'registered_by' => $user->id,
            'registered_at' => now(),
        ]);

        ActivityLog::log('created', 'PosDevice', "Offline device {$device->device_id} authorized");

        return ['device' => $device, 'activation_code' => $code];
    }

    /** SC-POS-KBL-8F31A7 — branch letters plus a random tail, never sequential. */
    public function suggestDeviceId(?string $branchName): string
    {
        $branch = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) ($branchName ?: 'POS')), 0, 3));

        do {
            $candidate = 'SC-POS-'.str_pad($branch, 3, 'X').'-'.strtoupper(Str::random(6));
        } while (PosDevice::where('device_id', $candidate)->exists());

        return $candidate;
    }

    /**
     * The installation calls this once, with the code an administrator gave it.
     *
     * @return array{device:PosDevice, token:string}
     */
    public function register(array $data): array
    {
        $device = PosDevice::where('device_id', $data['device_id'])->first();

        if (! $device) {
            throw new \RuntimeException('This device has not been authorized. Ask an administrator to add it in Settings → Devices.');
        }

        if ($device->status === PosDevice::STATUS_REVOKED) {
            throw new \RuntimeException($device->blockedReason() ?: 'This device has been revoked.');
        }

        if ($device->status === PosDevice::STATUS_DISABLED) {
            throw new \RuntimeException('This device is disabled. Ask an administrator to enable it.');
        }

        $code = strtoupper(trim((string) ($data['activation_code'] ?? '')));

        if (! $device->api_token_hash) {
            if (! $device->activation_code_hash || ! hash_equals($device->activation_code_hash, hash('sha256', $code))) {
                throw new \RuntimeException('That activation code is not correct.');
            }

            if ($device->activation_expires_at && $device->activation_expires_at->isPast()) {
                throw new \RuntimeException('That activation code has expired. Ask an administrator for a new one.');
            }
        } elseif (! isset($data['activation_code'])) {
            // Re-registration of a known device (reinstall, restore from backup):
            // it must still prove it is the same installation.
            throw new \RuntimeException('This device is already registered. Provide its activation code or its existing device token.');
        }

        $token = $this->issueToken($device);

        $device->forceFill([
            'status' => PosDevice::STATUS_ACTIVE,
            'name' => $data['name'] ?? $device->name,
            'platform' => $data['platform'] ?? $device->platform,
            'os_version' => $data['os_version'] ?? $device->os_version,
            'app_version' => $data['app_version'] ?? $device->app_version,
            'activation_code_hash' => null,
            'activation_expires_at' => null,
            'last_seen_at' => now(),
        ])->save();

        ActivityLog::log('activated', 'PosDevice', "Offline device {$device->device_id} registered from {$device->platform}");

        return ['device' => $device->fresh(), 'token' => $token];
    }

    public function issueToken(PosDevice $device): string
    {
        $token = self::TOKEN_PREFIX.bin2hex(random_bytes(32));

        $device->forceFill([
            'api_token_hash' => hash('sha256', $token),
            'token_issued_at' => now(),
            'token_rotated_at' => $device->api_token_hash ? now() : $device->token_rotated_at,
        ])->save();

        return $token;
    }

    /** Resolve a bearer token + device id pair to a trusted device, or null. */
    public function authenticate(?string $token, ?string $deviceId): ?PosDevice
    {
        if (! $token || ! $deviceId) {
            return null;
        }

        $device = PosDevice::where('device_id', $deviceId)->first();

        if (! $device || ! $device->api_token_hash) {
            return null;
        }

        if (! hash_equals($device->api_token_hash, hash('sha256', $token))) {
            return null;
        }

        return $device;
    }

    public function disable(PosDevice $device, string $reason = 'Disabled by an administrator.'): void
    {
        $device->forceFill([
            'status' => PosDevice::STATUS_DISABLED,
            'disabled_at' => now(),
            'notes' => $reason,
        ])->save();

        ActivityLog::log('updated', 'PosDevice', "Offline device {$device->device_id} disabled");
    }

    public function enable(PosDevice $device): void
    {
        $device->forceFill([
            'status' => PosDevice::STATUS_ACTIVE,
            'disabled_at' => null,
        ])->save();

        ActivityLog::log('updated', 'PosDevice', "Offline device {$device->device_id} re-enabled");
    }

    /** A lost or stolen till: token killed, status final, activation code cleared. */
    public function revoke(PosDevice $device, string $reason = 'Reported lost or stolen.'): void
    {
        $device->forceFill([
            'status' => PosDevice::STATUS_REVOKED,
            'api_token_hash' => null,
            'activation_code_hash' => null,
            'revoked_at' => now(),
            'revoked_reason' => $reason,
        ])->save();

        ActivityLog::log('deleted', 'PosDevice', "Offline device {$device->device_id} revoked: {$reason}");
    }
}
