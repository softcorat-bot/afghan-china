<?php

namespace Tests\Feature\Offline;

use App\Models\Customer;
use App\Models\OfflineMeta;
use App\Models\OfflineOutbox;
use App\Models\Product;
use App\Services\Offline\CentralClient;
use App\Services\Offline\OfflineSyncException;
use App\Services\Offline\PullApplier;
use App\Services\Offline\SyncRunner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SyncRunnerTest extends OfflineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('offline.enabled', true);
        config()->set('offline.central_url', 'https://central.test');
        config()->set('offline.device_id', 'TEST-DEVICE');
        config()->set('offline.device_token', 'TEST-TOKEN');
    }

    private function queueChange(string $entity = 'customer', ?string $uuid = null): OfflineOutbox
    {
        return OfflineOutbox::create([
            'change_uuid' => (string) Str::uuid(),
            'entity_type' => $entity,
            'entity_uuid' => $uuid ?: (string) Str::uuid(),
            'operation' => 'create',
            'payload' => ['name' => 'Test'],
            'status' => OfflineOutbox::STATUS_PENDING,
            'captured_at' => now(),
        ]);
    }

    public function test_full_cycle_push_pull_ack(): void
    {
        $row = $this->queueChange();

        Http::fake([
            '*v1/sync/status*' => Http::response(['server_seq' => 100, 'conflicts_pending' => 0, 'server_time' => now()->toIso8601String()]),
            '*v1/sync/heartbeat*' => Http::response(['ok' => true]),
            '*v1/sync/push*' => Http::response([
                'batch_uuid' => 'b1',
                'results' => [['change_uuid' => $row->change_uuid, 'entity_type' => 'customer', 'uuid' => $row->entity_uuid, 'status' => 'applied', 'server_id' => 7]],
                'summary' => ['applied' => 1],
            ]),
            '*v1/sync/context*' => Http::response(['company' => null]),
            '*v1/sync/pull*' => Http::response([
                'data' => ['products' => [[
                    'id' => 500, 'company_id' => $this->company->id, 'uuid' => (string) Str::uuid(),
                    'revision' => 1, 'sync_seq' => 42, 'name' => 'Pulled Tea', 'sale_price' => '10.00',
                    'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
                ]]],
                'deleted' => [], 'next_cursor' => 42, 'has_more' => false,
            ]),
            '*v1/sync/ack*' => Http::response(['ok' => true]),
            '*v1/sync/conflicts*' => Http::response(['pending' => 0]),
        ]);

        $summary = SyncRunner::make()->run(['reason' => 'test']);

        $this->assertTrue($summary['ok']);
        $this->assertSame(1, $summary['push']['applied']);
        $this->assertSame(1, $summary['pull']['applied']);
        $this->assertSame(42, $summary['cursor_after']);
        $this->assertSame(OfflineOutbox::STATUS_SYNCED, $row->fresh()->status);
        $this->assertSame('7', $row->fresh()->server_id);
        $this->assertSame(1, Product::withoutGlobalScopes()->where('name', 'Pulled Tea')->count());
        $this->assertNotNull(OfflineMeta::get('sync.last_ok_at'));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/sync/push')
            && $r->hasHeader('X-Device-Id', 'TEST-DEVICE')
            && $r->hasHeader('Authorization', 'Bearer TEST-TOKEN'));
    }

    public function test_push_rebuilds_payload_from_live_row(): void
    {
        $customer = Customer::create([
            'company_id' => $this->company->id,
            'name' => 'Rebuild Me',
            'phone' => '0700111222',
        ]);

        // Stale snapshot (as if captured before later writes were committed).
        $row = OfflineOutbox::create([
            'change_uuid' => (string) Str::uuid(),
            'entity_type' => 'customer',
            'entity_uuid' => $customer->uuid,
            'operation' => 'create',
            'payload' => ['name' => 'Rebuild Me', 'phone' => '0700000000'],
            'status' => OfflineOutbox::STATUS_PENDING,
            'captured_at' => now(),
        ]);

        Customer::withoutEvents(fn () => $customer->update(['phone' => '0700999888']));

        Http::fake([
            '*v1/sync/status*' => Http::response(['server_seq' => 1, 'conflicts_pending' => 0]),
            '*v1/sync/heartbeat*' => Http::response(['ok' => true]),
            '*v1/sync/push*' => Http::response([
                'batch_uuid' => 'b9',
                'results' => [['change_uuid' => $row->change_uuid, 'entity_type' => 'customer', 'uuid' => $row->entity_uuid, 'status' => 'applied']],
                'summary' => ['applied' => 1],
            ]),
            '*v1/sync/context*' => Http::response(['company' => null]),
            '*v1/sync/pull*' => Http::response(['data' => [], 'deleted' => [], 'next_cursor' => 0, 'has_more' => false]),
            '*v1/sync/ack*' => Http::response(['ok' => true]),
            '*v1/sync/conflicts*' => Http::response(['pending' => 0]),
        ]);

        $summary = SyncRunner::make()->run(['reason' => 'test']);

        $this->assertTrue($summary['ok']);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/sync/push')
            && ($r->data()['changes'][0]['payload']['phone'] ?? null) === '0700999888');

        $this->assertSame('0700999888', $row->fresh()->payload['phone']);
    }

    public function test_duplicate_replay_marks_synced_without_double_apply(): void
    {
        $row = $this->queueChange();

        Http::fake([
            '*v1/sync/status*' => Http::response(['server_seq' => 1, 'conflicts_pending' => 0]),
            '*v1/sync/heartbeat*' => Http::response(['ok' => true]),
            // Central already has this exact change (a lost response retried).
            '*v1/sync/push*' => Http::response([
                'batch_uuid' => 'b2',
                'results' => [['change_uuid' => $row->change_uuid, 'entity_type' => 'customer', 'uuid' => $row->entity_uuid, 'status' => 'duplicate', 'message' => 'Replayed.']],
                'summary' => ['duplicates' => 1],
            ]),
            '*v1/sync/context*' => Http::response([]),
            '*v1/sync/pull*' => Http::response(['data' => [], 'deleted' => [], 'next_cursor' => 0, 'has_more' => false]),
            '*v1/sync/ack*' => Http::response(['ok' => true]),
            '*v1/sync/conflicts*' => Http::response(['pending' => 0]),
        ]);

        $summary = SyncRunner::make()->run(['reason' => 'test']);

        $this->assertSame(1, $summary['push']['duplicates']);
        $this->assertSame(OfflineOutbox::STATUS_SYNCED, $row->fresh()->status);
    }

    public function test_partial_push_failure_keeps_successes_and_flags_rest(): void
    {
        $good = $this->queueChange();
        $bad = $this->queueChange();
        $ugly = $this->queueChange();

        Http::fake([
            '*v1/sync/status*' => Http::response(['server_seq' => 1, 'conflicts_pending' => 0]),
            '*v1/sync/heartbeat*' => Http::response(['ok' => true]),
            '*v1/sync/push*' => Http::response([
                'batch_uuid' => 'b3',
                'results' => [
                    ['change_uuid' => $good->change_uuid, 'entity_type' => 'customer', 'uuid' => $good->entity_uuid, 'status' => 'applied'],
                    ['change_uuid' => $bad->change_uuid, 'entity_type' => 'customer', 'uuid' => $bad->entity_uuid, 'status' => 'rejected', 'message' => 'Name is required.'],
                    ['change_uuid' => $ugly->change_uuid, 'entity_type' => 'customer', 'uuid' => $ugly->entity_uuid, 'status' => 'conflict', 'message' => 'Changed on both sides.'],
                ],
                'summary' => [],
            ]),
            '*v1/sync/context*' => Http::response([]),
            '*v1/sync/pull*' => Http::response(['data' => [], 'deleted' => [], 'next_cursor' => 0, 'has_more' => false]),
            '*v1/sync/ack*' => Http::response(['ok' => true]),
            '*v1/sync/conflicts*' => Http::response(['pending' => 1]),
        ]);

        $summary = SyncRunner::make()->run(['reason' => 'test']);

        $this->assertSame(OfflineOutbox::STATUS_SYNCED, $good->fresh()->status);
        $this->assertSame(OfflineOutbox::STATUS_FAILED, $bad->fresh()->status);
        $this->assertSame('Name is required.', $bad->fresh()->last_error);
        $this->assertSame(OfflineOutbox::STATUS_CONFLICT, $ugly->fresh()->status);
        $this->assertNull(OfflineMeta::get('sync.last_ok_at'), 'a run with failures must not count as clean');
    }

    public function test_connection_loss_leaves_outbox_and_cursor_untouched(): void
    {
        $row = $this->queueChange();
        OfflineMeta::set('sync.cursor', 17);

        // A dead network surfaces as OfflineSyncException('central_unreachable')
        // from the client; the runner must leave outbox + cursor untouched.
        // (Thrown directly by a test double: raising through Http::fake
        // closures kills the Windows PHP process instead of propagating.)
        $client = new class extends CentralClient
        {
            public function status(int $timeoutSeconds): array
            {
                throw OfflineSyncException::connection(new \RuntimeException('network down'));
            }
        };

        try {
            (new SyncRunner($client, new PullApplier))->run(['reason' => 'test']);
            $this->fail('expected OfflineSyncException');
        } catch (OfflineSyncException $e) {
            $this->assertSame('central_unreachable', $e->code);
        }

        $this->assertSame(OfflineOutbox::STATUS_PENDING, $row->fresh()->status);
        $this->assertSame('17', (string) OfflineMeta::get('sync.cursor'));
        $this->assertNotEmpty(OfflineMeta::get('sync.last_error'));
    }

    public function test_unregistered_installation_refuses_with_guidance(): void
    {
        config()->set('offline.device_id', null);
        config()->set('offline.device_token', null);

        Http::fake(['*' => Http::response(['server_seq' => 1])]);

        $this->expectException(OfflineSyncException::class);

        try {
            SyncRunner::make()->run(['reason' => 'test']);
        } catch (OfflineSyncException $e) {
            $this->assertSame('offline_not_configured', $e->code);
            $this->assertStringContainsString('offline:register', $e->getMessage());

            throw $e;
        }
    }
}
