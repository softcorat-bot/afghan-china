<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Counter\CounterController;
use App\Models\ActivityLog;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use App\Support\Performance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A person's own dashboard: selling stats + speed score (same formula as
 * counters, benchmarked against the other sellers in the same window),
 * their counters, shifts, and full activity trail (sales, purchases,
 * transfers — everything the log recorded them doing).
 */
class UserPerformanceController extends Controller
{
    public function show(Request $request, User $user): JsonResponse
    {
        abort_unless($user->company_id === \App\Support\Tenant::id(), 404);

        [$from, $to] = CounterController::rangeFor($request);

        $base = Sale::where('status', '!=', 'void')
            ->where('user_id', $user->id)
            ->whereBetween('sold_at', [$from, $to]);

        $stats = CounterController::statsFor(clone $base);

        // Benchmark against every seller in the same window.
        $bench = CounterController::benchmarkFor(
            Sale::where('status', '!=', 'void')->whereNotNull('user_id')->whereBetween('sold_at', [$from, $to]),
            'user_id'
        );
        $stats = array_merge($stats, Performance::score(
            $stats['orders_per_hour'], $stats['revenue_per_hour'], $stats['items_per_order'],
            $bench['oph'], $bench['rph'], $bench['ipo']
        ));

        // Which counters they worked on in the window.
        $counters = (clone $base)->whereNotNull('counter_id')
            ->join('counters', 'counters.id', '=', 'sales.counter_id')
            ->selectRaw('counters.id, counters.name, COUNT(*) AS orders, SUM(sales.total) AS revenue')
            ->groupBy('counters.id', 'counters.name')->orderByDesc('revenue')->get();

        // Everything the log saw them do, split by module.
        $logBase = ActivityLog::where('user_id', $user->id)
            ->whereBetween('created_at', [$from, $to]);
        $byModule = (clone $logBase)->selectRaw('module, COUNT(*) AS n')
            ->groupBy('module')->orderByDesc('n')->get();
        $activities = (clone $logBase)->latest()->limit(40)
            ->get(['id', 'action', 'module', 'description', 'created_at']);

        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'roles' => $user->getRoleNames()],
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'stats' => $stats,
            'benchmark' => ['orders_per_hour' => $bench['oph'], 'revenue_per_hour' => $bench['rph'], 'items_per_order' => $bench['ipo']],
            'counters' => $counters,
            'shifts' => Shift::where('user_id', $user->id)->whereBetween('opened_at', [$from, $to])
                ->orderByDesc('id')->limit(20)
                ->get(['id', 'counter_id', 'opened_at', 'closed_at', 'status', 'total_sales', 'orders_count', 'variance']),
            'by_module' => $byModule,
            'activities' => $activities,
            'hourly' => CounterController::hourlyFor(clone $base),
            'trend' => CounterController::trendFor(clone $base, $request->query('granularity')),
        ]);
    }
}
