<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\Sale;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sales = Sale::with(['customer:id,name', 'cashier:id,name'])
            ->withCount('items')
            ->when($request->filled('search'), function ($qq) use ($request) {
                $s = '%'.$request->string('search').'%';
                $qq->where('invoice_no', 'like', $s);
            })
            ->when($request->filled('status'), fn ($qq) => $qq->where('status', $request->string('status')))
            ->when($request->filled('from'), fn ($qq) => $qq->whereDate('sold_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($qq) => $qq->whereDate('sold_at', '<=', $request->date('to')))
            ->orderByDesc('sold_at')
            ->limit(500)
            ->get();

        return response()->json($sales);
    }

    public function show(Sale $sale): JsonResponse
    {
        return response()->json($sale->load(['items', 'payments', 'customer', 'cashier:id,name', 'refunds.items', 'refunds.user:id,name']));
    }

    /** Today's till summary for the POS header / sales dashboard. */
    public function summary(): JsonResponse
    {
        $today = Sale::where('status', '!=', 'void')->whereDate('sold_at', today());

        return response()->json([
            'count' => (clone $today)->count(),
            'revenue' => round((float) (clone $today)->sum('total'), 2),
            'items' => (int) (clone $today)->withCount('items')->get()->sum('items_count'),
            'base' => 'AFN',
        ]);
    }

    /**
     * Refund a sale — fully or line-by-line. `items: [{sale_item_id, qty}]`
     * refunds those quantities; omit `items` to refund everything remaining.
     * Each refunded unit is restocked; the sale becomes partially_refunded or
     * refunded. All in one transaction so stock, ledger, and status agree.
     */
    public function refund(Request $request, Sale $sale): JsonResponse
    {
        if ($sale->status === 'refunded') {
            return response()->json(['message' => 'Already fully refunded.'], 422);
        }
        if ($sale->status === 'quote') {
            return response()->json(['message' => 'Quotations cannot be refunded.'], 422);
        }
        if ($sale->status === 'void') {
            return response()->json(['message' => 'Sale is void.'], 422);
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string'],
            'items' => ['nullable', 'array'],
            'items.*.sale_item_id' => ['required_with:items', 'integer'],
            'items.*.qty' => ['required_with:items', 'numeric', 'gt:0'],
        ]);

        $sale->load('items');

        // Build the refund plan: {sale_item => qty}. No items = everything left.
        $plan = [];
        if (! empty($data['items'])) {
            $byId = $sale->items->keyBy('id');
            foreach ($data['items'] as $row) {
                $item = $byId->get($row['sale_item_id']);
                if (! $item) {
                    return response()->json(['message' => 'Line does not belong to this sale.'], 422);
                }
                $remaining = (float) $item->qty - (float) $item->refunded_qty;
                $qty = (float) $row['qty'];
                if ($qty > $remaining + 0.0001) {
                    return response()->json(['message' => "Cannot refund {$qty} of \"{$item->name}\" — only {$remaining} left."], 422);
                }
                $plan[] = [$item, $qty];
            }
        } else {
            foreach ($sale->items as $item) {
                $remaining = (float) $item->qty - (float) $item->refunded_qty;
                if ($remaining > 0) {
                    $plan[] = [$item, $remaining];
                }
            }
        }

        if (empty($plan)) {
            return response()->json(['message' => 'Nothing left to refund.'], 422);
        }

        $refund = DB::transaction(function () use ($sale, $plan, $data, $request) {
            $refund = $sale->refunds()->create([
                'company_id' => Tenant::id(),
                'user_id' => $request->user()->id,
                'amount' => 0,
                'reason' => $data['reason'] ?? null,
                'note' => $data['note'] ?? null,
            ]);

            $total = 0.0;
            foreach ($plan as [$item, $qty]) {
                // Per-unit refundable value = line total spread across the units sold.
                $perUnit = (float) $item->qty > 0 ? (float) $item->line_total / (float) $item->qty : 0.0;
                $amount = round($perUnit * $qty, 2);
                $total += $amount;

                if ($item->product_id) {
                    $p = Product::whereKey($item->product_id)->lockForUpdate()->first();
                    if ($p && $p->track_inventory) {
                        $p->increment('stock_qty', $qty);
                    }
                }

                $item->increment('refunded_qty', $qty);
                $refund->items()->create([
                    'company_id' => Tenant::id(),
                    'sale_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'qty' => $qty,
                    'amount' => $amount,
                ]);
            }

            $refund->update(['amount' => round($total, 2)]);
            $sale->increment('refunded_amount', round($total, 2));

            // Recompute status: fully refunded when no unit remains.
            $sale->load('items');
            $fully = $sale->items->every(fn ($i) => (float) $i->refunded_qty >= (float) $i->qty - 0.0001);
            $sale->update(['status' => $fully ? 'refunded' : 'partially_refunded']);

            return $refund;
        });

        ActivityLog::log('updated', 'Sale', "Refunded {$refund->amount} on sale {$sale->invoice_no}");

        return response()->json($sale->fresh()->load(['items', 'refunds.items']));
    }
}
