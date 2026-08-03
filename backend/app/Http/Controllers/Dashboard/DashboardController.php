<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Support\Sql;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Retail dashboard fed by live POS / catalog data: today's takings, the
 * catalog size, low-stock alerts, plus the platform figures (users,
 * branches, activity feed). No fake numbers — zero where there is no data.
 */
class DashboardController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $companyId = Tenant::id();

        $todaySales = Sale::where('status', '!=', 'void')->whereDate('sold_at', today());
        $salesToday = (float) (clone $todaySales)->sum('total');
        $ordersToday = (clone $todaySales)->count();

        // Personal register figures — the Counter dashboard hero runs on these.
        $me = $request->user();
        $mine = (clone $todaySales)->where('user_id', $me->id);
        $mySalesToday = (float) (clone $mine)->sum('total');
        $myOrdersToday = (clone $mine)->count();
        $myOpenShift = \App\Models\Shift::where('user_id', $me->id)
            ->where('status', 'open')
            ->first(['id', 'opened_at', 'opening_float']);

        $productsTotal = Product::count();
        $lowStock = Product::where('track_inventory', true)->whereColumn('stock_qty', '<=', 'min_stock')->count();

        // One cost resolver for the whole system: owners/finance see the
        // real (Main Cost) basis, everyone else the operational basis.
        $costExpr = \App\Support\EffectiveCost::productExpr($me);
        $stockValue = (float) Product::selectRaw("COALESCE(SUM({$costExpr} * stock_qty), 0) AS v")->value('v');

        // The live feed shows everyone's actions — only for roles that hold
        // the log permission (a cashier sees their own register, not the org).
        $seesFeed = $me->can('log-list') || (bool) $me->is_super_admin || $me->isPlatformOwner();
        $recentActivity = $seesFeed
            ? ActivityLog::with('user')
                ->where('company_id', $companyId)
                ->latest()->limit(10)
                ->get(['id', 'user_id', 'action', 'module', 'description', 'created_at'])
            : collect();

        // Top sellers over the last 30 days (by quantity) for the leaderboard.
        $topProducts = \App\Models\SaleItem::query()
            ->whereHas('sale', fn ($q) => $q->where('status', '!=', 'void')->whereDate('sold_at', '>=', now()->subDays(30)->toDateString()))
            ->selectRaw('product_id, MAX(name) AS name, SUM(qty) AS qty, SUM(line_total) AS revenue')
            ->groupBy('product_id')->orderByDesc('qty')->limit(8)->get();

        // Sales over the last 7 days (zero-filled) for the trend chart.
        $trendRaw = Sale::where('status', '!=', 'void')
            ->whereDate('sold_at', '>=', now()->subDays(6)->toDateString())
            ->selectRaw(Sql::dateFormat('sold_at', '%Y-%m-%d').' AS d, SUM(total) AS total, COUNT(*) AS orders')
            ->groupBy('d')->pluck('total', 'd');
        $salesTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = now()->subDays($i)->toDateString();
            $salesTrend[] = ['date' => $d, 'total' => round((float) ($trendRaw[$d] ?? 0), 2)];
        }

        // Sales over time at whatever grain the owner asked for. Zero-filled,
        // because a gap in a bar chart reads as "no data loaded" rather than
        // "nothing sold that day".
        $salesOverTime = $this->salesOverTime((string) $request->query('range', 'day'));

        // Today's real takings vs cost of goods sold → net profit + margin.
        // COGS runs through the effective-cost resolver: authorized viewers
        // get the confidential basis, staff get the operational snapshot.
        $cogsExpr = \App\Support\EffectiveCost::saleItemExpr($me);
        $cogsToday = (float) \App\Models\SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.status', '!=', 'void')
            ->whereDate('sales.sold_at', today())
            ->selectRaw("COALESCE(SUM({$cogsExpr} * (sale_items.qty - sale_items.refunded_qty)), 0) AS v")->value('v');
        $netProfitToday = $salesToday - $cogsToday;

        // Latest bills for the Recent Sales card (quotes are not sales).
        $recentSales = Sale::withCount('items')
            ->whereNotIn('status', ['void', 'quote'])
            ->orderByDesc('sold_at')->limit(8)
            ->get(['id', 'invoice_no', 'total', 'sold_at', 'status'])
            ->map(fn ($s) => [
                'id' => $s->id,
                'invoice_no' => $s->invoice_no,
                'total' => (float) $s->total,
                'items' => $s->items_count,
                'sold_at' => optional($s->sold_at)->format('H:i'),
                'date' => optional($s->sold_at)->toDateString(),
            ]);

        // Products at or below their minimum, worst first.
        $lowStockList = Product::where('track_inventory', true)
            ->whereColumn('stock_qty', '<=', 'min_stock')
            ->orderBy('stock_qty')->limit(10)
            ->get(['id', 'name', 'stock_qty', 'min_stock', 'unit']);

        // Catalog split by category, with each one's share of the whole store —
        // by product count, by stock value, and by what it actually sold in the
        // last 30 days, because "biggest category" means different things.
        $catRows = Product::query()
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->selectRaw("products.category_id AS id, COALESCE(product_categories.name, 'Uncategorized') AS name,
                         COUNT(*) AS count,
                         COALESCE(SUM(products.stock_qty * products.sale_price), 0) AS stock_value")
            ->groupBy('products.category_id')
            ->groupByRaw("COALESCE(product_categories.name, 'Uncategorized')")
            ->orderByDesc('count')->get();

        $soldByCat = \App\Models\SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.status', '!=', 'void')
            ->whereDate('sales.sold_at', '>=', now()->subDays(30)->toDateString())
            ->selectRaw('products.category_id AS id, SUM(sale_items.line_total) AS revenue')
            ->groupBy('products.category_id')->pluck('revenue', 'id');

        $catTotalCount = max(1, (int) $catRows->sum('count'));
        $catTotalValue = max(0.01, (float) $catRows->sum('stock_value'));
        $catTotalSold = max(0.01, (float) $soldByCat->sum());

        $categoryBreakdown = $catRows->map(fn ($r) => [
            'id' => $r->id,
            'name' => $r->name,
            'count' => (int) $r->count,
            'stock_value' => round((float) $r->stock_value, 2),
            'revenue_30d' => round((float) ($soldByCat[$r->id] ?? 0), 2),
            // Share of the entire store, so the bars add up to 100%.
            'share_count' => round(((int) $r->count) / $catTotalCount * 100, 1),
            'share_value' => round((float) $r->stock_value / $catTotalValue * 100, 1),
            'share_sold' => round((float) ($soldByCat[$r->id] ?? 0) / $catTotalSold * 100, 1),
        ])->values();

        return response()->json([
            'my_sales_today' => round($mySalesToday, 2),
            'my_orders_today' => $myOrdersToday,
            'my_open_shift' => $myOpenShift,
            'sales_today' => round($salesToday, 2),
            'orders_today' => $ordersToday,
            'products_total' => $productsTotal,
            'stock_alerts' => $lowStock,
            'stock_value' => round($stockValue, 2),
            'total_branches' => Branch::where('company_id', $companyId)->count(),
            'total_users' => User::where('company_id', $companyId)->count(),
            'net_profit_today' => round($netProfitToday, 2),
            'margin_today' => $salesToday > 0 ? round($netProfitToday / $salesToday * 100, 1) : 0,
            'sales_trend' => $salesTrend,
            'sales_over_time' => $salesOverTime,
            'recent_expenses' => \App\Models\Expense::with('user:id,name')
                ->orderByDesc('spent_on')->orderByDesc('id')->limit(8)->get()
                ->map(fn ($e) => [
                    'id' => $e->id,
                    'date' => optional($e->spent_on)->toDateString(),
                    'category' => $e->category,
                    'payee' => $e->payee,
                    'amount' => (float) $e->amount,
                    'method' => $e->method,
                ]),
            'expenses_month' => round((float) \App\Models\Expense::query()
                ->whereBetween('spent_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                ->sum('amount'), 2),
            'recent_sales' => $recentSales,
            'low_stock_list' => $lowStockList,
            'category_breakdown' => $categoryBreakdown,
            'top_products' => $topProducts,
            'recent_activity' => $recentActivity,
            'base' => 'AFN',
        ]);
    }

    /**
     * Sales over time at one of four grains. Every bucket in the window is
     * emitted even when it sold nothing — a gap in a bar chart reads as
     * "the data did not load", which is a different and much worse message.
     *
     * @return array<int, array{label:string, total:float, orders:int}>
     */
    private function salesOverTime(string $range): array
    {
        // grain => [strftime format, how many buckets back, how to step, label format]
        $spec = match ($range) {
            // Weeks group by their Monday, not a week number — see Sql::weekStart().
            'week' => [null, 12, 'subWeeks', 'W'],
            'month' => ['%Y-%m', 12, 'subMonths', 'M'],
            'year' => ['%Y', 5, 'subYears', 'Y'],
            default => ['%Y-%m-%d', 14, 'subDays', 'D'],
        };
        [$fmt, $buckets, $step, $kind] = $spec;

        $from = match ($kind) {
            'W' => now()->copy()->subWeeks($buckets - 1)->startOfWeek(),
            'M' => now()->copy()->subMonths($buckets - 1)->startOfMonth(),
            'Y' => now()->copy()->subYears($buckets - 1)->startOfYear(),
            default => now()->copy()->subDays($buckets - 1)->startOfDay(),
        };

        $bucket = $kind === 'W' ? Sql::weekStart('sold_at') : Sql::dateFormat('sold_at', $fmt);

        $rows = Sale::where('status', '!=', 'void')
            ->where('sold_at', '>=', $from)
            ->selectRaw($bucket.' AS k, SUM(total) AS total, COUNT(*) AS orders')
            ->groupBy('k')->get()->keyBy('k');

        $out = [];
        $cursor = $from->copy();
        for ($i = 0; $i < $buckets; $i++) {
            $key = match ($kind) {
                'W' => $cursor->copy()->startOfWeek()->format('Y-m-d'),
                'M' => $cursor->format('Y-m'),
                'Y' => $cursor->format('Y'),
                default => $cursor->toDateString(),
            };
            $row = $rows->get($key);
            $out[] = [
                'key' => $key,
                // Short human label: "07-25", "W30", "Jul", "2026".
                'label' => match ($kind) {
                    'W' => 'W'.$cursor->format('W'),
                    'M' => $cursor->format('M'),
                    'Y' => $cursor->format('Y'),
                    default => $cursor->format('m-d'),
                },
                'total' => round((float) ($row->total ?? 0), 2),
                'orders' => (int) ($row->orders ?? 0),
            ];
            match ($kind) {
                'W' => $cursor->addWeek(),
                'M' => $cursor->addMonth(),
                'Y' => $cursor->addYear(),
                default => $cursor->addDay(),
            };
        }

        return $out;
    }
}
