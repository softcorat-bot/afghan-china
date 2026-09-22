<?php

namespace App\Services\Offline;

use RuntimeException;

/**
 * Something went wrong talking to Central. The agent treats these as
 * *transport* failures — the outbox is untouched and everything stays
 * retryable — unless the failure says the device itself is no longer welcome
 * (revoked/disabled/unknown), which is surfaced, not retried blindly.
 */
class OfflineSyncException extends RuntimeException
{
    // NOTE: the machine code is deliberately NOT named `$code` — Exception
    // already owns an (int) $code, and redeclaring it as string is a fatal.
    public function __construct(
        string $message,
        public readonly string $syncCode = 'sync_error',
        public readonly bool $deviceRejected = false,
        public readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function connection(\Throwable $e): self
    {
        return new self(
            'Could not reach Central ('.$e->getMessage().'). The work stays queued locally.',
            'central_unreachable',
            false,
            null,
            $e
        );
    }

    public static function device(string $message, string $code = 'device_rejected', ?int $http = null): self
    {
        return new self($message, $code, true, $http);
    }

    public static function server(string $message, ?int $http = null): self
    {
        return new self($message, 'central_error', false, $http);
    }

    public static function notConfigured(string $message): self
    {
        return new self($message, 'offline_not_configured');
    }
}
