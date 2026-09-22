<?php

namespace App\Services\Offline;

use App\Models\CashMovement;
use App\Models\Counter;
use App\Models\CounterEndOfDay;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\StockAdjustment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Turns a local Eloquent model into a Central sync change.
 *
 * The payload shapes here are the exact contract the server handlers
 * (App\Services\Sync\Handlers\*) read: relations by uuid, money as numbers,
 * children nested inside the parent. If a handler gains a field, the matching
 * builder method below is the one place that learns it.
 */
class ChangeBuilder
{
    public function build(Model $model, string $entity, string $operation): array
    {
        $payload = $this->payloadFor($model, $entity);

        if ($operation === 'delete') {
            $payload = ['deleted_at' => now()->toIso8601String()] + $payload;
        }

        $revision = (int) ($model->revision ?? 1);

        return [
            'change_uuid' => (string) Str::uuid(),
            'entity_type' => $entity,
            'operation' => $operation,
            'uuid' => (string) ($model->uuid ?? ''),
            'base_revision' => $operation === 'update' ? max(1, $revision - 1) : null,
            'captured_at' => $this->capturedAt($model),
            'payload' => $payload,
        ];
    }

    private function payloadFor(Model $model, string $entity): array
    {
        return match ($entity) {
            'sale' => $this->sale($model),
            'refund' => $this->refund($model),
            'stock_movement' => $this->stockMovement($model),
            'cash_session' => $this->cashSession($model),
            'cash_movement' => $this->cashMovement($model),
            'customer' => $this->customer($model),
            'product' => $this->product($model),
            'expense' => $this->expense($model),
            'counter_end_of_day' => $this->counterEndOfDay($model),
            default => $model->attributesToArray(),
        };
    }

    // ── Transactions (append-only; children nested in the parent) ────────────

    private function sale(Sale $sale): array
    {
        $sale->loadMissing(['items.product', 'payments']);

        $items = [];
        foreach ($sale->items as $line) {
            $items[] = [
                'uuid' => $line->uuid,
                'product_uuid' => $line->product ? $line->product->uuid : null,
                'name' => $line->name,
                'barcode' => $line->barcode,
                'unit_price' => (float) $line->unit_price,
                'cost_price' => (float) $line->cost_price,
                'qty' => (float) $line->qty,
                'discount' => (float) $line->discount,
                'tax' => (float) $line->tax,
                'line_total' => (float) $line->line_total,
            ];
        }

        $payments = [];
        foreach ($sale->payments as $pay) {
            $payments[] = [
                'method' => $pay->method,
                'amount' => (float) $pay->amount,
                'reference' => $pay->reference,
            ];
        }

        return [
            'invoice_no' => $sale->invoice_no,
            'device_invoice_no' => $sale->invoice_no,
            'customer_uuid' => $this->uuidOf(Customer::class, $sale->customer_id),
            'user_uuid' => $this->uuidOf(User::class, $sale->user_id),
            'shift_uuid' => $this->uuidOf(Shift::class, $sale->shift_id),
            'counter_uuid' => $this->uuidOf(Counter::class, $sale->counter_id),
            'captured_at' => $sale->sold_at ? $sale->sold_at->toIso8601String() : null,
            'subtotal' => (float) $sale->subtotal,
            'discount' => (float) $sale->discount,
            'tax' => (float) $sale->tax,
            'total' => (float) $sale->total,
            'paid' => (float) $sale->paid,
            'change_due' => (float) $sale->change_due,
            'status' => $sale->status,
            'note' => $sale->note,
            'items' => $items,
            'payments' => $payments,
        ];
    }

    private function refund(Refund $refund): array
    {
        $refund->loadMissing('items.saleItem');

        $items = [];
        foreach ($refund->items as $line) {
            $items[] = [
                'sale_item_uuid' => $line->saleItem ? $line->saleItem->uuid : null,
                'qty' => (float) $line->qty,
                'amount' => (float) $line->amount,
            ];
        }

        return [
            'sale_uuid' => $this->uuidOf(Sale::class, $refund->sale_id),
            'user_uuid' => $this->uuidOf(User::class, $refund->user_id),
            'amount' => (float) $refund->amount,
            'reason' => $refund->reason,
            'note' => $refund->note,
            'captured_at' => $refund->created_at ? $refund->created_at->toIso8601String() : null,
            'items' => $items,
        ];
    }

    private function stockMovement(StockAdjustment $adj): array
    {
        return [
            'product_uuid' => $this->uuidOf(Product::class, $adj->product_id),
            'user_uuid' => $this->uuidOf(User::class, $adj->user_id),
            'type' => $adj->type,
            'qty' => abs((float) $adj->qty),
            'reason' => $adj->reason,
            'note' => $adj->note,
        ];
    }

    private function cashSession(Shift $shift): array
    {
        return [
            'user_uuid' => $this->uuidOf(User::class, $shift->user_id),
            'opened_at' => $shift->opened_at ? $shift->opened_at->toIso8601String() : null,
            'closed_at' => $shift->closed_at ? $shift->closed_at->toIso8601String() : null,
            'opening_float' => (float) $shift->opening_float,
            'counted_cash' => $shift->counted_cash === null ? null : (float) $shift->counted_cash,
            'expected_cash' => $shift->expected_cash === null ? null : (float) $shift->expected_cash,
            'variance' => $shift->variance === null ? null : (float) $shift->variance,
            'cash_sales' => (float) $shift->cash_sales,
            'card_sales' => (float) $shift->card_sales,
            'mobile_sales' => (float) $shift->mobile_sales,
            'cash_in' => (float) $shift->cash_in,
            'cash_out' => (float) $shift->cash_out,
            'total_sales' => (float) $shift->total_sales,
            'orders_count' => (int) $shift->orders_count,
            'status' => $shift->status,
            'note' => $shift->note,
        ];
    }

    private function cashMovement(CashMovement $move): array
    {
        return [
            'shift_uuid' => $this->uuidOf(Shift::class, $move->shift_id),
            'user_uuid' => $this->uuidOf(User::class, $move->user_id),
            'type' => $move->type,
            'amount' => (float) $move->amount,
            'reason' => $move->reason,
            'note' => $move->note,
        ];
    }

    // ── Master data ──────────────────────────────────────────────────────────

    private function customer(Customer $customer): array
    {
        return [
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'address' => $customer->address,
            'note' => $customer->note ?? null,
        ];
    }

    private function product(Product $product): array
    {
        // Opening stock travels as a number here ONLY so the server can convert
        // it into an opening_stock movement (ProductHandler); the server never
        // stores a pushed stock figure as the quantity.
        return [
            'name' => $product->name,
            'name_fa' => $product->name_fa,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'category_uuid' => $this->uuidOf(ProductCategory::class, $product->category_id),
            'brand' => $product->brand ?? null,
            'unit' => $product->unit,
            'description' => $product->description ?? null,
            'status' => $product->status ?? 'active',
            'cost_price' => (float) ($product->cost_price ?? 0),
            'sale_price' => (float) ($product->sale_price ?? 0),
            'wholesale_price' => $product->wholesale_price ?? null,
            'tax_rate' => (float) ($product->tax_rate ?? 0),
            'track_inventory' => (bool) ($product->track_inventory ?? true),
            'stock_qty' => (float) ($product->stock_qty ?? 0),
            'min_stock' => (float) ($product->min_stock ?? 0),
            'active' => (bool) ($product->active ?? true),
        ];
    }

    private function expense(Expense $expense): array
    {
        $spentOn = $expense->spent_on;

        return [
            'user_uuid' => $this->uuidOf(User::class, $expense->user_id),
            'spent_on' => $spentOn instanceof \DateTimeInterface ? $spentOn->format('Y-m-d') : (string) $spentOn,
            'category' => $expense->category,
            'payee' => $expense->payee,
            'amount' => (float) $expense->amount,
            'method' => $expense->method,
            'reference' => $expense->reference,
            'note' => $expense->note,
        ];
    }

    private function counterEndOfDay(CounterEndOfDay $report): array
    {
        $reportDate = $report->report_date;

        return [
            'counter_uuid' => $this->uuidOf(Counter::class, $report->counter_id),
            'user_uuid' => $this->uuidOf(User::class, $report->user_id),
            'report_date' => $reportDate instanceof \DateTimeInterface ? $reportDate->format('Y-m-d') : (string) $reportDate,
            'submitted_at' => $report->submitted_at ? $report->submitted_at->toIso8601String() : null,
            'opening_float' => (float) $report->opening_float,
            'cash_sales' => (float) $report->cash_sales,
            'card_sales' => (float) $report->card_sales,
            'mobile_sales' => (float) $report->mobile_sales,
            'expected_cash' => (float) $report->expected_cash,
            'counted_cash' => (float) $report->counted_cash,
            'variance' => (float) $report->variance,
            'cash_in' => (float) $report->cash_in,
            'cash_out' => (float) $report->cash_out,
            'total_income' => (float) $report->total_income,
            'total_expense' => (float) $report->total_expense,
            'net_profit' => (float) $report->net_profit,
            'note' => $report->notes ?? null,
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function capturedAt(Model $model): ?string
    {
        foreach (['sold_at', 'opened_at', 'spent_on', 'submitted_at', 'created_at'] as $key) {
            $value = $model->{$key} ?? null;

            if ($value) {
                return $value instanceof \DateTimeInterface ? $value->toIso8601String() : (string) $value;
            }
        }

        return now()->toIso8601String();
    }

    /** Resolve a local FK id to the global uuid Central understands. */
    private function uuidOf(string $class, mixed $id): ?string
    {
        if (empty($id) || ! class_exists($class)) {
            return null;
        }

        try {
            return $class::withoutGlobalScopes()->whereKey($id)->value('uuid');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
