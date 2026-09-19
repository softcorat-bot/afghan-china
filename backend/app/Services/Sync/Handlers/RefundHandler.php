<?php

namespace App\Services\Sync\Handlers;

use App\Models\PosDevice;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockAdjustment;
use App\Support\Schema as ColumnGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A refund rung offline against a sale — possibly a sale this server has not
 * heard of yet, in which case the refund waits: it is rejected with a clear
 * message (`sale not found`) so the device retries it *after* the sale lands,
 * never before it, and never inventing a standalone refund that would leave the
 * books unbalanced.
 */
class RefundHandler extends AbstractHandler
{
    public function entityType(): string
    {
        return 'refund';
    }

    public function apply(array $change, PosDevice $device): array
    {
        $payload = $this->payload($change);
        $uuid = (string) $change['uuid'];
        $companyId = $this->companyId($device);

        $existing = Refund::where('uuid', $uuid)->first();
        if ($existing) {
            return $this->duplicate($existing, 'This refund was already synchronized.');
        }

        $sale = Sale::withTrashed()->where('uuid', $payload['sale_uuid'] ?? '')->first();

        if (! $sale) {
            return $this->reject('The sale this refund belongs to has not reached the server yet — sync the sale first, then retry.');
        }

        $amount = round((float) ($payload['amount'] ?? 0), 2);
        if ($amount <= 0) {
            return $this->reject('A refund must carry a positive amount.');
        }

        $alreadyRefunded = round((float) ($sale->refunded_amount ?? 0), 2);
        $refundable = round((float) $sale->total - $alreadyRefunded, 2);

        if ($amount > $refundable + 0.05) {
            return $this->reject("This refund ({$amount}) exceeds what is left on invoice {$sale->invoice_no} ({$refundable}).");
        }

        $capturedAt = isset($payload['captured_at']) ? Carbon::parse($payload['captured_at']) : now();

        $refund = DB::transaction(function () use ($device, $change, $payload, $sale, $amount, $companyId, $capturedAt) {
            $refund = Refund::create(ColumnGuard::only('refunds', array_merge($this->provenance($device, $change), [
                'company_id' => $companyId,
                'sale_id' => $sale->id,
                'user_id' => $this->user($payload['user_uuid'] ?? null)?->id,
                'amount' => $amount,
                'reason' => $payload['reason'] ?? 'offline refund',
                'note' => $payload['note'] ?? null,
            ])));

            foreach ($payload['items'] ?? [] as $line) {
                $saleItem = isset($line['sale_item_uuid'])
                    ? SaleItem::where('uuid', $line['sale_item_uuid'])->first()
                    : null;

                $refund->items()->create(ColumnGuard::only('refund_items', [
                    'company_id' => $companyId,
                    'refund_id' => $refund->id,
                    'sale_item_id' => $saleItem?->id,
                    'product_id' => $saleItem?->product_id,
                    'qty' => (float) ($line['qty'] ?? 0),
                    'amount' => round((float) ($line['amount'] ?? 0), 2),
                ]));

                if (! $saleItem) {
                    continue;
                }

                $qty = (float) ($line['qty'] ?? 0);
                $saleItem->increment('refunded_qty', $qty);

                // Goods came back: put them on the shelf again, as a movement.
                $product = $saleItem->product_id ? Product::lockForUpdate()->find($saleItem->product_id) : null;
                if ($product && $product->track_inventory) {
                    $before = (float) $product->stock_qty;
                    $after = round($before + $qty, 3);
                    $product->forceFill(['stock_qty' => $after])->save();

                    StockAdjustment::create(ColumnGuard::only('stock_adjustments', [
                        'company_id' => $companyId,
                        'product_id' => $product->id,
                        'user_id' => $refund->user_id,
                        'type' => 'increase',
                        'reason' => 'refund',
                        'qty' => $qty,
                        'stock_before' => $before,
                        'stock_after' => $after,
                        'note' => "Offline refund on {$sale->invoice_no} from device {$device->device_id}",
                        'origin' => 'offline',
                        'device_id' => $device->device_id,
                        'synced_at' => now(),
                        'uuid' => (string) \Illuminate\Support\Str::uuid(),
                    ]));
                }
            }

            $sale->refunded_amount = round($alreadyRefunded + $amount, 2);
            $sale->status = $sale->refunded_amount >= (float) $sale->total - 0.05 ? 'refunded' : 'partially_refunded';
            $sale->save();

            return $refund;
        });

        return $this->result('applied', [
            'server_id' => $refund->id,
            'server_uuid' => $refund->uuid,
            'server_row' => $this->row($refund),
            'message' => "Refund of {$amount} accepted on invoice {$sale->invoice_no}.",
        ]);
    }
}
