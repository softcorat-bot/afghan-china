<?php

namespace App\Services\Offline;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Speaks the Central sync protocol (/api/v1/sync/*) with this installation's
 * device credential. Every method returns the decoded JSON body and throws
 * OfflineSyncException on transport/auth/server failures — the caller's
 * outbox and cursor are never touched by a failed call.
 */
class CentralClient
{
    public function __construct(
        private readonly ?string $baseUrl = null,
        private readonly ?string $deviceId = null,
        private readonly ?string $token = null,
    ) {
    }

    public static function fromIdentity(): self
    {
        $identity = DeviceIdentity::load();

        return new self(
            baseUrl: (string) config('offline.central_url'),
            deviceId: $identity['device_id'],
            token: $identity['device_token'],
        );
    }

    public function base(): string
    {
        return rtrim($this->baseUrl ?: (string) config('offline.central_url'), '/');
    }

    /** Register with a one-time activation code. Returns device + token payload. */
    public function register(string $deviceId, string $activationCode, ?string $name = null): array
    {
        $this->requireBase();

        $response = $this->send(fn () => $this->plain()->post($this->base().'/api/v1/sync/register', array_filter([
            'device_id' => $deviceId,
            'activation_code' => $activationCode,
            'name' => $name ?: $deviceId,
            'platform' => 'windows-offline',
            'app_version' => (string) config('offline.app_version'),
        ])));

        if ($response->status() === 422) {
            throw OfflineSyncException::device(
                (string) ($response->json('message') ?: 'Registration was refused. Check the activation code.'),
                'device_registration_refused',
                422
            );
        }

        return $this->decode($response, 'register');
    }

    public function status(int $timeoutSeconds): array
    {
        return $this->decode($this->send(
            fn () => $this->authed()->timeout($timeoutSeconds)->get($this->base().'/api/v1/sync/status')
        ), 'status');
    }

    public function heartbeat(array $stats): array
    {
        return $this->decode($this->send(
            fn () => $this->authed()->post($this->base().'/api/v1/sync/heartbeat', array_merge([
                'app_version' => (string) config('offline.app_version'),
                'os_version' => php_uname('s').' '.php_uname('r'),
            ], $stats))
        ), 'heartbeat');
    }

    /** @param array<int, array> $changes */
    public function push(array $changes, ?string $batchUuid = null): array
    {
        return $this->decode($this->send(
            fn () => $this->authed()->post($this->base().'/api/v1/sync/push', [
                'batch_uuid' => $batchUuid ?: (string) Str::uuid(),
                'app_version' => (string) config('offline.app_version'),
                'changes' => array_values($changes),
            ])
        ), 'push');
    }

    public function pull(int $sinceSeq, array $tables = [], ?int $limit = null): array
    {
        return $this->decode($this->send(
            fn () => $this->authed()->get($this->base().'/api/v1/sync/pull', [
                'since_seq' => max(0, $sinceSeq),
                'tables' => implode(',', $tables),
                'limit' => $limit ?: (int) config('offline.pull_limit', 500),
                'app_version' => (string) config('offline.app_version'),
            ])
        ), 'pull');
    }

    public function ack(int $cursor, array $applied = [], array $failed = [], ?string $message = null): array
    {
        return $this->decode($this->send(
            fn () => $this->authed()->post($this->base().'/api/v1/sync/ack', array_filter([
                'cursor' => $cursor,
                'applied' => $applied,
                'failed' => $failed,
                'message' => $message,
                'app_version' => (string) config('offline.app_version'),
            ]))
        ), 'ack');
    }

    /** Seed context: company + branch rows and the RBAC snapshot. */
    public function context(): array
    {
        return $this->decode($this->send(
            fn () => $this->authed()->get($this->base().'/api/v1/sync/context')
        ), 'context');
    }

    public function conflicts(bool $pendingOnly = false, int $limit = 100): array
    {
        return $this->decode($this->send(
            fn () => $this->authed()->get($this->base().'/api/v1/sync/conflicts', [
                'pending_only' => $pendingOnly,
                'limit' => $limit,
            ])
        ), 'conflicts');
    }

    public function rotateToken(): array
    {
        return $this->decode($this->send(
            fn () => $this->authed()->post($this->base().'/api/v1/sync/token/rotate')
        ), 'token/rotate');
    }

    /**
     * Every transport failure becomes an OfflineSyncException — never a raw
     * Guzzle/HTTP exception — so callers have exactly one failure type and the
     * outbox/cursor always stay retryable.
     */
    private function send(callable $request): \Illuminate\Http\Client\Response
    {
        try {
            return $request();
        } catch (ConnectionException $e) {
            throw OfflineSyncException::connection($e);
        }
    }

    private function plain(): PendingRequest
    {
        return Http::acceptJson()->asJson()
            ->timeout((int) config('offline.timeout', 30))
            ->connectTimeout((int) config('offline.connect_timeout', 8));
    }

    private function authed(): PendingRequest
    {
        $this->requireBase();

        $deviceId = $this->deviceId ?: DeviceIdentity::load()['device_id'];
        $token = $this->token ?: DeviceIdentity::load()['device_token'];

        if (! $deviceId || ! $token) {
            throw OfflineSyncException::notConfigured(
                'This installation is not registered with Central yet. Register it first (Sync Center → Register, or: php artisan offline:register --code XXXX-XXXX).'
            );
        }

        return $this->plain()->withToken($token)->withHeaders(['X-Device-Id' => $deviceId]);
    }

    private function requireBase(): void
    {
        if ($this->base() === '') {
            throw OfflineSyncException::notConfigured('CENTRAL_URL is not set. Point this installation at Central first.');
        }
    }

    /** @throws OfflineSyncException */
    private function decode(\Illuminate\Http\Client\Response $response, string $op): array
    {
        if ($response->status() === 401 || $response->status() === 403) {
            throw OfflineSyncException::device(
                (string) ($response->json('message') ?: 'Central refused this device credential.'),
                (string) ($response->json('code') ?: 'device_rejected'),
                $response->status()
            );
        }

        if ($response->serverError()) {
            throw OfflineSyncException::server(
                'Central answered '.$op.' with HTTP '.$response->status().'. Nothing was marked synced; retry later.',
                $response->status()
            );
        }

        if ($response->failed()) {
            $message = $response->json('message');

            if (is_array($message)) {
                $message = (string) collect($message)->flatten()->first();
            }

            throw OfflineSyncException::server(
                'Central rejected '.$op.': '.($message ?: 'HTTP '.$response->status()),
                $response->status()
            );
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
    }
}
