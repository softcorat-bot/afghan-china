<?php

namespace Tests\Feature\Offline;

use App\Models\OfflineMeta;
use App\Models\OfflineOutbox;
use App\Models\Product;
use App\Models\SyncConflict;
use App\Services\Offline\PullApplier;
use Illuminate\Support\Str;

class PullApplierTest extends OfflineTestCase
{
    private function row(array $over = []): array
    {
        return array_merge([
            'id' => 9001,
            'company_id' => $this->company->id,
            'uuid' => (string) Str::uuid(),
            'revision' => 3,
            'sync_seq' => 10,
            'origin' => 'web',
            'device_id' => null,
            'name' => 'Sugar 1kg',
            'sale_price' => '95.00',
            'stock_qty' => '40.000',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ], $over);
    }

    public function test_insert_preserves_central_id_and_is_idempotent(): void
    {
        $row = $this->row();

        $first = (new PullApplier)->applyPage(['products' => [$row]], [], 0);

        $this->assertSame(10, $first['cursor']);
        $this->assertCount(1, $first['applied']);
        $this->assertSame(9001, Product::withoutGlobalScopes()->where('uuid', $row['uuid'])->value('id'));

        $second = (new PullApplier)->applyPage(['products' => [$row]], [], 10);

        $this->assertSame(10, $second['cursor']);
        $this->assertSame(1, Product::withoutGlobalScopes()->where('uuid', $row['uuid'])->count());
    }

    public function test_update_applies_server_values(): void
    {
        $row = $this->row();
        (new PullApplier)->applyPage(['products' => [$row]], [], 0);

        $row['sale_price'] = '99.00';
        $row['revision'] = 4;
        $row['sync_seq'] = 11;

        $page = (new PullApplier)->applyPage(['products' => [$row]], [], 10);

        $this->assertSame(11, $page['cursor']);
        $this->assertSame('99.00', (string) Product::withoutGlobalScopes()->where('uuid', $row['uuid'])->value('sale_price'));
    }

    public function test_tombstone_soft_deletes_local_row(): void
    {
        $row = $this->row();
        (new PullApplier)->applyPage(['products' => [$row]], [], 0);

        $page = (new PullApplier)->applyPage([], ['products' => [['uuid' => $row['uuid'], 'sync_seq' => 12]]], 10);

        $this->assertSame(12, $page['cursor']);
        $this->assertTrue(Product::withoutGlobalScopes()->where('uuid', $row['uuid'])->first()->trashed());
    }

    public function test_row_with_pending_local_change_is_skipped_and_conflicted(): void
    {
        $this->enableOfflineMode();

        $row = $this->row();
        (new PullApplier)->applyPage(['products' => [$row]], [], 0);

        // A local edit queues a pending change…
        $product = Product::withoutGlobalScopes()->where('uuid', $row['uuid'])->first();
        $product->update(['sale_price' => 120]);

        $this->assertTrue(OfflineOutbox::query()->where('entity_uuid', $row['uuid'])->where('status', 'pending')->exists());

        // …so Central's newer value must NOT overwrite it, and the cursor must
        // NOT advance past the skipped row (it will be re-offered next pull).
        $row['sale_price'] = '99.00';
        $row['revision'] = 9;
        $row['sync_seq'] = 50;

        $page = (new PullApplier)->applyPage(['products' => [$row]], [], 10);

        $this->assertSame(10, $page['cursor']);
        $this->assertCount(1, $page['conflicts']);
        $this->assertSame(1, SyncConflict::withoutGlobalScopes()->where('status', 'pending')->count());

        $conflict = SyncConflict::withoutGlobalScopes()->first();
        $this->assertSame('product', $conflict->entity_type);
        $this->assertArrayHasKey('sale_price', $conflict->differing_fields);
        $this->assertSame('120.00', (string) $conflict->local_payload['sale_price']);

        // Local value untouched.
        $this->assertEquals(120.0, (float) Product::withoutGlobalScopes()->where('uuid', $row['uuid'])->value('sale_price'));
    }

    public function test_apply_context_seeds_company_and_rbac(): void
    {
        $other = \App\Models\Company::create(['name_en' => 'Other']);
        config()->set('offline.device_id', 'TEST-1');

        (new PullApplier)->applyContext([
            'company' => ['id' => $other->id, 'name_en' => 'Other Renamed', 'lang' => 'en', 'calendar_type' => 'en'],
            'roles' => [['id' => 31, 'name' => 'Cashier', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]],
            'permissions' => [['id' => 32, 'name' => 'pos-sell', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]],
            'role_has_permissions' => [['permission_id' => 32, 'role_id' => 31]],
            'model_has_roles' => [],
            'model_has_permissions' => [],
        ]);

        $this->assertSame('Other Renamed', \App\Models\Company::find($other->id)->name_en);
        $this->assertDatabaseHas('roles', ['id' => 31, 'name' => 'Cashier']);
        $this->assertDatabaseHas('role_has_permissions', ['permission_id' => 32, 'role_id' => 31]);
    }
}
