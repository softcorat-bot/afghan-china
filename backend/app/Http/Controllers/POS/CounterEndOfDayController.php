<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Counter;
use App\Models\CounterEndOfDay;
use App\Models\Sale;
use App\Models\Shift;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Counter End-of-Day Reports: Daily cash reconciliation per counter.
 * Cashier submits counted cash and system calculates variance.
 */
class CounterEndOfDayController extends Controller
{
    /**
     * Get or create today's end-of-day report for a counter.
     * Pre-fills with system data (cash sales, expected cash).
     */
    public function show(Request $request, Counter $counter): JsonResponse
    {
        $today = today();

        $report = CounterEndOfDay::where('counter_id', $counter->id)
            ->whereDate('report_date', $today)
            ->first();

        if (!$report) {
            // Calculate expected cash from today's shift
            $todayShift = Shift::where('counter_id', $counter->id)
                ->whereDate('opened_at', $today)
                ->first();

            $cashSales = Sale::where('counter_id', $counter->id)
                ->whereDate('sold_at', $today)
                ->sum('paid');

            $report = new CounterEndOfDay([
                'counter_id' => $counter->id,
                'company_id' => Tenant::id(),
                'branch_id' => $counter->branch_id,
                'report_date' => $today,
                'opening_float' => $todayShift?->opening_float ?? 0,
                'cash_sales' => $cashSales,
                'expected_cash' => ($todayShift?->opening_float ?? 0) + $cashSales,
                'status' => 'draft',
            ]);
        }

        return response()->json($report);
    }

    /**
     * Submit end-of-day report with counted cash.
     * System calculates variance automatically.
     */
    public function submit(Request $request, Counter $counter): JsonResponse
    {
        $data = $request->validate([
            'opening_float' => 'required|numeric|min:0',
            'counted_cash' => 'required|numeric|min:0',
            'cash_in' => 'nullable|numeric|min:0',
            'cash_out' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        $today = today();

        // Get or create report
        $report = CounterEndOfDay::where('counter_id', $counter->id)
            ->whereDate('report_date', $today)
            ->firstOrNew();

        $report->fill([
            'counter_id' => $counter->id,
            'company_id' => Tenant::id(),
            'branch_id' => $counter->branch_id,
            'report_date' => $today,
            'opening_float' => $data['opening_float'],
            'counted_cash' => $data['counted_cash'],
            'cash_in' => $data['cash_in'] ?? 0,
            'cash_out' => $data['cash_out'] ?? 0,
            'notes' => $data['notes'] ?? null,
            'user_id' => $request->user()?->id,
            'submitted_at' => now(),
            'status' => 'submitted',
        ]);

        // Calculate totals from today's sales
        $todayShift = Shift::where('counter_id', $counter->id)
            ->whereDate('opened_at', $today)
            ->first();

        $salesData = Sale::where('counter_id', $counter->id)
            ->whereDate('sold_at', $today)
            ->selectRaw('
                COALESCE(SUM(total), 0) as total_income,
                COALESCE(SUM(discount), 0) as total_discount
            ')
            ->first();

        $report->opening_float = $data['opening_float'];
        $report->cash_sales = $todayShift?->cash_sales ?? 0;
        $report->card_sales = $todayShift?->card_sales ?? 0;
        $report->mobile_sales = $todayShift?->mobile_sales ?? 0;
        $report->expected_cash = ($data['opening_float'] ?? 0) + ($todayShift?->cash_sales ?? 0);
        $report->total_income = $salesData->total_income ?? 0;
        $report->variance = $report->expected_cash - $data['counted_cash'];

        $report->save();

        ActivityLog::log('created', 'CounterEndOfDay', "Counter {$counter->name} end-of-day submitted, variance: {$report->variance}");

        return response()->json($report);
    }

    /**
     * List end-of-day reports for a counter or branch.
     */
    public function index(Request $request): JsonResponse
    {
        $query = CounterEndOfDay::where('company_id', Tenant::id());

        if ($request->counter_id) {
            $query->where('counter_id', $request->counter_id);
        }

        if ($request->branch_id) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->date_from) {
            $query->whereDate('report_date', '>=', $request->date_from);
        }

        if ($request->date_to) {
            $query->whereDate('report_date', '<=', $request->date_to);
        }

        $reports = $query->with(['counter', 'branch', 'cashier'])
            ->orderBy('report_date', 'desc')
            ->paginate($request->per_page ?? 50);

        return response()->json($reports);
    }

    /**
     * Manager/Admin: Approve a submitted report.
     */
    public function approve(Request $request, CounterEndOfDay $report): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('approve-counter-reports'), 403);

        $report->update(['status' => 'approved']);

        ActivityLog::log('updated', 'CounterEndOfDay', "Counter report approved for {$report->counter->name} on {$report->report_date}");

        return response()->json($report);
    }

    /**
     * Manager/Admin: Reject a submitted report.
     */
    public function reject(Request $request, CounterEndOfDay $report): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('approve-counter-reports'), 403);

        $data = $request->validate(['rejection_reason' => 'required|string|max:1000']);

        $report->update([
            'status' => 'rejected',
            'rejection_reason' => $data['rejection_reason'],
        ]);

        ActivityLog::log('updated', 'CounterEndOfDay', "Counter report rejected for {$report->counter->name}: {$data['rejection_reason']}");

        return response()->json($report);
    }

    /**
     * Get summary statistics for counter performance over a period.
     */
    public function summary(Request $request, Counter $counter): JsonResponse
    {
        $from = $request->date_from ? now()->parse($request->date_from) : now()->startOfMonth();
        $to = $request->date_to ? now()->parse($request->date_to) : now();

        $reports = CounterEndOfDay::where('counter_id', $counter->id)
            ->whereBetween('report_date', [$from->toDateString(), $to->toDateString()])
            ->get();

        $summary = [
            'counter' => $counter,
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'total_days' => $reports->count(),
            'total_income' => $reports->sum('total_income'),
            'total_expenses' => $reports->sum('total_expense'),
            'total_profit' => $reports->sum('net_profit'),
            'total_variance' => $reports->sum('variance'),
            'average_daily_income' => $reports->count() > 0 ? $reports->sum('total_income') / $reports->count() : 0,
            'variance_percentage' => $reports->sum('expected_cash') > 0
                ? ($reports->sum('variance') / $reports->sum('expected_cash') * 100)
                : 0,
            'days_with_shortage' => $reports->where('variance', '<', 0)->count(),
            'days_with_overage' => $reports->where('variance', '>', 0)->count(),
            'days_balanced' => $reports->where('variance', 0)->count(),
        ];

        return response()->json($summary);
    }
}
