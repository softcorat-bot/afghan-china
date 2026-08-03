<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Counter;
use App\Models\CounterEndOfDay;
use App\Models\Expense;
use App\Models\Sale;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Comprehensive Counter & Branch Reports
 * Income, revenue, expenses, net profit per counter/branch
 * With date range filtering: daily, weekly, monthly, yearly, custom dates
 */
class ComprehensiveCounterReportController extends Controller
{
    /**
     * One row per counter — the owner's "every counter, all numbers" table:
     * revenue, real (Main Cost) COGS, its share of branch expenses, net.
     */
    public function allCounters(Request $request): JsonResponse
    {
        // These figures are built on the Main Cost — owner/VIP eyes only.
        abort_unless(\App\Support\EffectiveCost::canView($request->user()), 403, 'Not found.');
        $period = $request->period ?? 'daily';
        $startDate = $this->getStartDate($period, $request->start_date ?? null);
        $endDate = $this->getEndDate($period, $request->end_date ?? null);

        $counters = Counter::withoutGlobalScopes()
            ->where('company_id', Tenant::id())
            ->orderBy('branch_id')->orderBy('name')->get();
        $branchNames = Branch::withoutGlobalScopes()
            ->whereIn('id', $counters->pluck('branch_id')->filter())->pluck('name', 'id');

        $rows = $counters->map(function ($counter) use ($startDate, $endDate, $period, $branchNames) {
            // This overview is the owner's desk (gated above), so it always
            // reads through the real-cost lens.
            $report = $this->buildReport('counter', $counter->id, $startDate, $endDate, $period, true);

            return [
                'counter_id' => $counter->id,
                'counter' => $counter->name,
                'branch' => $branchNames[$counter->branch_id] ?? null,
                'active' => (bool) $counter->active,
                'orders' => $report['sales']['total_orders'],
                'items_sold' => $report['sales']['total_items_sold'],
                'revenue' => $report['sales']['total_revenue'],
                'discount' => $report['sales']['total_discount'],
                'cogs_main' => $report['costs']['total_cogs'],
                'expense_share' => $report['costs']['total_expenses'],
                'gross_profit' => $report['profit']['gross_profit'],
                'net_profit' => $report['profit']['net_profit'],
                'margin_percent' => $report['profit']['profit_margin_percent'],
                'eod' => $report['eod_summary'],
            ];
        })->values();

        return response()->json([
            'period' => [
                'type' => $period,
                'from' => $startDate->toDateString(),
                'to' => $endDate->toDateString(),
            ],
            'counters' => $rows,
            'totals' => [
                'revenue' => (float) $rows->sum('revenue'),
                'cogs_main' => (float) $rows->sum('cogs_main'),
                'expense_share' => (float) $rows->sum('expense_share'),
                'net_profit' => (float) $rows->sum('net_profit'),
                'orders' => (int) $rows->sum('orders'),
            ],
        ]);
    }

    public function counterReport(Request $request, Counter $counter): JsonResponse
    {
        $ownerLens = \App\Support\EffectiveCost::canView($request->user());
        $period = $request->period ?? 'daily'; // daily, weekly, monthly, yearly, lifetime, custom
        $startDate = $this->getStartDate($period, $request->start_date ?? null);
        $endDate = $this->getEndDate($period, $request->end_date ?? null);

        $report = $this->buildReport('counter', $counter->id, $startDate, $endDate, $period, $ownerLens);

        return response()->json([
            'counter' => $counter,
            'period' => [
                'type' => $period,
                'from' => $startDate->toDateString(),
                'to' => $endDate->toDateString(),
            ],
            'report' => $report,
        ]);
    }

    public function branchReport(Request $request, Branch $branch): JsonResponse
    {
        $ownerLens = \App\Support\EffectiveCost::canView($request->user());
        $period = $request->period ?? 'daily';
        $startDate = $this->getStartDate($period, $request->start_date ?? null);
        $endDate = $this->getEndDate($period, $request->end_date ?? null);

        $report = $this->buildReport('branch', $branch->id, $startDate, $endDate, $period, $ownerLens);

        return response()->json([
            'branch' => $branch,
            'period' => [
                'type' => $period,
                'from' => $startDate->toDateString(),
                'to' => $endDate->toDateString(),
            ],
            'report' => $report,
        ]);
    }

    public function companyReport(Request $request): JsonResponse
    {
        $ownerLens = \App\Support\EffectiveCost::canView($request->user());
        $period = $request->period ?? 'monthly';
        $startDate = $this->getStartDate($period, $request->start_date ?? null);
        $endDate = $this->getEndDate($period, $request->end_date ?? null);

        $report = $this->buildReport('company', Tenant::id(), $startDate, $endDate, $period, $ownerLens);

        return response()->json([
            'company_id' => Tenant::id(),
            'period' => [
                'type' => $period,
                'from' => $startDate->toDateString(),
                'to' => $endDate->toDateString(),
            ],
            'report' => $report,
        ]);
    }

    /**
     * Build comprehensive report with all metrics
     */
    private function buildReport(string $type, int $entityId, $startDate, $endDate, string $period, bool $ownerLens = false): array
    {
        // Sales revenue and items
        $salesQuery = Sale::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('company_id', Tenant::id())
            ->whereBetween(DB::raw('DATE(sold_at)'), [$startDate->toDateString(), $endDate->toDateString()]);

        if ($type === 'counter') {
            $salesQuery->where('counter_id', $entityId);
        } elseif ($type === 'branch') {
            $salesQuery->where('branch_id', $entityId);
        }

        $salesData = $salesQuery->selectRaw('
            COUNT(*) as total_orders,
            COUNT(DISTINCT DATE(sold_at)) as days_with_sales,
            SUM(total) as total_revenue,
            SUM(discount) as total_discount,
            SUM(tax) as total_tax,
            AVG(total) as avg_order_value
        ')->first();

        // Count items sold
        $itemsSold = DB::table('sales')
            ->join('sale_items', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.company_id', Tenant::id())
            ->whereBetween(DB::raw('DATE(sales.sold_at)'), [$startDate->toDateString(), $endDate->toDateString()]);

        if ($type === 'counter') {
            $itemsSold = $itemsSold->where('sales.counter_id', $entityId);
        } elseif ($type === 'branch') {
            $itemsSold = $itemsSold->where('sales.branch_id', $entityId);
        }

        $totalItemsCount = $itemsSold->count();

        // Payment methods breakdown
        $paymentMethods = DB::table('sales')
            ->join('sale_payments', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sales.company_id', Tenant::id())
            ->whereBetween(DB::raw('DATE(sales.sold_at)'), [$startDate->toDateString(), $endDate->toDateString()]);

        if ($type === 'counter') {
            $paymentMethods = $paymentMethods->where('sales.counter_id', $entityId);
        } elseif ($type === 'branch') {
            $paymentMethods = $paymentMethods->where('sales.branch_id', $entityId);
        }

        $paymentBreakdown = $paymentMethods->selectRaw('
            sale_payments.method,
            COUNT(*) as count,
            SUM(sale_payments.amount) as amount
        ')->groupBy('sale_payments.method')->get();

        // Expenses
        $expensesQuery = Expense::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('company_id', Tenant::id())
            ->whereBetween(DB::raw('DATE(spent_on)'), [$startDate->toDateString(), $endDate->toDateString()]);

        $counterExpenseShare = 1.0;
        if ($type === 'branch') {
            $expensesQuery->where('branch_id', $entityId);
        } elseif ($type === 'counter') {
            // A counter carries only its DIVIDED share of the branch expenses:
            // proportional to its revenue share in the branch for the period,
            // falling back to an even split across the branch's counters when
            // nothing sold yet (so rent still lands somewhere honest).
            $counterBranch = Counter::withoutGlobalScopes()->find($entityId)?->branch_id;
            if ($counterBranch) {
                $expensesQuery->where('branch_id', $counterBranch);

                $branchRevenue = (float) Sale::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                    ->where('company_id', Tenant::id())
                    ->where('branch_id', $counterBranch)
                    ->whereBetween(DB::raw('DATE(sold_at)'), [$startDate->toDateString(), $endDate->toDateString()])
                    ->sum('total');
                $counterRevenue = (float) Sale::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                    ->where('company_id', Tenant::id())
                    ->where('counter_id', $entityId)
                    ->whereBetween(DB::raw('DATE(sold_at)'), [$startDate->toDateString(), $endDate->toDateString()])
                    ->sum('total');

                if ($branchRevenue > 0) {
                    $counterExpenseShare = $counterRevenue / $branchRevenue;
                } else {
                    $seats = Counter::withoutGlobalScopes()
                        ->where('company_id', Tenant::id())
                        ->where('branch_id', $counterBranch)->count();
                    $counterExpenseShare = $seats > 0 ? 1 / $seats : 1.0;
                }
            }
        }

        $expenseData = $expensesQuery->selectRaw('
            SUM(amount) as total_expense,
            COUNT(*) as expense_count
        ')->first();

        // Cost of Goods Sold (sum of product cost prices sold)
        $cogsQuery = DB::table('sales')
            ->join('sale_items', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'sale_items.product_id', '=', 'products.id')
            ->where('sales.company_id', Tenant::id())
            ->whereBetween(DB::raw('DATE(sales.sold_at)'), [$startDate->toDateString(), $endDate->toDateString()]);

        if ($type === 'counter') {
            $cogsQuery->where('sales.counter_id', $entityId);
        } elseif ($type === 'branch') {
            $cogsQuery->where('sales.branch_id', $entityId);
        }

        // Two lenses on one report: the owner sees the REAL buy price
        // (`main ?? operational`, the resolver every owner-level figure uses);
        // a manager sees the ordinary cost price. Same query, same page —
        // only the cost column differs, so nobody is shown a 403 for a report
        // that is legitimately theirs.
        $costExpr = $ownerLens
            ? 'COALESCE(products.main_price, products.cost_price)'
            : 'products.cost_price';
        $totalCogs = $cogsQuery->selectRaw(
            "SUM({$costExpr} * sale_items.qty) as total_cogs"
        )->value('total_cogs') ?? 0;

        // Counter-specific: End of day reports
        $eodData = null;
        if ($type === 'counter') {
            $eodQuery = CounterEndOfDay::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                ->where('counter_id', $entityId)
                ->whereBetween('report_date', [$startDate->toDateString(), $endDate->toDateString()]);

            $eodData = [
                'submitted_reports' => $eodQuery->count(),
                'average_variance' => $eodQuery->avg('variance'),
                'total_variance' => $eodQuery->sum('variance'),
                'days_with_shortage' => (clone $eodQuery)->where('variance', '<', 0)->count(),
                'days_with_overage' => (clone $eodQuery)->where('variance', '>', 0)->count(),
                'days_balanced' => (clone $eodQuery)->where('variance', 0)->count(),
            ];
        }

        // Calculate metrics
        $revenue = $salesData->total_revenue ?? 0;
        $totalExpense = round((float) ($expenseData->total_expense ?? 0) * $counterExpenseShare, 2);
        $grossProfit = $revenue - $totalCogs;
        $netProfit = $grossProfit - $totalExpense;

        return [
            'sales' => [
                'total_orders' => $salesData->total_orders ?? 0,
                'days_with_sales' => $salesData->days_with_sales ?? 0,
                'total_items_sold' => $totalItemsCount,
                'total_revenue' => (float) $revenue,
                'total_discount' => (float) ($salesData->total_discount ?? 0),
                'total_tax' => (float) ($salesData->total_tax ?? 0),
                'average_order_value' => (float) ($salesData->avg_order_value ?? 0),
            ],
            'payment_methods' => $paymentBreakdown->map(fn ($p) => [
                'method' => $p->method,
                'count' => $p->count,
                'amount' => (float) $p->amount,
            ])->toArray(),
            'costs' => [
                'total_cogs' => (float) $totalCogs,
                'total_expenses' => (float) $totalExpense,
            ],
            'profit' => [
                'gross_profit' => (float) $grossProfit,
                'net_profit' => (float) $netProfit,
                'profit_margin_percent' => $revenue > 0 ? round(($netProfit / $revenue) * 100, 2) : 0,
            ],
            'eod_summary' => $eodData,
            'cost_lens' => $ownerLens ? 'main' : 'operational',
        ];
    }

    /**
     * Get start date based on period
     */
    private function getStartDate(string $period, ?string $customDate = null): \Carbon\Carbon
    {
        return match ($period) {
            'daily' => today(),
            'weekly' => now()->startOfWeek(),
            'monthly' => now()->startOfMonth(),
            'yearly' => now()->startOfYear(),
            'lifetime' => \Carbon\Carbon::parse('2000-01-01'),
            'custom' => \Carbon\Carbon::parse($customDate ?? today()),
            default => today(),
        };
    }

    /**
     * Get end date based on period
     */
    private function getEndDate(string $period, ?string $customDate = null): \Carbon\Carbon
    {
        return match ($period) {
            'daily' => today(),
            'weekly' => now()->endOfWeek(),
            'monthly' => now()->endOfMonth(),
            'yearly' => now()->endOfYear(),
            'lifetime' => today(),
            'custom' => \Carbon\Carbon::parse($customDate ?? today()),
            default => today(),
        };
    }
}
