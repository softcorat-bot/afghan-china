<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\MainPriceLog;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Support\Sql;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The owner's private Main Cost desk. `main_price` is the REAL buy price —
 * always below the declared cost_price — and is invisible everywhere else
 * in the system. Access: Platform Owner, or a user the owner explicitly
 * granted the 'main-cost' permission. Nothing here is written to the
 * shared activity log; changes journal into main_price_logs instead.
 */
class MainCostController extends Controller
{
    private function authorizeView(Request $request): void
    {
        abort_unless(\App\Support\EffectiveCost::canView($request->user()), 403, 'Not found.');
    }

    private function authorizeEdit(Request $request): void
    {
        abort_unless(\App\Support\EffectiveCost::canEdit($request->user()), 403, 'Not found.');
    }

    /**
     * The Financial Mirror: the ONE products table viewed through the
     * owner's cost lens. Paginated so 100k products behave like 100.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeView($request);

        $q = Product::with('category:id,name')->orderBy('name');

        if ($s = trim((string) $request->query('search'))) {
            $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$s}%")
                ->orWhere('name_fa', 'like', "%{$s}%")
                ->orWhere('sku', 'like', "%{$s}%")
                ->orWhere('brand', 'like', "%{$s}%")
                ->orWhere('barcode', 'like', "%{$s}%"));
        }
        if ($cid = $request->query('category_id')) {
            $q->where('category_id', $cid);
        }
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($request->boolean('only_missing')) {
            $q->whereNull('main_price');
        }

        // The mirror table paginates client-side (like the Products page), so
        // the ceiling allows one full-catalog fetch for this owner-only route.
        $perPage = min(max((int) ($request->query('per_page') ?: 25), 5), 10000);
        $page = $q->paginate($perPage);
        $products = collect($page->items());

        // Latest main-price change per product (who touched it, when).
        $latestLogs = MainPriceLog::with('user:id,name')
            ->whereIn('id', function ($sub) use ($products) {
                $sub->selectRaw('MAX(id)')->from('main_price_logs')
                    ->whereIn('product_id', $products->pluck('id'))
                    ->groupBy('product_id');
            })->get()->keyBy('product_id');

        $rows = $products->map(function (Product $p) use ($latestLogs) {
            $main = $p->main_price !== null ? (float) $p->main_price : null;
            $operational = (float) $p->cost_price;
            $sale = (float) $p->sale_price;
            $effective = $main ?? $operational;   // the owner never sees an empty cost
            $log = $latestLogs[$p->id] ?? null;

            return [
                'id' => $p->id,
                'name' => $p->name,
                'name_fa' => $p->name_fa,
                'sku' => $p->sku,
                'barcode' => $p->barcode,
                'category' => $p->category?->name,
                'brand' => $p->brand,
                'unit' => $p->unit,
                'status' => $p->status,
                'image_url' => $p->image_url,
                'stock_qty' => (float) $p->stock_qty,
                'warehouse_qty' => (float) $p->warehouse_qty,
                'sale_price' => $sale,
                'cost_price' => $operational,
                'main_price' => $main,
                'effective_cost' => $effective,
                'is_fallback' => $main === null,
                'difference' => round($operational - $effective, 2),
                'actual_margin' => $sale > 0 ? round(($sale - $effective) / $sale * 100, 1) : 0,
                'operational_margin' => $sale > 0 ? round(($sale - $operational) / $sale * 100, 1) : 0,
                'updated_at' => $log?->created_at?->toDateTimeString(),
                'updated_by' => $log?->user?->name,
            ];
        });

        // Fleet-wide stats over the WHOLE filtered-less catalog (cheap aggregates).
        $agg = Product::selectRaw('
                COUNT(*) AS total,
                SUM(CASE WHEN main_price IS NOT NULL THEN 1 ELSE 0 END) AS priced,
                COALESCE(SUM(cost_price * stock_qty), 0) AS declared_stock_value,
                COALESCE(SUM(COALESCE(main_price, cost_price) * stock_qty), 0) AS real_stock_value')
            ->first();

        return response()->json([
            'products' => $rows->values(),
            'pagination' => [
                'page' => $page->currentPage(),
                'per_page' => $perPage,
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
            'can_edit' => \App\Support\EffectiveCost::canEdit($request->user()),
            'stats' => [
                'total' => (int) $agg->total,
                'priced' => (int) $agg->priced,
                'unpriced' => (int) $agg->total - (int) $agg->priced,
                'declared_stock_value' => round((float) $agg->declared_stock_value, 2),
                'real_stock_value' => round((float) $agg->real_stock_value, 2),
                'hidden_stock_value' => round((float) $agg->declared_stock_value - (float) $agg->real_stock_value, 2),
            ],
        ]);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->authorizeEdit($request);

        $data = $request->validate([
            'main_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $old = $product->main_price !== null ? (float) $product->main_price : null;
        $new = $data['main_price'] !== null ? round((float) $data['main_price'], 2) : null;

        $product->forceFill(['main_price' => $new])->save();

        if ($new !== null && $new !== $old) {
            MainPriceLog::create([
                'company_id' => Tenant::id(),
                'product_id' => $product->id,
                'user_id' => $request->user()->id,
                'old_price' => $old,
                'new_price' => $new,
            ]);
        }

        return response()->json([
            'id' => $product->id,
            'main_price' => $new,
            'cost_price' => (float) $product->cost_price,
            'revenue_delta' => $new !== null ? round((float) $product->cost_price - $new, 2) : null,
        ]);
    }

    /**
     * Real-profit report. Revenue comes from the bills; declared cost from
     * the sale-time cost snapshots; real cost from today's main prices
     * (falling back to declared where unset). Grouped by period and,
     * separately, by cashier per period ("history of each person").
     */
    /**
     * The owner's view of the period report: identical to the one every
     * manager sees, with the real-cost columns added.
     */
    public function report(Request $request): JsonResponse
    {
        $this->authorizeView($request);

        return $this->periodReport($request, true);
    }

    /**
     * THE period report. The query already computes both cost bases in one
     * pass — declared (the normal cost every manager sees) and real (the
     * owner's Main Price) — so the VIP report and the ordinary report are the
     * same report, and the only difference is whether the real-cost columns
     * are handed over. That is what keeps the two from ever disagreeing.
     */
    public function periodReport(Request $request, bool $withMainCost): JsonResponse
    {
        $granularity = in_array($request->query('granularity'), ['daily', 'weekly', 'monthly', 'yearly'], true)
            ? $request->query('granularity') : 'daily';
        // Weekly buckets by their Monday rather than a week number — see Sql::weekStart().
        $fmt = ['daily' => '%Y-%m-%d', 'weekly' => null, 'monthly' => '%Y-%m', 'yearly' => '%Y'][$granularity];
        $bucket = fn (string $column) => $granularity === 'weekly'
            ? Sql::weekStart($column)
            : Sql::dateFormat($column, $fmt);

        // A bare Monday reads as a day, so weekly periods are labelled back into
        // "2026-W30" for display. Grouping stays on the date; only the label changes.
        // The year is the ISO year (`o`), not the calendar year — the Monday of
        // ISO week 1 can fall in December, and `Y` would label it "2025-W01".
        $label = fn (string $period) => $granularity === 'weekly'
            ? Carbon::parse($period)->format('o-\WW')
            : $period;

        $from = $request->query('from') ?: now()->subDays(30)->toDateString();
        $to = $request->query('to') ?: now()->toDateString();
        $userId = $request->query('user_id');
        $productId = $request->query('product_id');

        // Bill-level revenue per period (+ per cashier per period).
        $billBase = Sale::query()
            ->where('status', '!=', 'void')
            ->whereDate('sold_at', '>=', $from)
            ->whereDate('sold_at', '<=', $to)
            ->when($userId, fn ($w) => $w->where('user_id', $userId))
            ->when($productId, fn ($w) => $w->whereHas('items', fn ($i) => $i->where('product_id', $productId)));

        // Item-level costs per period. Net of refunded quantities.
        $itemBase = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.status', '!=', 'void')
            ->whereDate('sales.sold_at', '>=', $from)
            ->whereDate('sales.sold_at', '<=', $to)
            ->when($userId, fn ($w) => $w->where('sales.user_id', $userId))
            ->when($productId, fn ($w) => $w->where('sale_items.product_id', $productId));

        $costSelect = '
            SUM(sale_items.cost_price * (sale_items.qty - sale_items.refunded_qty)) AS declared_cost,
            SUM(COALESCE(products.main_price, sale_items.cost_price) * (sale_items.qty - sale_items.refunded_qty)) AS real_cost';

        $mergePeriods = function ($bills, $costs) {
            $out = [];
            foreach ($bills as $b) {
                $out[$b->period] = [
                    'period' => $b->period,
                    'revenue' => round((float) $b->revenue, 2),
                    'orders' => (int) $b->orders,
                    'declared_cost' => 0.0, 'real_cost' => 0.0,
                ];
            }
            foreach ($costs as $c) {
                $out[$c->period] ??= ['period' => $c->period, 'revenue' => 0.0, 'orders' => 0, 'declared_cost' => 0.0, 'real_cost' => 0.0];
                $out[$c->period]['declared_cost'] = round((float) $c->declared_cost, 2);
                $out[$c->period]['real_cost'] = round((float) $c->real_cost, 2);
            }
            ksort($out);
            return array_values(array_map(function ($r) {
                $r['declared_profit'] = round($r['revenue'] - $r['declared_cost'], 2);
                $r['real_profit'] = round($r['revenue'] - $r['real_cost'], 2);
                $r['hidden_margin'] = round($r['declared_cost'] - $r['real_cost'], 2);
                return $r;
            }, $out));
        };

        $periods = $mergePeriods(
            (clone $billBase)->selectRaw($bucket('sold_at').' AS period, SUM(total) AS revenue, COUNT(*) AS orders')
                ->groupBy('period')->get(),
            (clone $itemBase)->selectRaw($bucket('sales.sold_at')." AS period, {$costSelect}")
                ->groupBy('period')->get()
        );

        // Per-person, per-period history.
        $billsByUser = (clone $billBase)
            ->leftJoin('users', 'users.id', '=', 'sales.user_id')
            ->selectRaw($bucket('sold_at')." AS period, sales.user_id, COALESCE(users.name, '—') AS user_name, SUM(total) AS revenue, COUNT(*) AS orders")
            ->groupBy('period', 'sales.user_id', 'users.name')->get();
        $costsByUser = (clone $itemBase)
            ->selectRaw($bucket('sales.sold_at')." AS period, sales.user_id, {$costSelect}")
            ->groupBy('period', 'sales.user_id')->get();

        $byUser = [];
        foreach ($billsByUser as $b) {
            $key = $b->period.'|'.($b->user_id ?? 0);
            $byUser[$key] = [
                'period' => $b->period, 'user_id' => $b->user_id, 'user_name' => $b->user_name,
                'revenue' => round((float) $b->revenue, 2), 'orders' => (int) $b->orders,
                'declared_cost' => 0.0, 'real_cost' => 0.0,
            ];
        }
        foreach ($costsByUser as $c) {
            $key = $c->period.'|'.($c->user_id ?? 0);
            if (! isset($byUser[$key])) {
                continue;
            }
            $byUser[$key]['declared_cost'] = round((float) $c->declared_cost, 2);
            $byUser[$key]['real_cost'] = round((float) $c->real_cost, 2);
        }
        $byUser = array_values(array_map(function ($r) {
            $r['declared_profit'] = round($r['revenue'] - $r['declared_cost'], 2);
            $r['real_profit'] = round($r['revenue'] - $r['real_cost'], 2);
            $r['hidden_margin'] = round($r['declared_cost'] - $r['real_cost'], 2);
            return $r;
        }, $byUser));
        usort($byUser, fn ($a, $b) => [$b['period'], $b['revenue']] <=> [$a['period'], $a['revenue']]);

        // Relabel only once both lists are sorted, so ordering stays on the key.
        $periods = array_map(function ($r) use ($label) {
            $r['period'] = $label($r['period']);
            return $r;
        }, $periods);
        $byUser = array_map(function ($r) use ($label) {
            $r['period'] = $label($r['period']);
            return $r;
        }, $byUser);

        $summary = [
            'revenue' => round(array_sum(array_column($periods, 'revenue')), 2),
            'orders' => array_sum(array_column($periods, 'orders')),
            'declared_cost' => round(array_sum(array_column($periods, 'declared_cost')), 2),
            'real_cost' => round(array_sum(array_column($periods, 'real_cost')), 2),
        ];
        $summary['declared_profit'] = round($summary['revenue'] - $summary['declared_cost'], 2);
        $summary['real_profit'] = round($summary['revenue'] - $summary['real_cost'], 2);
        $summary['hidden_margin'] = round($summary['declared_cost'] - $summary['real_cost'], 2);

        // Without the Main Cost grant the real-cost columns never leave the
        // server — the shape stays the same, the confidential figures do not.
        if (! $withMainCost) {
            $hide = ['real_cost', 'real_profit', 'hidden_margin'];
            $strip = fn (array $row) => array_diff_key($row, array_flip($hide));
            $summary = $strip($summary);
            $periods = array_map($strip, $periods);
            $byUser = array_map($strip, $byUser);
        }

        return response()->json([
            'granularity' => $granularity,
            'from' => $from,
            'to' => $to,
            'with_main_cost' => $withMainCost,
            'summary' => $summary,
            'periods' => $periods,
            'by_user' => $byUser,
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $this->authorizeView($request);

        $logs = MainPriceLog::with(['user:id,name', 'product:id,name,sku,barcode'])
            ->when($request->query('user_id'), fn ($w, $v) => $w->where('user_id', $v))
            ->when($request->query('product_id'), fn ($w, $v) => $w->where('product_id', $v))
            ->when($request->query('from'), fn ($w, $v) => $w->whereDate('created_at', '>=', $v))
            ->when($request->query('to'), fn ($w, $v) => $w->whereDate('created_at', '<=', $v))
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        return response()->json($logs);
    }
}
