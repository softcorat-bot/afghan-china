<?php

namespace Tests\Feature\Offline;

use App\Models\OfflineOutbox;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

class SyncCenterApiTest extends OfflineTestCase
{
    public function test_status_reports_mode_counts_and_cursor(): void
    {
        config()->set('offline.enabled', true);
        Sanctum::actingAs($this->admin());

        OfflineOutbox::create([
            'change_uuid' => (string) Str::uuid(), 'entity_type' => 'sale', 'entity_uuid' => (string) Str::uuid(),
            'operation' => 'create', 'payload' => [], 'status' => OfflineOutbox::STATUS_PENDING,
        ]);

        $response = $this->getJson('/api/offline/status');

        $response->assertOk()
            ->assertJsonPath('mode', 'offline')
            ->assertJsonPath('outbox.pending', 1)
            ->assertJsonPath('device.registered', false);
    }

    public function test_offline_routes_404_when_online(): void
    {
        config()->set('offline.enabled', false);
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/offline/status')->assertNotFound();
        $this->postJson('/api/offline/sync')->assertNotFound();
    }

    public function test_sync_endpoint_runs_cycle_and_returns_summary(): void
    {
        config()->set('offline.enabled', true);
        config()->set('offline.central_url', 'https://central.test');
        config()->set('offline.device_id', 'API-TEST');
        config()->set('offline.device_token', 'TOKEN');
        Sanctum::actingAs($this->admin());

        Http::fake([
            '*v1/sync/status*' => Http::response(['server_seq' => 3, 'conflicts_pending' => 0]),
            '*v1/sync/heartbeat*' => Http::response(['ok' => true]),
            '*v1/sync/context*' => Http::response([]),
            '*v1/sync/pull*' => Http::response(['data' => [], 'deleted' => [], 'next_cursor' => 0, 'has_more' => false]),
            '*v1/sync/ack*' => Http::response(['ok' => true]),
            '*v1/sync/conflicts*' => Http::response(['pending' => 0]),
        ]);

        $this->postJson('/api/offline/sync')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonStructure(['summary' => ['push', 'pull', 'cursor_after']]);
    }

    public function test_retry_requeues_failed_rows(): void
    {
        config()->set('offline.enabled', true);
        Sanctum::actingAs($this->admin());

        $row = OfflineOutbox::create([
            'change_uuid' => (string) Str::uuid(), 'entity_type' => 'customer', 'entity_uuid' => (string) Str::uuid(),
            'operation' => 'create', 'payload' => [], 'status' => OfflineOutbox::STATUS_FAILED,
            'last_error' => 'boom',
        ]);

        $this->postJson('/api/offline/retry', ['id' => $row->id])
            ->assertOk()
            ->assertJsonPath('requeued', 1);

        $this->assertSame(OfflineOutbox::STATUS_PENDING, $row->fresh()->status);
    }

    public function test_non_privileged_user_is_forbidden(): void
    {
        config()->set('offline.enabled', true);

        Sanctum::actingAs(\App\Models\User::factory()->create());

        $this->getJson('/api/offline/status')->assertForbidden();
    }
}
