<?php

namespace App\Http\Controllers\Counter;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Counter;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Support\Performance;
use App\Support\Sql;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Counter seats + their performance analytics. A counter is judged by
 * everything sold ON it (whoever was working); each worker on the counter
 * gets the same scoring so good and slow seats/people surface instantly.
 */
class CounterController extends Controller
{
    public function index(): JsonResponse
    {
        $counters = Counter::orderBy('name')->get();

        $today = Sale::where('status', '!=', 'void')->whereDate('sold_at', today())
            ->whereNotNull('counter_id')
            ->selectRaw('counter_id, SUM(total) AS total, COUNT(*) AS orders')
            ->groupBy('counter_id')->get()->keyBy('counter_id');

        $openShifts = Shift::with('cashier:id,name')->where('status', 'open')
            ->whereNotNull('counter_id')->get()->keyBy('counter_id');

        return response()->json($counters->map(fn (Counter $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'active' => $c->active,
            'sales_today' => round((float) ($today[$c->id]->total ?? 0), 2),
            'orders_today' => (int) ($today[$c->id]->orders ?? 0),
            'operator' => $openShifts->get($c->id)?->cashier?->name,
            'shift_open' => $openShifts->has($c->id),
        ]));
    }

    /** Super Admin adds counter seats. */
    public function store(Request $request): JsonResponse
    {
        $this->assertSuperAdmin($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);

        $counter = Counter::create([
            'company_id' => Tenant::id(),
            'branch_id' => \App\Support\Branch::id(),
            'name' => $data['name'],
            'active' => true,
        ]);
        ActivityLog::log('created', 'Counter', "Added counter \"{$counter->name}\"");

        return response()->json($counter, 201);
    }

    public function update(Request $request, Counter $counter): JsonResponse
    {
        $this->assertSuperAdmin($request);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'active' => ['sometimes', 'boolean'],
        ]);
        $counter->update($data);
        ActivityLog::log('updated', 'Counter', "Updated counter \"{$counter->name}\"");

        return response()->json($counter);
    }

    /** Full analytics for one counter over any date range. */
    public function performance(Request $request, Counter $counter): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $base = Sale::where('status', '!=', 'void')
            ->where('counter_id', $counter->id)
            ->whereBetween('sold_at', [$from, $to]);

        $stats = $this->salesStats(clone $base);

        // Benchmark: average per-counter figures across ALL counters, same window.
        $bench = $this->benchmark(
            Sale::where('status', '!=', 'void')->whereNotNull('counter_id')->whereBetween('sold_at', [$from, $to]),
            'counter_id'
        );
        $stats = array_merge($stats, Performance::score(
            $stats['orders_per_hour'], $stats['revenue_per_hour'], $stats['items_per_order'],
            $bench['oph'], $bench['rph'], $bench['ipo']
        ));

        // Workers who sold on this counter, each scored with the same formula.
        $workers = (clone $base)->whereNotNull('user_id')
            ->join('users', 'users.id', '=', 'sales.user_id')
            ->selectRaw("sales.user_id, users.name,
                COUNT(*) AS orders, SUM(sales.total) AS revenue,
                COUNT(DISTINCT ".Sql::dateFormat('sold_at', '%Y-%m-%d %H').") AS hours,
                MIN(sold_at) AS first_sale, MAX(sold_at) AS last_sale")
            ->groupBy('sales.user_id', 'users.name')->orderByDesc('revenue')->get()
            ->map(function ($w) use ($bench, $from, $to, $counter) {
                $items = (float) SaleItem::join('sales', 'sales.id', '=', 'sale_items.sale_id')
                    ->where('sales.counter_id', $counter->id)->where('sales.user_id', $w->user_id)
                    ->where('sales.status', '!=', 'void')->whereBetween('sales.sold_at', [$from, $to])
                    ->sum('sale_items.qty');
                $hours = max((int) $w->hours, 1);
                $oph = round($w->orders / $hours, 2);
                $rph = round((float) $w->revenue / $hours, 2);
                $ipo = $w->orders > 0 ? round($items / $w->orders, 2) : 0;

                return array_merge([
                    'user_id' => $w->user_id,
                    'name' => $w->name,
                    'orders' => (int) $w->orders,
                    'revenue' => round((float) $w->revenue, 2),
                    'items' => $items,
                    'active_hours' => (int) $w->hours,
                    'orders_per_hour' => $oph,
                    'revenue_per_hour' => $rph,
                    'first_sale' => Carbon::parse($w->first_sale)->format('Y-m-d H:i'),
                    'last_sale' => Carbon::parse($w->last_sale)->format('Y-m-d H:i'),
                ], Performance::score($oph, $rph, $ipo, $bench['oph'], $bench['rph'], $bench['ipo']));
            })->values();

        return response()->json([
            'counter' => $counter,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'stats' => $stats,
            'benchmark' => ['orders_per_hour' => $bench['oph'], 'revenue_per_hour' => $bench['rph'], 'items_per_order' => $bench['ipo']],
            'workers' => $workers,
            'hourly' => $this->hourly(clone $base),
            'trend' => $this->trend(clone $base, $request->query('granularity')),
            'recent' => (clone $base)->with('cashier:id,name')->orderByDesc('sold_at')->limit(15)
                ->get(['id', 'invoice_no', 'user_id', 'total', 'sold_at'])
                ->map(fn ($s) => [
                    'invoice_no' => $s->invoice_no,
                    'user' => $s->cashier?->name,
                    'total' => (float) $s->total,
                    'time' => optional($s->sold_at)->format('Y-m-d H:i'),
                ]),
        ]);
    }

    /* ── shared pieces (also used by user performance) ─────────── */

    public static function statsFor($query): array
    {
        return (new self)->salesStats($query);
    }

    public static function benchmarkFor($query, string $groupCol): array
    {
        return (new self)->benchmark($query, $groupCol);
    }

    public static function hourlyFor($query): array
    {
        return (new self)->hourly($query);
    }

    public static function trendFor($query, ?string $granularity): array
    {
        return (new self)->trend($query, $granularity);
    }

    public static function rangeFor(Request $request): array
    {
        return (new self)->range($request);
    }

    private function range(Request $request): array
    {
        $to = $request->filled('to') ? Carbon::parse($request->query('to'))->endOfDay() : now()->endOfDay();
        $from = $request->filled('from') ? Carbon::parse($request->query('from'))->startOfDay() : now()->startOfDay();

        return [$from, $to];
    }

    private function salesStats($base): array
    {
        $row = (clone $base)->selectRaw("
            COUNT(*) AS orders, COALESCE(SUM(total),0) AS revenue,
            COUNT(DISTINCT ".Sql::dateFormat('sold_at', '%Y-%m-%d %H').") AS hours")->first();

        $items = (float) SaleItem::whereIn('sale_id', (clone $base)->select('id'))->sum('qty');
        $orders = (int) $row->orders;
        $hours = max((int) $row->hours, 1);
        $revenue = (float) $row->revenue;

        return [
            'orders' => $orders,
            'revenue' => round($revenue, 2),
            'items' => $items,
            'avg_ticket' => $orders > 0 ? round($revenue / $orders, 2) : 0,
            'active_hours' => (int) $row->hours,
            'orders_per_hour' => round($orders / $hours, 2),
            'revenue_per_hour' => round($revenue / $hours, 2),
            'items_per_order' => $orders > 0 ? round($items / $orders, 2) : 0,
            'avg_minutes_per_sale' => $orders > 0 ? round(((int) $row->hours) * 60 / $orders, 1) : 0,
        ];
    }

    private function benchmark($query, string $groupCol): array
    {
        // Taken before the lines below, because selectRaw() and groupBy() mutate
        // the builder in place: cloning afterwards would carry `GROUP BY grp`
        // into a query that only selects `id`, and MySQL rejects that outright.
        $saleIds = (clone $query)->reorder()->select('id');

        $rows = $query->selectRaw("{$groupCol} AS grp,
                COUNT(*) AS orders, SUM(total) AS revenue,
                COUNT(DISTINCT ".Sql::dateFormat('sold_at', '%Y-%m-%d %H').") AS hours")
            ->groupBy('grp')->get();

        if ($rows->isEmpty()) {
            return ['oph' => 0, 'rph' => 0, 'ipo' => 0];
        }

        $totalItems = (float) SaleItem::whereIn('sale_id', $saleIds)->sum('qty');
        $totalOrders = max((int) $rows->sum('orders'), 1);

        return [
            'oph' => round($rows->avg(fn ($r) => $r->orders / max((int) $r->hours, 1)), 2),
            'rph' => round($rows->avg(fn ($r) => (float) $r->revenue / max((int) $r->hours, 1)), 2),
            'ipo' => round($totalItems / $totalOrders, 2),
        ];
    }

    private function hourly($base): array
    {
        $rows = (clone $base)->selectRaw(Sql::dateFormat('sold_at', '%H').' AS h, SUM(total) AS total, COUNT(*) AS orders')
            ->groupBy('h')->get();
        $out = array_fill(0, 24, ['total' => 0.0, 'orders' => 0]);
        foreach ($rows as $r) {
            $out[(int) $r->h] = ['total' => round((float) $r->total, 2), 'orders' => (int) $r->orders];
        }

        return $out;
    }

    private function trend($base, ?string $granularity): array
    {
        $fmt = ['daily' => '%Y-%m-%d', 'monthly' => '%Y-%m', 'yearly' => '%Y'][$granularity] ?? '%Y-%m-%d';

        return (clone $base)->selectRaw(Sql::dateFormat('sold_at', $fmt).' AS period, SUM(total) AS revenue, COUNT(*) AS orders')
            ->groupBy('period')->orderBy('period')->get()
            ->map(fn ($r) => ['period' => $r->period, 'revenue' => round((float) $r->revenue, 2), 'orders' => (int) $r->orders])
            ->all();
    }

    private function assertSuperAdmin(Request $request): void
    {
        $u = $request->user();
        abort_unless($u && ((bool) $u->is_super_admin || $u->hasRole('Super Admin') || $u->isPlatformOwner()), 403, 'Reserved to the Super Admin.');
    }
}
