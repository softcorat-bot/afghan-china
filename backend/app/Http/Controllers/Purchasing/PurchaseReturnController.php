<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Return received goods to a supplier — the reverse of receiving. Each
 * returned unit is removed from stock and the supplier's payable is reduced.
 * Only received purchases can be returned; you cannot return more than was
 * received minus what was already returned.
 */
class PurchaseReturnController extends Controller
{
    public function store(Request $request, Purchase $purchase): JsonResponse
    {
        if ($purchase->status !== 'received') {
            return response()->json(['message' => 'Only received purchases can be returned.'], 422);
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_item_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
        ]);

        $purchase->load('items');
        $byId = $purchase->items->keyBy('id');
        $plan = [];
        foreach ($data['items'] as $row) {
            $item = $byId->get($row['purchase_item_id']);
            if (! $item) {
                return response()->json(['message' => 'Line does not belong to this purchase.'], 422);
            }
            $remaining = (float) $item->qty - (float) $item->returned_qty;
            $qty = (float) $row['qty'];
            if ($qty > $remaining + 0.0001) {
                return response()->json(['message' => "Cannot return {$qty} of \"{$item->name}\" — only {$remaining} left."], 422);
            }
            // Can't return stock you no longer physically hold.
            if ($item->product_id) {
                $onHand = (float) (Product::whereKey($item->product_id)->value('stock_qty') ?? 0);
                $track = (bool) (Product::whereKey($item->product_id)->value('track_inventory'));
                if ($track && $qty > $onHand + 0.0001) {
                    return response()->json(['message' => "Not enough stock of \"{$item->name}\" to return (have {$onHand})."], 422);
                }
            }
            $plan[] = [$item, $qty];
        }

        $return = DB::transaction(function () use ($purchase, $plan, $data, $request) {
            $ret = $purchase->returns()->create([
                'company_id' => Tenant::id(),
                'supplier_id' => $purchase->supplier_id,
                'user_id' => $request->user()->id,
                'reference' => $this->nextReference(Tenant::id()),
                'amount' => 0,
                'reason' => $data['reason'] ?? null,
                'note' => $data['note'] ?? null,
            ]);

            $total = 0.0;
            foreach ($plan as [$item, $qty]) {
                $amount = round((float) $item->cost_price * $qty, 2);
                $total += $amount;
                if ($item->product_id) {
                    $p = Product::whereKey($item->product_id)->lockForUpdate()->first();
                    if ($p && $p->track_inventory) {
                        $p->decrement('stock_qty', $qty);
                    }
                }
                $item->increment('returned_qty', $qty);
                $ret->items()->create([
                    'company_id' => Tenant::id(),
                    'purchase_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'qty' => $qty,
                    'amount' => $amount,
                ]);
            }

            $ret->update(['amount' => round($total, 2)]);

            // Reduce what we owe the supplier (never below zero).
            if ($purchase->supplier_id) {
                $bal = (float) (Supplier::whereKey($purchase->supplier_id)->value('balance') ?? 0);
                Supplier::whereKey($purchase->supplier_id)->update(['balance' => round(max(0, $bal - $total), 2)]);
            }

            return $ret;
        });

        ActivityLog::log('created', 'PurchaseReturn', "Returned {$return->amount} on purchase {$purchase->reference}");

        return response()->json($purchase->fresh()->load(['items', 'returns.items', 'supplier:id,name']));
    }

    private function nextReference(int $companyId): string
    {
        $last = PurchaseReturn::where('company_id', $companyId)->orderByDesc('id')->value('reference');
        $n = 0;
        if ($last && preg_match('/(\d+)$/', $last, $m)) {
            $n = (int) $m[1];
        }

        return 'PR-'.str_pad((string) ($n + 1), 6, '0', STR_PAD_LEFT);
    }
}
