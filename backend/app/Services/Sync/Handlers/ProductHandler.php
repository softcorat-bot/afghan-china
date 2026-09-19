<?php

namespace App\Services\Sync\Handlers;

use App\Models\PosDevice;
use App\Models\Product;
use App\Support\Schema as ColumnGuard;

/**
 * A product created or corrected at a till.
 *
 * Products are master data: the catalogue normally flows server → POS, but a
 * shop that adds an item while offline should not lose it. Pricing fields are
 * mergeable (documented in config/sync.php), stock is *never* pushed here — it
 * arrives as movements — and everything else follows the server's policy.
 */
class ProductHandler extends AbstractHandler
{
    public function entityType(): string
    {
        return 'product';
    }

    public function apply(array $change, PosDevice $device): array
    {
        $payload = $this->payload($change);
        $uuid = (string) $change['uuid'];
        $companyId = $this->companyId($device);

        $existing = Product::withTrashed()->where('uuid', $uuid)->first();

        if (! $existing) {
            if (empty($payload['name'])) {
                return $this->reject('A product needs a name.');
            }

            $product = Product::create(ColumnGuard::only('products', array_merge($this->provenance($device, $change), [
                'company_id' => $companyId,
                'name' => $payload['name'],
                'name_fa' => $payload['name_fa'] ?? null,
                'sku' => $payload['sku'] ?? null,
                'barcode' => $payload['barcode'] ?? null,
                'unit' => $payload['unit'] ?? 'pcs',
                'status' => $payload['status'] ?? 'active',
                'cost_price' => round((float) ($payload['cost_price'] ?? 0), 2),
                'sale_price' => round((float) ($payload['sale_price'] ?? 0), 2),
                'wholesale_price' => isset($payload['wholesale_price']) ? round((float) $payload['wholesale_price'], 2) : null,
                'tax_rate' => round((float) ($payload['tax_rate'] ?? 0), 2),
                'track_inventory' => (bool) ($payload['track_inventory'] ?? true),
                // Opening stock is a movement, not a number: keep the till's
                // figure as a movement so the ledger stays honest.
                'stock_qty' => 0,
                'active' => (bool) ($payload['active'] ?? true),
            ])));

            if (($qty = (float) ($payload['stock_qty'] ?? 0)) > 0) {
                app(StockMovementHandler::class)->apply([
                    'uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'entity_type' => 'stock_movement',
                    'operation' => 'create',
                    'payload' => [
                        'product_uuid' => $product->uuid,
                        'type' => 'increase',
                        'qty' => $qty,
                        'reason' => 'opening_stock',
                        'note' => "Opening stock captured offline on {$device->device_id}",
                    ],
                ], $device);
            }

            return $this->result('applied', [
                'server_id' => $product->id,
                'server_uuid' => $product->uuid,
                'server_row' => $this->row($product->fresh()),
                'message' => "Product {$product->name} created.",
            ]);
        }

        $decision = $this->conflicts->reconcileMaster('product', $existing, $payload, $device, $uuid);

        if ($decision['action'] === 'unchanged') {
            return $this->duplicate($existing, $decision['message'] ?? 'Product is already up to date.');
        }

        if (! empty($decision['payload'])) {
            $existing->fill($decision['payload'])
                ->forceFill(['origin' => 'offline', 'device_id' => $device->device_id, 'synced_at' => now()])
                ->save();
        }

        return $this->result($decision['action'] === 'conflict' ? 'conflict' : 'applied', [
            'server_id' => $existing->id,
            'server_uuid' => $existing->uuid,
            'server_row' => $this->row($existing->fresh()),
            'conflict_id' => $decision['conflict']?->id,
            'message' => $decision['message'] ?? 'Product updated.',
        ]);
    }
}
