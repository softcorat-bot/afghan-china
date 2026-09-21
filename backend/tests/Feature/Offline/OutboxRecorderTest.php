<?php

namespace Tests\Feature\Offline;

use App\Models\Customer;
use App\Models\OfflineOutbox;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Shift;

class OutboxRecorderTest extends OfflineTestCase
{
    public function test_sale_create_queues_one_atomic_change_with_nested_children(): void
    {
        $this->enableOfflineMode();

        $product = Product::create(['company_id' => $this->company->id, 'name' => 'Rice 5kg', 'sale_price' => 420]);

        $sale = Sale::create([
            'company_id' => $this->company->id,
            'invoice_no' => 'T-1',
            'sold_at' => now(),
            'subtotal' => 840,
            'total' => 840,
            'paid' => 840,
        ]);

        SaleItem::create([
            'company_id' => $this->company->id, 'sale_id' => $sale->id, 'product_id' => $product->id,
            'name' => 'Rice 5kg', 'unit_price' => 420, 'qty' => 2, 'line_total' => 840,
        ]);

        SalePayment::create(['company_id' => $this->company->id, 'sale_id' => $sale->id, 'method' => 'cash', 'amount' => 840]);

        // Children are not observed individually — but the sale change must be
        // rebuilt to include them, so re-save the sale (as the POS does).
        $sale->touch();

        $rows = OfflineOutbox::query()->where('entity_type', 'sale')->get();

        // A sale is append-only: touch() must NOT queue an `update` row.
        $this->assertCount(1, $rows);

        $change = $rows->first();
        $this->assertSame('create', $change->operation);
        $this->assertSame($sale->uuid, $change->entity_uuid);
        $this->assertNotEmpty($change->change_uuid);
        $this->assertSame('T-1', $change->payload['device_invoice_no']);
    }

    public function test_sale_payload_shape_matches_server_handler_contract(): void
    {
        $this->enableOfflineMode();

        $product = Product::create(['company_id' => $this->company->id, 'name' => 'Tea', 'sale_price' => 100]);

        $sale = Sale::create([
            'company_id' => $this->company->id, 'invoice_no' => 'T-9', 'sold_at' => now(),
            'subtotal' => 200, 'total' => 200, 'paid' => 200,
        ]);

        SaleItem::create([
            'company_id' => $this->company->id, 'sale_id' => $sale->id, 'product_id' => $product->id,
            'name' => 'Tea', 'unit_price' => 100, 'qty' => 2, 'line_total' => 200,
        ]);

        SalePayment::create(['company_id' => $this->company->id, 'sale_id' => $sale->id, 'method' => 'cash', 'amount' => 200]);

        $change = (new \App\Services\Offline\ChangeBuilder)->build($sale->fresh(), 'sale', 'create');

        $this->assertSame('sale', $change['entity_type']);
        $this->assertCount(1, $change['payload']['items']);
        $this->assertSame($product->uuid, $change['payload']['items'][0]['product_uuid']);
        $this->assertSame(100.0, $change['payload']['items'][0]['unit_price']);
        $this->assertSame(200.0, $change['payload']['items'][0]['line_total']);
        $this->assertCount(1, $change['payload']['payments']);
        $this->assertSame('cash', $change['payload']['payments'][0]['method']);
        $this->assertSame(200.0, $change['payload']['total']);
        $this->assertNotEmpty($change['payload']['captured_at']);
    }

    public function test_customer_update_rebuilds_pending_create_instead_of_adding_update(): void
    {
        $this->enableOfflineMode();

        $customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Ahmad', 'phone' => '0700']);
        $customer->update(['phone' => '0701']);

        $rows = OfflineOutbox::query()->where('entity_type', 'customer')->get();

        $this->assertCount(1, $rows);
        $this->assertSame('create', $rows->first()->operation);
        $this->assertSame('0701', $rows->first()->payload['phone']);
    }

    public function test_create_then_delete_before_sync_coalesces_to_nothing(): void
    {
        $this->enableOfflineMode();

        $customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Ghost']);
        $this->assertSame(1, OfflineOutbox::query()->where('entity_type', 'customer')->count());

        $customer->delete();

        $this->assertSame(0, OfflineOutbox::query()->where('entity_type', 'customer')->count());
    }

    public function test_delete_after_sync_queues_tombstone(): void
    {
        $this->enableOfflineMode();

        $customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Karim']);
        OfflineOutbox::query()->update(['status' => OfflineOutbox::STATUS_SYNCED]);

        $customer->delete();

        $tomb = OfflineOutbox::query()->where('operation', 'delete')->first();

        $this->assertNotNull($tomb);
        $this->assertSame($customer->uuid, $tomb->entity_uuid);
        $this->assertNotEmpty($tomb->payload['deleted_at']);
    }

    public function test_shift_close_queues_update(): void
    {
        $this->enableOfflineMode();

        $shift = Shift::create(['company_id' => $this->company->id, 'opened_at' => now(), 'status' => 'open']);
        OfflineOutbox::query()->update(['status' => OfflineOutbox::STATUS_SYNCED]);

        $shift->update(['status' => 'closed', 'closed_at' => now(), 'counted_cash' => 5000]);

        $update = OfflineOutbox::query()->where('operation', 'update')->first();

        $this->assertNotNull($update);
        $this->assertSame('cash_session', $update->entity_type);
        $this->assertSame('closed', $update->payload['status']);
    }

    public function test_online_mode_records_nothing(): void
    {
        // Offline Mode deliberately NOT enabled.
        Customer::create(['company_id' => $this->company->id, 'name' => 'Online Only']);

        $this->assertSame(0, OfflineOutbox::query()->count());
    }
}
