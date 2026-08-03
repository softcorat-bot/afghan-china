<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Support\Branch;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Purchases / goods-receiving — the inventory counterpart of the POS sale.
 * A purchase is created as a draft (no stock moved); "receive" adds the
 * quantities to shop stock, refreshes each product's cost price, and bumps
 * the supplier payable — all in one transaction.
 */
class PurchaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $purchases = Purchase::with(['supplier:id,name', 'user:id,name'])
            ->withCount('items')
            ->when($request->filled('search'), fn ($qq) => $qq->where('reference', 'like', '%'.$request->string('search').'%'))
            ->when($request->filled('status'), fn ($qq) => $qq->where('status', $request->string('status')))
            ->orderByDesc('purchased_at')->orderByDesc('id')
            ->limit(500)->get();

        return response()->json($purchases);
    }

    public function show(Purchase $purchase): JsonResponse
    {
        return response()->json($purchase->load(['items', 'supplier', 'user:id,name', 'returns.items']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'supplier_invoice' => ['nullable', 'string', 'max:100'],
            'purchased_at' => ['nullable', 'date'],
            'paid' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.cost_price' => ['required', 'numeric', 'min:0'],
            'items.*.tax' => ['nullable', 'numeric', 'min:0'],
        ]);

        $companyId = Tenant::id();

        $purchase = DB::transaction(function () use ($data, $companyId, $request) {
            $products = Product::whereIn('id', collect($data['items'])->pluck('product_id'))->get()->keyBy('id');

            $subtotal = 0;
            $taxTotal = 0;
            $lines = [];
            foreach ($data['items'] as $row) {
                $p = $products[$row['product_id']];
                $qty = (float) $row['qty'];
                $cost = (float) $row['cost_price'];
                $tax = (float) ($row['tax'] ?? 0);
                $gross = round($cost * $qty, 2);
                $subtotal += $gross;
                $taxTotal += $tax;
                $lines[] = [
                    'company_id' => $companyId,
                    'product_id' => $p->id,
                    'name' => $p->name,
                    'cost_price' => $cost,
                    'qty' => $qty,
                    'tax' => $tax,
                    'line_total' => round($gross + $tax, 2),
                ];
            }

            $discount = (float) ($data['discount'] ?? 0);
            $total = round(max(0, $subtotal - $discount + $taxTotal), 2);

            $purchase = Purchase::create([
                'company_id' => $companyId,
                'branch_id' => Branch::id(),
                'reference' => $this->nextReference($companyId),
                'supplier_invoice' => $data['supplier_invoice'] ?? null,
                'supplier_id' => $data['supplier_id'] ?? null,
                'user_id' => $request->user()->id,
                'purchased_at' => $data['purchased_at'] ?? now()->toDateString(),
                'subtotal' => round($subtotal, 2),
                'discount' => $discount,
                'tax' => round($taxTotal, 2),
                'total' => $total,
                'paid' => round((float) ($data['paid'] ?? 0), 2),
                'status' => 'draft',
                'note' => $data['note'] ?? null,
            ]);
            $purchase->items()->createMany($lines);

            return $purchase;
        });

        ActivityLog::log('created', 'Purchase', "Created purchase {$purchase->reference}");

        return response()->json($purchase->load(['items', 'supplier:id,name']), 201);
    }

    /** Receive a draft: add stock, refresh cost prices, bump supplier payable. */
    public function receive(Purchase $purchase): JsonResponse
    {
        if ($purchase->status === 'received') {
            return response()->json(['message' => 'Already received.'], 422);
        }
        if ($purchase->status === 'cancelled') {
            return response()->json(['message' => 'Purchase is cancelled.'], 422);
        }

        DB::transaction(function () use ($purchase) {
            foreach ($purchase->items()->get() as $item) {
                if (! $item->product_id) {
                    continue;
                }
                $p = Product::whereKey($item->product_id)->lockForUpdate()->first();
                if (! $p) {
                    continue;
                }
                if ($p->track_inventory) {
                    $p->increment('stock_qty', (float) $item->qty);
                }
                // Refresh the product's cost to the latest purchase cost.
                if ((float) $item->cost_price > 0) {
                    $p->cost_price = $item->cost_price;
                    $p->save();
                }
            }

            if ($purchase->supplier_id) {
                $owed = (float) $purchase->total - (float) $purchase->paid;
                if ($owed !== 0.0) {
                    Supplier::whereKey($purchase->supplier_id)->update([
                        'balance' => DB::raw('balance + '.$owed),
                    ]);
                }
            }

            $purchase->update(['status' => 'received']);
        });

        ActivityLog::log('updated', 'Purchase', "Received purchase {$purchase->reference}");

        return response()->json($purchase->fresh()->load(['items', 'supplier:id,name']));
    }

    public function destroy(Purchase $purchase): JsonResponse
    {
        if ($purchase->status === 'received') {
            return response()->json(['message' => 'Cannot delete a received purchase; cancel it instead.'], 422);
        }
        $ref = $purchase->reference;
        $purchase->delete();
        ActivityLog::log('deleted', 'Purchase', "Deleted purchase {$ref}");

        return response()->json(['message' => 'Deleted.']);
    }

    private function nextReference(int $companyId): string
    {
        // Company-wide sequence: must not inherit the active-branch scope.
        $last = Purchase::withTrashed()
            ->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('company_id', $companyId)->orderByDesc('id')->value('reference');
        $n = 0;
        if ($last && preg_match('/(\d+)$/', $last, $m)) {
            $n = (int) $m[1];
        }

        return 'GRN-'.str_pad((string) ($n + 1), 6, '0', STR_PAD_LEFT);
    }
}
