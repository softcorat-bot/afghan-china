<?php

namespace App\Services\Sync;

use App\Models\PosDevice;
use App\Models\SyncBatch;
use App\Services\Sync\Handlers\AbstractHandler;
use App\Services\Sync\Handlers\CashMovementHandler;
use App\Services\Sync\Handlers\CashSessionHandler;
use App\Services\Sync\Handlers\CounterEndOfDayHandler;
use App\Services\Sync\Handlers\CustomerHandler;
use App\Services\Sync\Handlers\ExpenseHandler;
use App\Services\Sync\Handlers\ProductHandler;
use App\Services\Sync\Handlers\RefundHandler;
use App\Services\Sync\Handlers\SaleHandler;
use App\Services\Sync\Handlers\StockMovementHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Applies what a device did offline.
 *
 * Shape of the contract, and why:
 *  - each change is applied in its own transaction, so one bad record cannot
 *    roll back the ninety-nine good ones (partial sync is a feature, not a bug);
 *  - the outcome of every change is written to the idempotency ledger *inside*
 *    that same transaction, so "applied" and "recorded as applied" can never
 *    disagree;
 *  - an already-processed change replays its stored result instead of running
 *    again — the device may retry as often as it likes.
 */
class SyncPusher
{
    /**
     * Entities a device may send but that are not standalone rows. Explaining
     * why is better than a bare rejection: a device developer gets a fix, not a
     * mystery.
     */
    private const GUIDANCE = [
        'sale_payment' => 'A payment travels inside the sale it belongs to: send the sale change with its payments[] array so the whole sale stays one atomic, idempotent unit.',
        'sale_item' => 'Sale lines travel inside their sale change (items[]).',
    ];

    /** @var array<string, class-string<AbstractHandler>> */
    private const HANDLERS = [
        'sale' => SaleHandler::class,
        'refund' => RefundHandler::class,
        'stock_movement' => StockMovementHandler::class,
        'stock_adjustment' => StockMovementHandler::class,
        'cash_session' => CashSessionHandler::class,
        'cash_movement' => CashMovementHandler::class,
        'customer' => CustomerHandler::class,
        'product' => ProductHandler::class,
        'expense' => ExpenseHandler::class,
        'counter_end_of_day' => CounterEndOfDayHandler::class,
    ];

    public function __construct(
        private readonly IdempotencyLedger $ledger,
        private readonly ConflictResolver $conflicts,
    ) {
    }

    /**
     * @return array{batch_uuid:string, results:array, summary:array, cursor:int}
     */
    public function push(PosDevice $device, array $changes, ?string $batchUuid, ?string $appVersion, ?string $ip): array
    {
        $batchUuid = $batchUuid ?: (string) Str::uuid();
        $limit = (int) config('sync.max_push_batch', 500);

        if (count($changes) > $limit) {
            throw ValidationException::withMessages([
                'changes' => "A single push may carry at most {$limit} changes; this one carried ".count($changes).'.',
            ]);
        }

        $batch = SyncBatch::create([
            'uuid' => $batchUuid,
            'company_id' => $device->company_id,
            'branch_id' => $device->branch_id,
            'device_id' => $device->device_id,
            'direction' => 'push',
            'status' => 'completed',
            'changes_received' => count($changes),
            'app_version' => $appVersion,
            'ip' => $ip,
            'started_at' => now(),
        ]);

        $results = [];
        $summary = ['received' => count($changes), 'applied' => 0, 'duplicates' => 0, 'conflicts' => 0, 'rejected' => 0, 'errors' => 0];

        foreach ($changes as $change) {
            $result = $this->applyOne($device, $change, $batchUuid);
            $results[] = $result;

            $bucket = match ($result['status']) {
                'applied' => 'applied',
                'duplicate' => 'duplicates',
                'conflict' => 'conflicts',
                'rejected' => 'rejected',
                default => 'errors',
            };
            $summary[$bucket]++;
        }

        $batch->fill([
            'applied' => $summary['applied'],
            'duplicates' => $summary['duplicates'],
            'conflicts' => $summary['conflicts'],
            'rejected' => $summary['rejected'],
            'status' => ($summary['rejected'] + $summary['errors']) === 0 ? 'completed' : 'partial',
            'finished_at' => now(),
        ])->save();

        $device->forceFill([
            'last_seen_at' => now(),
            'last_sync_at' => now(),
            'last_ip' => $ip,
            'app_version' => $appVersion ?: $device->app_version,
        ])->save();

        return [
            'batch_uuid' => $batchUuid,
            'results' => $results,
            'summary' => $summary,
            'cursor' => app(SyncSequence::class)->current(),
        ];
    }

    private function applyOne(PosDevice $device, array $change, string $batchUuid): array
    {
        $change = $this->normalize($change);
        $changeUuid = $change['change_uuid'];
        $entityType = $change['entity_type'];

        // 1. Has this exact outbox row already been applied?
        if ($replay = $this->ledger->find($changeUuid)) {
            $replay['status'] = $replay['status'] === 'applied' ? 'duplicate' : $replay['status'];
            $replay['message'] = $replay['message'] ?: 'Replayed from the idempotency ledger.';

            return $replay;
        }

        // 2. Is there even a handler for it?
        $handlerClass = self::HANDLERS[$entityType] ?? null;

        if (! $handlerClass) {
            $result = [
                'change_uuid' => $changeUuid,
                'entity_type' => $entityType,
                'uuid' => $change['uuid'],
                'status' => 'rejected',
                'message' => self::GUIDANCE[$entityType]
                    ?? "This server does not accept '{$entityType}' from an offline device.",
                'http_status' => 422,
            ];

            $this->ledger->record($change, $result, $batchUuid, $device->device_id);

            return $result;
        }

        /** @var AbstractHandler $handler */
        $handler = app($handlerClass, ['conflicts' => $this->conflicts]);

        try {
            $outcome = DB::transaction(fn () => $handler->apply($change, $device));
        } catch (ValidationException $e) {
            $outcome = [
                'status' => 'rejected',
                'message' => collect($e->errors())->flatten()->first() ?: 'Rejected by validation.',
                'http_status' => 422,
            ];
        } catch (\Throwable $e) {
            report($e);

            // Transient (a lock timeout, a deadlock, a database blip): tell the
            // device to retry rather than recording a rejection it cannot fix.
            $outcome = [
                'status' => 'error',
                'message' => 'Server could not apply this change right now: '.$e->getMessage(),
                'http_status' => 503,
            ];
        }

        $result = array_merge([
            'change_uuid' => $changeUuid,
            'entity_type' => $entityType,
            'uuid' => $change['uuid'],
        ], $outcome);

        if (($result['status'] ?? 'error') !== 'error') {
            // Recorded in the same transaction the handler used, when the
            // handler succeeded; a replay will never re-run the work.
            DB::transaction(fn () => $this->ledger->record($change, $result, $batchUuid, $device->device_id));
        }

        return $result;
    }

    /** A change must be well-formed before anything touches the database. */
    private function normalize(array $change): array
    {
        $change['change_uuid'] = (string) ($change['change_uuid'] ?? $change['uuid'] ?? Str::uuid());
        $change['uuid'] = (string) ($change['uuid'] ?? '');
        $change['entity_type'] = (string) ($change['entity_type'] ?? '');
        $change['operation'] = (string) ($change['operation'] ?? 'create');
        $change['payload'] = is_array($change['payload'] ?? null) ? $change['payload'] : [];

        if ($change['uuid'] === '' || $change['entity_type'] === '') {
            throw ValidationException::withMessages([
                'changes' => 'Every change needs a uuid and an entity_type.',
            ]);
        }

        if (strlen($change['uuid']) > 64 || strlen($change['change_uuid']) > 64) {
            throw ValidationException::withMessages(['changes' => 'Device identifiers must be at most 64 characters.']);
        }

        return $change;
    }
}
