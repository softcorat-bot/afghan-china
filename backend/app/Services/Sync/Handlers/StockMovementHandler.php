<?php

namespace App\Services\Sync\Handlers;

use App\Models\PosDevice;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Support\Schema as ColumnGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Stock movements made offline (a recount, damage, expiry write-off, a transfer
 * received). The device sends *movements*, never a stock number: the server
 * applies the delta to its own quantity and keeps the movement as evidence.
 * That is the only way two tills and the warehouse can disagree without anyone
 * losing track of why.
 */
class StockMovementHandler extends AbstractHandler
{
    public function entityType(): string
    {
        return 'stock_movement';
    }

    public function apply(array $change, PosDevice $device): array
    {
        $payload = $this->payload($change);
        $uuid = (string) $change['uuid'];
        $companyId = $this->companyId($device);

        $existing = StockAdjustment::where('uuid', $uuid)->first();
        if ($existing) {
            return $this->duplicate($existing, 'This stock movement was already applied.');
        }

        $product = $this->product($payload['product_uuid'] ?? null);
        if (! $product) {
            return $this->reject('Unknown product — pull the catalogue first, then retry this movement.');
        }

        $qty = abs((float) ($payload['qty'] ?? 0));
        if ($qty <= 0) {
            return $this->reject('A stock movement must carry a positive quantity.');
        }

        $type = ($payload['type'] ?? 'decrease') === 'increase' ? 'increase' : 'decrease';

        $adjustment = DB::transaction(function () use ($device, $change, $payload, $product, $qty, $type, $companyId) {
            $locked = Product::lockForUpdate()->find($product->id);
            $before = (float) $locked->stock_qty;
            $after = round($type === 'increase' ? $before + $qty : $before - $qty, 3);

            $locked->forceFill(['stock_qty' => $after])->save();

            return StockAdjustment::create(ColumnGuard::only('stock_adjustments', array_merge($this->provenance($device, $change), [
                'company_id' => $companyId,
                'product_id' => $locked->id,
                'user_id' => $this->user($payload['user_uuid'] ?? null)?->id,
                'type' => $type,
                'reason' => $payload['reason'] ?? 'offline adjustment',
                'qty' => $qty,
                'stock_before' => $before,
                'stock_after' => $after,
                'note' => $payload['note'] ?? null,
            ])));
        });

        return $this->result('applied', [
            'server_id' => $adjustment->id,
            'server_uuid' => $adjustment->uuid,
            'server_row' => $this->row($adjustment),
            'message' => "Stock {$type} of {$qty} applied to {$product->name}.",
        ]);
    }
}
