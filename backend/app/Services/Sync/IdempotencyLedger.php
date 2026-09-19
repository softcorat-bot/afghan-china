<?php

namespace App\Services\Sync;

use App\Models\SyncConflict;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * The outbox's server-side twin.
 *
 * Every change a device sends is keyed by the `change_uuid` of the outbox row
 * that produced it. If the same change arrives again — because the response was
 * lost, the app was restarted mid-sync, or the user pressed Sync Now twice — the
 * stored result is replayed and the change is *not* applied a second time. That
 * is what makes "no duplicate sales" a property of the design rather than a hope.
 */
class IdempotencyLedger
{
    /** @return array|null The recorded result for a change, if it was already processed. */
    public function find(string $changeUuid): ?array
    {
        $row = DB::table('sync_inbox')->where('change_uuid', $changeUuid)->first();

        if (! $row) {
            return null;
        }

        return [
            'change_uuid' => $row->change_uuid,
            'entity_type' => $row->entity_type,
            'uuid' => $row->entity_uuid,
            'operation' => $row->operation,
            'status' => $row->status,
            'replayed' => true,
            'server_id' => $row->server_id,
            'message' => $row->error_message,
            'conflict_id' => $this->conflictId($row->response),
            'response' => json_decode((string) $row->response, true) ?: null,
        ];
    }

    /**
     * Record the outcome of a change. Must be called inside the same database
     * transaction that applied it, so a crash between "applied" and "recorded"
     * can never happen.
     */
    public function record(array $change, array $result, ?string $batchUuid, ?string $deviceId): void
    {
        $existing = DB::table('sync_inbox')->where('change_uuid', $change['change_uuid'])->first();

        $payload = [
            'company_id' => Tenant::id(),
            'device_id' => $deviceId ?: ($change['device_id'] ?? 'unknown'),
            'batch_uuid' => $batchUuid,
            'change_uuid' => $change['change_uuid'],
            'entity_type' => $change['entity_type'],
            'entity_uuid' => $change['uuid'],
            'operation' => $change['operation'],
            'payload_hash' => hash('sha256', json_encode($change['payload'] ?? []) ?: ''),
            'status' => $result['status'],
            'http_status' => $result['http_status'] ?? 200,
            'server_id' => isset($result['server_id']) ? (string) $result['server_id'] : null,
            'response' => json_encode($this->slim($result)),
            'error_message' => $result['message'] ?? null,
            'processed_at' => now(),
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('sync_inbox')->where('id', $existing->id)->update($payload);

            return;
        }

        $payload['created_at'] = now();
        DB::table('sync_inbox')->insert($payload);
    }

    /** Housekeeping: the ledger is an audit trail, not an archive of everything forever. */
    public function prune(int $days): int
    {
        return DB::table('sync_inbox')
            ->where('processed_at', '<', now()->subDays($days))
            ->whereIn('status', ['applied', 'duplicate'])
            ->delete();
    }

    private function conflictId(?string $response): ?int
    {
        $decoded = json_decode((string) $response, true);

        return $decoded['conflict_id'] ?? null;
    }

    /** Keep the stored response small: it is a receipt, not a second copy of the row. */
    private function slim(array $result): array
    {
        $result = $result['response'] ?? $result;
        unset($result['server_row'], $result['payload']);

        return $result;
    }

    public function conflictFor(string $entityType, string $uuid, string $deviceId): ?SyncConflict
    {
        return SyncConflict::where('entity_type', $entityType)
            ->where('entity_uuid', $uuid)
            ->where('device_id', $deviceId)
            ->where('status', SyncConflict::STATUS_PENDING)
            ->latest('id')
            ->first();
    }
}
