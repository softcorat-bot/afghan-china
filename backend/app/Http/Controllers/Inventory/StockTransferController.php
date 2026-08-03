<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Warehouse ⇄ shop stock transfers (the Shopify "transfer" flow).
 * `to_store` moves reserve stock onto the shop floor; `to_warehouse`
 * sends shop stock back. Each submit is a numbered TRF document whose
 * lines are snapshotted — that document trail IS the history.
 */
class StockTransferController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $transfers = StockTransfer::with(['user:id,name', 'items:id,stock_transfer_id,product_id,name,barcode,qty'])
            ->when($request->query('direction'), fn ($q, $v) => $q->where('direction', $v))
            ->when($request->query('user_id'), fn ($q, $v) => $q->where('user_id', $v))
            ->when($request->query('from'), fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByDesc('id')->limit(200)->get();

        return response()->json($transfers);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'direction' => ['required', 'in:to_store,to_warehouse,inbound'],
            'source' => ['nullable', 'string', 'max:255'],       // inbound: person / company / country
            'destination' => ['nullable', 'in:warehouse,shop'],  // inbound: where it lands
            'note' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
        ]);

        $inbound = $data['direction'] === 'inbound';
        $toStore = $data['direction'] === 'to_store';
        $destination = $inbound ? ($data['destination'] ?? 'warehouse') : null;
        if ($inbound) {
            abort_unless(trim((string) ($data['source'] ?? '')) !== '', 422, 'Inbound stock needs a source (person, company or country).');
        }

        $transfer = DB::transaction(function () use ($data, $request, $toStore, $inbound, $destination) {
            $lines = [];
            $totalQty = 0.0;

            foreach ($data['items'] as $row) {
                // Row-lock the product so concurrent transfers/sales can't
                // oversubscribe either location.
                $product = Product::whereKey($row['product_id'])->lockForUpdate()->first();
                abort_unless($product, 422, 'Product not found.');

                $qty = round((float) $row['qty'], 3);

                if ($inbound) {
                    // New stock arriving from outside — pure increment.
                    $col = $destination === 'shop' ? 'stock_qty' : 'warehouse_qty';
                    $product->{$col} = (float) $product->{$col} + $qty;
                } else {
                    $source = $toStore ? (float) $product->warehouse_qty : (float) $product->stock_qty;

                    if ($source < $qty) {
                        $where = $toStore ? 'warehouse' : 'shop';
                        abort(422, "Not enough {$where} stock for \"{$product->name}\" (available {$source}, requested {$qty}).");
                    }

                    if ($toStore) {
                        $product->warehouse_qty = $source - $qty;
                        $product->stock_qty = (float) $product->stock_qty + $qty;
                    } else {
                        $product->stock_qty = $source - $qty;
                        $product->warehouse_qty = (float) $product->warehouse_qty + $qty;
                    }
                }
                $product->save();

                $lines[] = [
                    'company_id' => Tenant::id(),
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'barcode' => $product->barcode,
                    'qty' => $qty,
                ];
                $totalQty += $qty;
            }

            $transfer = StockTransfer::create([
                'company_id' => Tenant::id(),
                'branch_id' => \App\Support\Branch::id(),
                'reference' => $this->nextReference(Tenant::id()),
                'direction' => $inbound ? 'inbound' : ($toStore ? 'to_store' : 'to_warehouse'),
                'source' => $inbound ? trim((string) $data['source']) : null,
                'destination' => $destination,
                'user_id' => $request->user()->id,
                'total_qty' => round($totalQty, 3),
                'lines_count' => count($lines),
                'note' => $data['note'] ?? null,
            ]);
            $transfer->items()->createMany($lines);

            return $transfer;
        });

        $dir = $inbound
            ? "inbound from \"{$transfer->source}\" → {$destination}"
            : ($toStore ? 'warehouse → store' : 'store → warehouse');
        ActivityLog::log('created', 'Transfer', "Stock transfer {$transfer->reference} ({$dir}) — {$transfer->total_qty} units in {$transfer->lines_count} lines");

        return response()->json($transfer->load(['items', 'user:id,name']), 201);
    }

    private function nextReference(int $companyId): string
    {
        // Company-wide sequence: must not inherit the active-branch scope.
        $last = StockTransfer::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('company_id', $companyId)->orderByDesc('id')->value('reference');
        $n = $last ? ((int) preg_replace('/\D/', '', $last)) + 1 : 1;

        return 'TRF-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }
}
