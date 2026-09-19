<?php

namespace App\Services\Sync\Handlers;

use App\Models\Counter;
use App\Models\Customer;
use App\Models\PosDevice;
use App\Models\Sale;
use App\Models\StockAdjustment;
use App\Services\Sales\InvoiceNumbers;
use App\Support\Schema as ColumnGuard;
use Illuminate\Support\Facades\DB;

/**
 * The one handler that must be perfect: an offline sale.
 *
 * Guarantees, in order of importance:
 *  1. NEVER LOST — a sale that reached the server is written, even if a product
 *     in it can no longer be resolved centrally (the line keeps its snapshots).
 *  2. NEVER DUPLICATED — the sale uuid is the identity; a replay is answered
 *     with the row that already exists, and the idempotency ledger catches the
 *     retry before it even gets here.
 *  3. ATOMIC — sale, lines, tenders, stock movements and customer totals are one
 *     transaction. Either the whole sale exists centrally or none of it does.
 *  4. NEVER REWRITTEN — a second, different version of the same sale uuid is a
 *     critical conflict for a human, never an UPDATE.
 */
class SaleHandler extends AbstractHandler
{
    /** Lines whose product no longer exists centrally (counted for the device's response). */
    private int $unresolvedProducts = 0;

    public function entityType(): string
    {
        return 'sale';
    }

    public function apply(array $change, PosDevice $device): array
    {
        $payload = $this->payload($change);
        $uuid = (string) $change['uuid'];

        $existing = Sale::withTrashed()->where('uuid', $uuid)->first();

        if ($existing) {
            return $this->verifyReplay($existing, $payload, $device, $uuid);
        }

        $items = array_values($payload['items'] ?? []);
        $payments = array_values(array_filter($payload['payments'] ?? [], fn ($p) => (float) ($p['amount'] ?? 0) > 0));

        if (count($items) === 0) {
            return $this->reject('A sale must carry at least one line.');
        }

        $integrity = $this->checkIntegrity($payload, $items, $payments, $uuid, $device);
        if (isset($integrity['error'])) {
            return $this->reject($integrity['error']);
        }

        $sale = app(InvoiceNumbers::class)->withRetry(
            fn () => DB::transaction(fn () => $this->writeSale($device, $change, $payload, $items, $payments))
        );

        $warnings = $integrity['warnings'];

        if ($this->unresolvedProducts > 0) {
            $warnings[] = $this->unresolvedProducts.' line(s) reference products that no longer exist centrally; the money is recorded, stock was left untouched.';
        }

        return $this->result('applied', [
            'server_id' => $sale->id,
            'server_uuid' => $sale->uuid,
            'server_revision' => $sale->revision,
            'server_row' => $this->row($sale),
            'warnings' => $warnings,
            'message' => "Sale {$sale->invoice_no} accepted.",
        ]);
    }

    /**
     * A sale with this uuid already exists. If it is the same sale (same money,
     * same time, same shape) this is a replay and the device is told "already
     * done". If the numbers differ, the financial record is NOT touched.
     */
    private function verifyReplay(Sale $sale, array $payload, PosDevice $device, string $uuid): array
    {
        $expectedTotal = round((float) ($payload['total'] ?? 0), 2);
        $actualTotal = round((float) $sale->total, 2);
        $expectedLines = count($payload['items'] ?? []);
        $actualLines = $sale->items()->count();

        $same = abs($expectedTotal - $actualTotal) < 0.01
            && $expectedLines === $actualLines
            && $this->sameInstant($sale->captured_at ?? $sale->sold_at, $payload['captured_at'] ?? null);

        if ($same) {
            return $this->duplicate($sale, "Sale {$sale->invoice_no} was already synchronized.");
        }

        $conflict = $this->conflicts->raiseFinancial('sale', $uuid, $payload, $this->row($sale), $device,
            'The same sale uuid arrived with different financial content.');

        return $this->result('conflict', [
            'conflict_id' => $conflict->id,
            'server_uuid' => $sale->uuid,
            'server_row' => $this->row($sale),
            'message' => 'This sale uuid already exists with different figures. Nothing was overwritten — an administrator must review it.',
        ]);
    }

    private function sameInstant(mixed $a, mixed $b): bool
    {
        if (! $a || ! $b) {
            return true;
        }

        try {
            return abs(\Illuminate\Support\Carbon::parse($a)->diffInSeconds(\Illuminate\Support\Carbon::parse($b))) <= 120;
        } catch (\Throwable) {
            return true;
        }
    }

    /** Structural checks only: the money was already taken at the till. */
    private function checkIntegrity(array $payload, array $items, array $payments, string $uuid, PosDevice $device): array
    {
        $warnings = [];
        $subtotal = 0.0;
        $tax = 0.0;
        $lineDiscount = 0.0;
        $expected = 0.0;

        foreach ($items as $i => $item) {
            $qty = (float) ($item['qty'] ?? 0);
            $unit = (float) ($item['unit_price'] ?? 0);
            $disc = (float) ($item['discount'] ?? 0);
            $lineTax = (float) ($item['tax'] ?? 0);
            $lineTotal = (float) ($item['line_total'] ?? 0);

            if ($qty <= 0) {
                return ['error' => "Line {$i} has a non-positive quantity."];
            }
            if ($unit < 0 || $disc < 0 || $lineTotal < 0) {
                return ['error' => "Line {$i} carries a negative amount."];
            }

            $computed = round(($unit * $qty) - $disc + $lineTax, 2);
            if (abs($computed - round($lineTotal, 2)) > 0.05) {
                return ['error' => "Line {$i} does not add up (line_total {$lineTotal} vs computed {$computed})."];
            }

            $subtotal += $unit * $qty;
            $tax += $lineTax;
            $lineDiscount += $disc;
            $expected += $lineTotal;
        }

        $billDiscount = (float) ($payload['discount'] ?? 0);
        $statedTotal = round((float) ($payload['total'] ?? 0), 2);
        $expectedTotal = round($expected - $billDiscount, 2);

        if (abs($expectedTotal - $statedTotal) > 0.05) {
            return ['error' => "The sale total ({$statedTotal}) does not match its lines ({$expectedTotal})."];
        }

        $paid = round(array_sum(array_map(fn ($p) => (float) ($p['amount'] ?? 0), $payments)), 2);

        if ($paid + 0.01 < $statedTotal) {
            $short = round($statedTotal - $paid, 2);
            $warnings[] = "Tendered {$paid} against a total of {$statedTotal} (short by {$short}); recorded as-is because the customer has already left with the goods.";

            $this->conflicts->raise('sale', $uuid, [
                'paid' => $paid, 'total' => $statedTotal,
            ], null, [
                'device' => $device,
                'policy' => 'append_only',
                'reason' => 'Offline sale was tendered short of its total.',
                'severity' => 'warning',
            ]);
        }

        return ['warnings' => $warnings];
    }

    private function writeSale(PosDevice $device, array $change, array $payload, array $items, array $payments): Sale
    {
        $companyId = $this->companyId($device);
        $branchId = $this->branchId($device);

        $customer = $this->customer($payload['customer_uuid'] ?? null);
        $cashier = $this->user($payload['user_uuid'] ?? null);
        $shift = $this->shift($payload['shift_uuid'] ?? null);
        $counter = isset($payload['counter_uuid']) && $payload['counter_uuid']
            ? Counter::where('uuid', $payload['counter_uuid'])->first()
            : null;

        $capturedAt = isset($payload['captured_at']) ? \Illuminate\Support\Carbon::parse($payload['captured_at']) : now();

        $sale = Sale::create(ColumnGuard::only('sales', array_merge($this->provenance($device, $change), [
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'shift_id' => $shift?->id,
            'counter_id' => $counter?->id ?? $shift?->counter_id,
            'channel' => 'retail',
            'invoice_no' => app(InvoiceNumbers::class)->next($companyId),
            'device_invoice_no' => $payload['device_invoice_no'] ?? $payload['invoice_no'] ?? null,
            'customer_id' => $customer?->id,
            'user_id' => $cashier?->id,
            'sold_at' => $capturedAt,
            'captured_at' => $capturedAt,
            'subtotal' => round((float) ($payload['subtotal'] ?? 0), 2),
            'discount' => round((float) ($payload['discount'] ?? 0), 2) + round(array_sum(array_map(fn ($i) => (float) ($i['discount'] ?? 0), $items)), 2),
            'tax' => round((float) ($payload['tax'] ?? 0), 2),
            'total' => round((float) ($payload['total'] ?? 0), 2),
            'paid' => round(array_sum(array_map(fn ($p) => (float) ($p['amount'] ?? 0), $payments)), 2),
            'change_due' => round((float) ($payload['change_due'] ?? 0), 2),
            'status' => 'completed',
            'note' => $payload['note'] ?? null,
        ])));

        $unresolved = 0;

        foreach ($items as $item) {
            $product = $this->product($item['product_uuid'] ?? null);
            $qty = (float) $item['qty'];

            $sale->items()->create(ColumnGuard::only('sale_items', array_merge($this->provenance($device, ['uuid' => $item['uuid'] ?? null]), [
                'company_id' => $companyId,
                'sale_id' => $sale->id,
                'product_id' => $product?->id,
                'product_uuid' => $item['product_uuid'] ?? null,
                'name' => $item['name'] ?? $product?->name ?? 'Unknown item',
                'barcode' => $item['barcode'] ?? $product?->barcode,
                'unit_price' => round((float) $item['unit_price'], 2),
                'cost_price' => round((float) ($item['cost_price'] ?? $product?->cost_price ?? 0), 2),
                'qty' => $qty,
                'discount' => round((float) ($item['discount'] ?? 0), 2),
                'tax' => round((float) ($item['tax'] ?? 0), 2),
                'line_total' => round((float) $item['line_total'], 2),
                'refunded_qty' => 0,
            ])));

            if (! $product) {
                $unresolved++;

                continue;
            }

            if ($product->track_inventory) {
                $this->moveStock($device, $product->lockForUpdate()->first() ?? $product, $qty, $sale);
            }
        }

        foreach ($payments as $payment) {
            $sale->payments()->create(ColumnGuard::only('sale_payments', [
                'company_id' => $companyId,
                'sale_id' => $sale->id,
                'method' => $payment['method'],
                'amount' => round((float) $payment['amount'], 2),
                'reference' => $payment['reference'] ?? null,
            ]));
        }

        if ($customer) {
            Customer::whereKey($customer->id)->update([
                'total_spent' => DB::raw('total_spent + '.(float) $sale->total),
                'orders_count' => DB::raw('orders_count + 1'),
                'loyalty_points' => DB::raw('loyalty_points + '.(int) floor((float) $sale->total / 100)),
            ]);
        }

        $this->unresolvedProducts = $unresolved;

        return $sale;
    }

    /**
     * Stock is a projection of movements. The server never accepts "stock = 50"
     * from a device: it applies the movement and records it, so the audit trail
     * survives.
     */
    private function moveStock(PosDevice $device, \App\Models\Product $product, float $qty, Sale $sale): void
    {
        $before = (float) $product->stock_qty;
        $after = round($before - $qty, 3);

        $product->forceFill(['stock_qty' => $after])->save();

        StockAdjustment::create(ColumnGuard::only('stock_adjustments', array_merge($this->provenance($device, ['uuid' => null]), [
            'company_id' => $this->companyId($device),
            'product_id' => $product->id,
            'user_id' => $sale->user_id,
            'type' => 'decrease',
            'reason' => 'offline_sale',
            'qty' => $qty,
            'stock_before' => $before,
            'stock_after' => $after,
            'note' => "Offline sale {$sale->invoice_no} from device {$device->device_id}",
        ])));
    }
}
