<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\SaleItem;
use App\Models\StockAdjustment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Per-product dashboard: lifetime and 30-day performance, purchase cost
 * history, and the full movement history (sales, purchases, adjustments).
 */
class ProductInsightsController extends Controller
{
    public function dashboard(\Illuminate\Http\Request $request, Product $product): JsonResponse
    {
        $product->load('category:id,name');

        // Cost basis via the ONE resolver: owners/finance see the product's
        // real (Main Cost) economics, staff the operational snapshot.
        $ownerCost = \App\Support\EffectiveCost::canView($request->user()) && $product->main_price !== null
            ? (float) $product->main_price
            : null;

        // ── sales performance (voided sales excluded) ──
        $saleLines = SaleItem::where('product_id', $product->id)
            ->whereHas('sale', fn ($q) => $q->where('status', '!=', 'void'))
            ->get();

        $unitsSold = 0.0; $revenue = 0.0; $cost = 0.0; $refundedUnits = 0.0;
        foreach ($saleLines as $l) {
            $unitsSold += (float) $l->qty;
            $refundedUnits += (float) $l->refunded_qty;
            $revenue += ((float) $l->unit_price * (float) $l->qty) - (float) $l->discount;
            $cost += ($ownerCost ?? (float) $l->cost_price) * (float) $l->qty;
        }
        $profit = $revenue - $cost;

        // ── purchases (received only) ──
        $purchLines = PurchaseItem::where('product_id', $product->id)
            ->whereHas('purchase', fn ($q) => $q->where('status', 'received'))
            ->with('purchase:id,reference,purchased_at,supplier_id')
            ->orderByDesc('id')->get();
        $unitsReceived = (float) $purchLines->sum('qty');
        $purchaseCost = (float) $purchLines->sum(fn ($l) => (float) $l->cost_price * (float) $l->qty);

        // ── 30-day daily sales trend ──
        $from = now()->subDays(29)->startOfDay();
        $daily = SaleItem::where('product_id', $product->id)
            ->whereHas('sale', fn ($q) => $q->where('status', '!=', 'void')->where('sold_at', '>=', $from))
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->selectRaw('DATE(sales.sold_at) AS d, SUM(sale_items.qty) AS qty, SUM((sale_items.unit_price*sale_items.qty)-sale_items.discount) AS revenue')
            ->groupBy('d')->orderBy('d')->get()->keyBy('d');
        $trend = [];
        for ($day = $from->copy(); $day <= now(); $day->addDay()) {
            $key = $day->toDateString();
            $row = $daily->get($key);
            $trend[] = ['date' => $key, 'qty' => (float) ($row->qty ?? 0), 'revenue' => round((float) ($row->revenue ?? 0), 2)];
        }

        // ── history feeds ──
        $recentSales = SaleItem::where('product_id', $product->id)
            ->with('sale:id,invoice_no,sold_at,status')
            ->orderByDesc('id')->limit(15)->get()
            ->map(fn ($l) => [
                'invoice_no' => $l->sale?->invoice_no,
                'date' => $l->sale?->sold_at,
                'status' => $l->sale?->status,
                'qty' => (float) $l->qty,
                'unit_price' => (float) $l->unit_price,
                'line_total' => (float) $l->line_total,
                'refunded_qty' => (float) $l->refunded_qty,
            ]);

        $recentPurchases = $purchLines->take(15)->map(fn ($l) => [
            'reference' => $l->purchase?->reference,
            'date' => $l->purchase?->purchased_at,
            'qty' => (float) $l->qty,
            'cost_price' => (float) $l->cost_price,
            'returned_qty' => (float) ($l->returned_qty ?? 0),
        ])->values();

        $recentAdjustments = StockAdjustment::where('product_id', $product->id)
            ->with('user:id,name')
            ->orderByDesc('id')->limit(15)->get()
            ->map(fn ($a) => [
                'date' => $a->created_at,
                'type' => $a->type,
                'qty' => (float) $a->qty,
                'stock_before' => (float) $a->stock_before,
                'stock_after' => (float) $a->stock_after,
                'reason' => $a->reason,
                'user' => $a->user?->name,
            ]);

        return response()->json([
            'product' => $product,
            'stats' => [
                'units_sold' => round($unitsSold, 2),
                'units_refunded' => round($refundedUnits, 2),
                'revenue' => round($revenue, 2),
                'cost_of_sales' => round($cost, 2),
                'profit' => round($profit, 2),
                'margin' => $revenue > 0 ? round(($profit / $revenue) * 100, 1) : 0,
                'units_received' => round($unitsReceived, 2),
                'purchase_cost' => round($purchaseCost, 2),
                'stock_value_cost' => round(($ownerCost ?? (float) $product->cost_price) * (float) $product->stock_qty, 2),
                'stock_value_retail' => round((float) $product->sale_price * (float) $product->stock_qty, 2),
                'last_sold_at' => $saleLines->max('created_at'),
                'last_received_at' => $purchLines->first()?->purchase?->purchased_at,
            ],
            'trend' => $trend,
            'recent_sales' => $recentSales,
            'recent_purchases' => $recentPurchases,
            'recent_adjustments' => $recentAdjustments,
            'base' => 'AFN',
        ]);
    }

    /**
     * The product's complete history, one movement type at a time and paged,
     * so a product with ten thousand sale lines opens as fast as a new one.
     * The dashboard's own feeds stay short; these are the full ledgers.
     */
    public function history(\Illuminate\Http\Request $request, Product $product): JsonResponse
    {
        $type = (string) $request->query('type', 'sales');
        $perPage = min(100, max(10, (int) $request->query('per_page', 25)));

        $page = match ($type) {
            'sales' => SaleItem::where('product_id', $product->id)
                ->with(['sale:id,invoice_no,sold_at,status,customer_id,user_id',
                    'sale.customer:id,name', 'sale.cashier:id,name'])
                ->orderByDesc('id')->paginate($perPage)
                ->through(fn ($l) => [
                    'id' => $l->id,
                    'reference' => $l->sale?->invoice_no,
                    'date' => $l->sale?->sold_at,
                    'status' => $l->sale?->status,
                    'party' => $l->sale?->customer?->name,
                    'user' => $l->sale?->cashier?->name,
                    'qty' => (float) $l->qty,
                    'unit_price' => (float) $l->unit_price,
                    'discount' => (float) $l->discount,
                    'total' => (float) $l->line_total,
                    'refunded_qty' => (float) $l->refunded_qty,
                ]),

            'purchases' => PurchaseItem::where('product_id', $product->id)
                ->with(['purchase:id,reference,purchased_at,status,supplier_id',
                    'purchase.supplier:id,name'])
                ->orderByDesc('id')->paginate($perPage)
                ->through(fn ($l) => [
                    'id' => $l->id,
                    'reference' => $l->purchase?->reference,
                    'date' => $l->purchase?->purchased_at,
                    'status' => $l->purchase?->status,
                    'party' => $l->purchase?->supplier?->name,
                    'qty' => (float) $l->qty,
                    'unit_price' => (float) $l->cost_price,
                    'total' => round((float) $l->cost_price * (float) $l->qty, 2),
                    'returned_qty' => (float) ($l->returned_qty ?? 0),
                ]),

            'stock' => StockAdjustment::where('product_id', $product->id)
                ->with('user:id,name')
                ->orderByDesc('id')->paginate($perPage)
                ->through(fn ($a) => [
                    'id' => $a->id,
                    'date' => $a->created_at,
                    'type' => $a->type,
                    'qty' => (float) $a->qty,
                    'stock_before' => (float) $a->stock_before,
                    'stock_after' => (float) $a->stock_after,
                    'reason' => $a->reason,
                    'location' => $a->location ?? null,
                    'user' => $a->user?->name,
                ]),

            'transfers' => \App\Models\StockTransferItem::where('product_id', $product->id)
                ->with(['transfer:id,reference,direction,source,destination,created_at,user_id',
                    'transfer.user:id,name'])
                ->orderByDesc('id')->paginate($perPage)
                ->through(fn ($i) => [
                    'id' => $i->id,
                    'reference' => $i->transfer?->reference,
                    'date' => $i->transfer?->created_at,
                    'direction' => $i->transfer?->direction,
                    'source' => $i->transfer?->source,
                    'destination' => $i->transfer?->destination,
                    'qty' => (float) $i->qty,
                    'user' => $i->transfer?->user?->name,
                ]),

            // The owner's private Main Price journal — same gate as the desk.
            'price' => \App\Support\EffectiveCost::canView($request->user())
                ? \App\Models\MainPriceLog::where('product_id', $product->id)
                    ->with('user:id,name')
                    ->orderByDesc('id')->paginate($perPage)
                    ->through(fn ($l) => [
                        'id' => $l->id,
                        'date' => $l->created_at,
                        'old_price' => $l->old_price === null ? null : (float) $l->old_price,
                        'new_price' => (float) $l->new_price,
                        'user' => $l->user?->name,
                    ])
                : abort(403, 'Not found.'),

            default => abort(422, "Unknown history type \"{$type}\"."),
        };

        return response()->json($page);
    }
}
