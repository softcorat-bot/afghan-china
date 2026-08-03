<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Retail analytics. All figures are base-currency (AFN) and tenant-scoped by
 * the global CompanyScope. Sales figures exclude voided sales.
 */
class ReportController extends Controller
{
    private function range(Request $request): array
    {
        $to = $request->filled('to') ? Carbon::parse($request->date('to'))->endOfDay() : now()->endOfDay();
        $from = $request->filled('from') ? Carbon::parse($request->date('from'))->startOfDay() : now()->copy()->subDays(29)->startOfDay();

        return [$from, $to];
    }

    /** Sales analytics: totals, daily trend, payment mix, category mix, top products. */
    public function sales(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $saleIds = Sale::where('status', '!=', 'void')
            ->whereBetween('sold_at', [$from, $to])->pluck('id');

        $items = SaleItem::whereIn('sale_id', $saleIds)->get();

        // Cost basis via the ONE resolver: owners/finance compute from the
        // confidential effective cost, staff from the sale-time snapshot.
        $me = $request->user();
        $mainPrices = \App\Support\EffectiveCost::canView($me)
            ? Product::whereNotNull('main_price')->pluck('main_price', 'id')
            : collect();

        $revenue = 0.0;   // net of tax and discount (the money that is margin-bearing)
        $cost = 0.0;
        $units = 0.0;
        foreach ($items as $it) {
            $net = ((float) $it->unit_price * (float) $it->qty) - (float) $it->discount;
            $revenue += $net;
            $unitCost = $it->product_id && isset($mainPrices[$it->product_id])
                ? (float) $mainPrices[$it->product_id]
                : (float) $it->cost_price;
            $cost += $unitCost * (float) $it->qty;
            $units += (float) $it->qty;
        }
        $orders = $saleIds->count();
        $grossTotal = (float) Sale::whereIn('id', $saleIds)->sum('total'); // incl. tax, after discount

        // Daily trend
        $daily = Sale::whereIn('id', $saleIds)
            ->selectRaw('DATE(sold_at) AS d, SUM(total) AS revenue, COUNT(*) AS orders')
            ->groupBy('d')->orderBy('d')->get()
            ->keyBy('d');
        $trend = [];
        for ($day = $from->copy(); $day <= $to; $day->addDay()) {
            $key = $day->toDateString();
            $row = $daily->get($key);
            $trend[] = [
                'date' => $key,
                'revenue' => round((float) ($row->revenue ?? 0), 2),
                'orders' => (int) ($row->orders ?? 0),
            ];
        }

        $byPayment = SalePayment::whereIn('sale_id', $saleIds)
            ->selectRaw('method, SUM(amount) AS amount')
            ->groupBy('method')->orderByDesc('amount')->get();

        $byCategory = SaleItem::whereIn('sale_items.sale_id', $saleIds)
            ->leftJoin('products', 'sale_items.product_id', '=', 'products.id')
            ->leftJoin('product_categories', 'products.category_id', '=', 'product_categories.id')
            ->selectRaw("COALESCE(product_categories.name, 'Uncategorized') AS name, SUM(sale_items.line_total) AS revenue, SUM(sale_items.qty) AS qty")
            ->groupBy(DB::raw("COALESCE(product_categories.name, 'Uncategorized')"))->orderByDesc('revenue')->get();

        $cogsExpr = \App\Support\EffectiveCost::saleItemExpr($me);
        $topProducts = SaleItem::whereIn('sale_items.sale_id', $saleIds)
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->selectRaw("sale_items.name, SUM(sale_items.qty) AS qty, SUM((sale_items.unit_price*sale_items.qty)-sale_items.discount) AS revenue, SUM(((sale_items.unit_price*sale_items.qty)-sale_items.discount) - ({$cogsExpr}*sale_items.qty)) AS profit")
            ->groupBy('sale_items.name')->orderByDesc('revenue')->limit(10)->get();

        return response()->json([
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => [
                'revenue' => round($revenue, 2),
                'gross' => round($grossTotal, 2),
                'cost' => round($cost, 2),
                'profit' => round($revenue - $cost, 2),
                'margin' => $revenue > 0 ? round((($revenue - $cost) / $revenue) * 100, 1) : 0,
                'orders' => $orders,
                'units' => round($units, 2),
                'avg_basket' => $orders > 0 ? round($grossTotal / $orders, 2) : 0,
            ],
            'trend' => $trend,
            'by_payment' => $byPayment,
            'by_category' => $byCategory,
            'top_products' => $topProducts,
            'base' => 'AFN',
        ]);
    }

    /** Inventory analytics: valuation, low/out of stock, valuation by category. */
    public function inventory(Request $request): JsonResponse
    {
        // Valuation runs through the effective-cost resolver.
        $costExpr = \App\Support\EffectiveCost::productExpr($request->user());
        $stockValue = (float) Product::selectRaw("COALESCE(SUM({$costExpr}*stock_qty),0) v")->value('v');
        $retailValue = (float) Product::selectRaw('COALESCE(SUM(sale_price*stock_qty),0) v')->value('v');
        $outOfStock = Product::where('track_inventory', true)->where('stock_qty', '<=', 0)->count();

        $lowStock = Product::where('track_inventory', true)
            ->whereColumn('stock_qty', '<=', 'min_stock')
            ->orderBy('stock_qty')
            ->limit(50)
            ->get(['id', 'name', 'stock_qty', 'min_stock', 'sale_price']);

        $byCategory = Product::leftJoin('product_categories', 'products.category_id', '=', 'product_categories.id')
            ->selectRaw("COALESCE(product_categories.name,'Uncategorized') AS name, COUNT(*) AS items, COALESCE(SUM({$costExpr}*products.stock_qty),0) AS value")
            ->groupBy(DB::raw("COALESCE(product_categories.name,'Uncategorized')"))->orderByDesc('value')->get();

        return response()->json([
            'stock_value' => round($stockValue, 2),
            'retail_value' => round($retailValue, 2),
            'potential_margin' => round($retailValue - $stockValue, 2),
            'products' => Product::count(),
            'out_of_stock' => $outOfStock,
            'low_stock' => $lowStock,
            'by_category' => $byCategory,
            'base' => 'AFN',
        ]);
    }

    /** Supplier payables snapshot. */
    public function suppliers(): JsonResponse
    {
        $rows = Supplier::where('balance', '>', 0)->orderByDesc('balance')->get(['id', 'name', 'phone', 'balance']);

        return response()->json([
            'total_payable' => round((float) Supplier::sum('balance'), 2),
            'suppliers' => $rows,
            'base' => 'AFN',
        ]);
    }
}
