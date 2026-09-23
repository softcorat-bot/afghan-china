<?php

namespace Tests\Feature\Offline;

use App\Models\OfflineMeta;
use App\Models\PosDevice;
use App\Models\User;
use App\Services\Offline\PullApplier;
use App\Services\Sync\DeviceRegistrar;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/sync/context — the company + RBAC snapshot a till seeds from.
 * The route existed but pointed at a controller method that did not, so the
 * very first `offline:seed` against a real Central failed.
 */
class SyncContextTest extends OfflineTestCase
{
    private function deviceHeaders(): array
    {
        $admin = $this->admin();
        $admin->forceFill(['company_id' => $this->company->id])->save();
        $this->company->users()->syncWithoutDetaching([$admin->id]);

        $registrar = app(DeviceRegistrar::class);
        ['device' => $device, 'activation_code' => $code] = $registrar->create($admin, [
            'name' => 'Till 1', 'company_id' => $this->company->id, 'branch_id' => null,
        ]);
        ['token' => $token] = $registrar->register(['device_id' => $device->device_id, 'activation_code' => $code]);

        return ['X-Device-Id' => $device->device_id, 'Authorization' => 'Bearer '.$token];
    }

    public function test_context_returns_the_device_company_and_rbac_snapshot(): void
    {
        $headers = $this->deviceHeaders();

        foreach (['/api/v1/sync/context', '/api/sync/context'] as $url) {
            $this->getJson($url, $headers)
                ->assertOk()
                ->assertJsonPath('company.id', $this->company->id)
                ->assertJsonStructure(['company', 'company_user', 'roles', 'permissions',
                    'role_has_permissions', 'model_has_roles', 'model_has_permissions']);
        }
    }

    public function test_context_applies_on_a_fresh_till_before_users_are_pulled(): void
    {
        $context = $this->getJson('/api/v1/sync/context', $this->deviceHeaders())->json();
        $this->assertNotEmpty($context['company_user']);

        // Simulate a brand-new till: no users yet (they come with the first pull).
        DB::table('company_user')->delete();
        User::withoutGlobalScopes()->withTrashed()->get()->each->forceDelete();
        OfflineMeta::set('device.company_id', $this->company->id);

        (new PullApplier)->applyContext($context);   // must not hit the FK

        $this->assertSame(1, DB::table('companies')->where('id', $this->company->id)->count());
        $this->assertSame(0, DB::table('company_user')->count());
    }

    public function test_sync_log_pages_load_once_a_till_has_synced(): void
    {
        $headers = $this->deviceHeaders();
        $this->postJson('/api/v1/sync/ack', ['cursor' => 0], $headers)->assertOk();   // writes a SyncBatch

        \Laravel\Sanctum\Sanctum::actingAs(User::withoutGlobalScopes()->where('is_super_admin', true)->first());
        \App\Support\Tenant::set($this->company->id);

        $this->getJson('/api/sync/batches')->assertOk()->assertJsonPath('data.0.device.device_id', $headers['X-Device-Id']);
        $this->getJson('/api/sync/overview')->assertOk();
    }
}
