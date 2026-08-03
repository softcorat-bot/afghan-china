<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Counter;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\Scopes\BranchScope;
use App\Support\EffectiveCost;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * فایده خالص — the owner's net profit, in one call.
 *
 *     revenue − real cost of what was sold − expenses = net profit
 *
 * "Real cost" means the Main Price the owner keeps privately, falling back to
 * the operational cost where none is set. Expenses are subtracted in full at
 * company and branch level; for a single counter they are apportioned by that
 * counter's share of its branch's revenue, because rent does not belong to one
 * till but the till still has to carry part of it.
 *
 * One endpoint answers every slice the owner asks for — day, month, year,
 * lifetime or a custom range; whole company, one branch, or one counter —
 * so the page can be a single number that changes as they click.
 */
class NetProfitController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless(EffectiveCost::canView($request->user()), 403, 'Not found.');

        $period = (string) ($request->query('period') ?: 'daily');
        [$from, $to] = $this->range($period, $request->query('from'), $request->query('to'));

        $branchId = $request->filled('branch_id') ? (int) $request->query('branch_id') : null;
        $counterId = $request->filled('counter_id') ? (int) $request->query('counter_id') : null;

        $branches = Branch::withoutGlobalScopes()->where('company_id', Tenant::id())
            ->orderBy('name')->get(['id', 'name']);
        $counters = Counter::withoutGlobalScopes()->where('company_id', Tenant::id())
            ->orderBy('branch_id')->orderBy('name')->get(['id', 'name', 'branch_id', 'active']);

        // ── the headline figure, for whatever is selected ──
        $totals = $this->figures($from, $to, $branchId, $counterId);

        // ── per branch, each with its counters underneath ──
        $branchRows = $branches
            ->when($branchId, fn ($c) => $c->where('id', $branchId))
            ->map(function ($b) use ($from, $to, $counters) {
                $row = $this->figures($from, $to, $b->id, null);
                $row['branch_id'] = $b->id;
                $row['branch'] = $b->name;
                $row['counters'] = $counters->where('branch_id', $b->id)->map(function ($c) use ($from, $to) {
                    $r = $this->figures($from, $to, null, $c->id);
                    $r['counter_id'] = $c->id;
                    $r['counter'] = $c->name;
                    $r['active'] = (bool) $c->active;

                    return $r;
                })->values();

                return $row;
            })->values();

        // ── the trend behind the number, so it is not a figure without a story ──
        $trend = $this->trend($period, $from, $to, $branchId, $counterId);

        return response()->json([
            'period' => ['type' => $period, 'from' => $from, 'to' => $to],
            'filters' => ['branch_id' => $branchId, 'counter_id' => $counterId],
            'totals' => $totals,
            'branches' => $branchRows,
            'trend' => $trend,
            'branch_options' => $branches,
            'counter_options' => $counters,
        ]);
    }

    /**
     * Revenue, real cost, expenses and what is left — for a company, a branch
     * or a single counter.
     */
    private function figures(string $from, string $to, ?int $branchId, ?int $counterId): array
    {
        $sales = Sale::withoutGlobalScope(BranchScope::class)
            ->where('company_id', Tenant::id())
            ->where('status', '!=', 'void')
            ->whereBetween(DB::raw('DATE(sold_at)'), [$from, $to]);

        if ($counterId) {
            $sales->where('counter_id', $counterId);
        } elseif ($branchId) {
            $sales->where('branch_id', $branchId);
        }

        $agg = (clone $sales)->selectRaw('
            COUNT(*) as orders,
            COALESCE(SUM(total), 0) as revenue,
            COALESCE(SUM(discount), 0) as discount
        ')->first();

        $revenue = (float) ($agg->revenue ?? 0);

        // Cost of what actually went out of the door, at the owner's real price.
        $cogsQuery = DB::table('sales')
            ->join('sale_items', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'sale_items.product_id', '=', 'products.id')
            ->where('sales.company_id', Tenant::id())
            ->where('sales.status', '!=', 'void')
            ->whereBetween(DB::raw('DATE(sales.sold_at)'), [$from, $to]);

        if ($counterId) {
            $cogsQuery->where('sales.counter_id', $counterId);
        } elseif ($branchId) {
            $cogsQuery->where('sales.branch_id', $branchId);
        }

        $cogs = (float) ($cogsQuery->selectRaw(
            'COALESCE(SUM(COALESCE(products.main_price, products.cost_price) * sale_items.qty), 0) as c'
        )->value('c') ?? 0);

        // Expenses: whole for a company or a branch; a counter carries its share.
        $expenses = $this->expenses($from, $to, $branchId, $counterId);

        $gross = round($revenue - $cogs, 2);
        $net = round($gross - $expenses, 2);

        return [
            'orders' => (int) ($agg->orders ?? 0),
            'revenue' => round($revenue, 2),
            'discount' => round((float) ($agg->discount ?? 0), 2),
            'cogs' => round($cogs, 2),
            'expenses' => $expenses,
            'gross_profit' => $gross,
            'net_profit' => $net,
            'margin' => $revenue > 0 ? round($net / $revenue * 100, 1) : 0.0,
        ];
    }

    private function expenses(string $from, string $to, ?int $branchId, ?int $counterId): float
    {
        $q = Expense::withoutGlobalScope(BranchScope::class)
            ->where('company_id', Tenant::id())
            ->whereBetween(DB::raw('DATE(spent_on)'), [$from, $to]);

        if ($counterId) {
            $counter = Counter::withoutGlobalScopes()->find($counterId);
            if (! $counter?->branch_id) {
                return 0.0;
            }
            $q->where('branch_id', $counter->branch_id);
            $total = (float) $q->sum('amount');

            // Share of the branch's expenses, by share of the branch's takings.
            // With nothing sold yet, split evenly so rent still lands somewhere.
            $branchRevenue = (float) Sale::withoutGlobalScope(BranchScope::class)
                ->where('company_id', Tenant::id())->where('status', '!=', 'void')
                ->where('branch_id', $counter->branch_id)
                ->whereBetween(DB::raw('DATE(sold_at)'), [$from, $to])->sum('total');
            $counterRevenue = (float) Sale::withoutGlobalScope(BranchScope::class)
                ->where('company_id', Tenant::id())->where('status', '!=', 'void')
                ->where('counter_id', $counterId)
                ->whereBetween(DB::raw('DATE(sold_at)'), [$from, $to])->sum('total');

            if ($branchRevenue > 0) {
                return round($total * ($counterRevenue / $branchRevenue), 2);
            }
            $seats = Counter::withoutGlobalScopes()
                ->where('company_id', Tenant::id())
                ->where('branch_id', $counter->branch_id)->count();

            return $seats > 0 ? round($total / $seats, 2) : 0.0;
        }

        if ($branchId) {
            $q->where('branch_id', $branchId);
        }

        return round((float) $q->sum('amount'), 2);
    }

    /** Net profit bucketed over the range, so the headline has a shape behind it. */
    private function trend(string $period, string $from, string $to, ?int $branchId, ?int $counterId): array
    {
        $buckets = match ($period) {
            'daily' => $this->hoursOf($from),
            'monthly' => $this->daysBetween($from, $to),
            'yearly' => $this->monthsBetween($from, $to),
            'lifetime' => $this->monthsBetween($from, $to),
            default => $this->daysBetween($from, $to),
        };

        // Cap the work: a lifetime view over many years still answers quickly.
        if (count($buckets) > 36) {
            $buckets = array_slice($buckets, -36);
        }

        return array_map(function ($b) use ($branchId, $counterId) {
            $f = $this->figures($b['from'], $b['to'], $branchId, $counterId);

            return ['label' => $b['label'], 'net_profit' => $f['net_profit'], 'revenue' => $f['revenue']];
        }, $buckets);
    }

    private function hoursOf(string $day): array
    {
        // A single day reads better as one bar than as 24 mostly-empty ones.
        return [['label' => \Carbon\Carbon::parse($day)->format('d M'), 'from' => $day, 'to' => $day]];
    }

    private function daysBetween(string $from, string $to): array
    {
        $out = [];
        $c = \Carbon\Carbon::parse($from);
        $end = \Carbon\Carbon::parse($to);
        while ($c <= $end) {
            $d = $c->toDateString();
            $out[] = ['label' => $c->format('d'), 'from' => $d, 'to' => $d];
            $c->addDay();
        }

        return $out;
    }

    private function monthsBetween(string $from, string $to): array
    {
        $out = [];
        $c = \Carbon\Carbon::parse($from)->startOfMonth();
        $end = \Carbon\Carbon::parse($to)->endOfMonth();
        while ($c <= $end) {
            $out[] = [
                'label' => $c->format('M y'),
                'from' => $c->copy()->startOfMonth()->toDateString(),
                'to' => $c->copy()->endOfMonth()->toDateString(),
            ];
            $c->addMonth();
        }

        return $out;
    }

    /** @return array{0:string,1:string} */
    private function range(string $period, ?string $from, ?string $to): array
    {
        if ($period === 'custom' && $from && $to) {
            return [$from, $to];
        }

        return match ($period) {
            'daily' => [today()->toDateString(), today()->toDateString()],
            'monthly' => [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()],
            'yearly' => [now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString()],
            'lifetime' => [
                (Sale::withoutGlobalScope(BranchScope::class)->where('company_id', Tenant::id())
                    ->min(DB::raw('DATE(sold_at)')) ?: '2000-01-01'),
                today()->toDateString(),
            ],
            default => [today()->toDateString(), today()->toDateString()],
        };
    }
}
