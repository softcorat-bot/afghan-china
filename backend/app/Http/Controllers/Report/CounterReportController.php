<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Shift;
use App\Models\User;
use App\Support\Sql;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Per-counter daily report — the standard register close-out figures for
 * every counter seat (Counter 1, 2, 3, ...): gross / discounts / refunds /
 * net, orders, items, average ticket, tender breakdown, cash-drawer shifts
 * with over/short, and an hourly activity curve. Voided sales excluded.
 */
class CounterReportController extends Controller
{
    public function daily(Request $request): JsonResponse
    {
        $date = $request->filled('date')
            ? Carbon::parse($request->query('date'))->toDateString()
            : now()->toDateString();

        $companyId = Tenant::id();

        // Every user holding the Counter role — shown even with zero sales —
        // plus anyone else who actually sold that day (e.g. an admin covering).
        $counterIds = User::where('company_id', $companyId)
            ->whereHas('roles', fn ($q) => $q->where('name', 'Counter'))
            ->pluck('id');

        $sellerIds = Sale::where('status', '!=', 'void')
            ->whereDate('sold_at', $date)
            ->whereNotNull('user_id')
            ->distinct()->pluck('user_id');

        $userIds = $counterIds->merge($sellerIds)->unique()->values();
        $users = User::whereIn('id', $userIds)->orderBy('name')->get(['id', 'name']);

        // Bill-level figures per cashier.
        $bills = Sale::where('status', '!=', 'void')
            ->whereDate('sold_at', $date)
            ->selectRaw('user_id, COUNT(*) AS orders, SUM(total) AS gross, SUM(discount) AS discounts, SUM(change_due) AS change_given')
            ->groupBy('user_id')->get()->keyBy('user_id');

        // Units over the counter per cashier.
        $items = SaleItem::join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', '!=', 'void')
            ->whereDate('sales.sold_at', $date)
            ->selectRaw('sales.user_id AS uid, SUM(sale_items.qty) AS units')
            ->groupBy('sales.user_id')->pluck('units', 'uid');

        // Tender split per cashier (cash / card / mobile / credit).
        $tenders = SalePayment::join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sales.status', '!=', 'void')
            ->whereDate('sales.sold_at', $date)
            ->selectRaw('sales.user_id AS uid, sale_payments.method, SUM(sale_payments.amount) AS amt')
            ->groupBy('sales.user_id', 'sale_payments.method')->get()
            ->groupBy('uid');

        // Refunds processed by each cashier that day.
        $refunds = Refund::whereDate('created_at', $date)
            ->selectRaw('user_id, SUM(amount) AS amt, COUNT(*) AS n')
            ->groupBy('user_id')->get()->keyBy('user_id');

        // Drawer sessions opened that day (open ones report live figures).
        $shifts = Shift::whereDate('opened_at', $date)->orderBy('opened_at')->get()->groupBy('user_id');

        // Hourly sales curve per cashier.
        $hourly = Sale::where('status', '!=', 'void')
            ->whereDate('sold_at', $date)
            ->selectRaw("user_id AS uid, ".Sql::dateFormat('sold_at', '%H')." AS h, SUM(total) AS total")
            ->groupBy('uid', 'h')->get()->groupBy('uid');

        $dayNet = 0.0;
        $counters = $users->map(function (User $u) use ($bills, $items, $tenders, $refunds, $shifts, $hourly, $counterIds, &$dayNet) {
            $b = $bills[$u->id] ?? null;
            $gross = (float) ($b->gross ?? 0);
            $orders = (int) ($b->orders ?? 0);
            $refAmt = (float) ($refunds[$u->id]->amt ?? 0);
            $net = $gross - $refAmt;
            $dayNet += $net;

            $tenderMap = ['cash' => 0.0, 'card' => 0.0, 'mobile' => 0.0, 'credit' => 0.0];
            foreach ($tenders[$u->id] ?? [] as $t) {
                $tenderMap[$t->method] = round((float) $t->amt, 2);
            }
            // Cash that stays in the drawer = cash tendered − change handed back.
            $tenderMap['cash'] = round(max(0, $tenderMap['cash'] - (float) ($b->change_given ?? 0)), 2);

            $hourMap = array_fill(0, 24, 0.0);
            foreach ($hourly[$u->id] ?? [] as $h) {
                $hourMap[(int) $h->h] = round((float) $h->total, 2);
            }

            $shiftRows = ($shifts[$u->id] ?? collect())->map(fn (Shift $s) => [
                'id' => $s->id,
                'status' => $s->status,
                'opened_at' => optional($s->opened_at)->format('H:i'),
                'closed_at' => optional($s->closed_at)->format('H:i'),
                'opening_float' => (float) $s->opening_float,
                'expected_cash' => $s->expected_cash !== null ? (float) $s->expected_cash : null,
                'counted_cash' => $s->counted_cash !== null ? (float) $s->counted_cash : null,
                'variance' => $s->variance !== null ? (float) $s->variance : null,
                'cash_in' => (float) $s->cash_in,
                'cash_out' => (float) $s->cash_out,
            ])->values();

            return [
                'user_id' => $u->id,
                'name' => $u->name,
                'is_counter' => $counterIds->contains($u->id),
                'orders' => $orders,
                'items' => (float) ($items[$u->id] ?? 0),
                'gross' => round($gross, 2),
                'discounts' => round((float) ($b->discounts ?? 0), 2),
                'refunds' => round($refAmt, 2),
                'refund_count' => (int) ($refunds[$u->id]->n ?? 0),
                'net' => round($net, 2),
                'avg_ticket' => $orders > 0 ? round($gross / $orders, 2) : 0,
                'tenders' => $tenderMap,
                'shifts' => $shiftRows,
                'shift_open' => $shiftRows->contains(fn ($s) => $s['status'] === 'open'),
                'hourly' => $hourMap,
            ];
        })->values();

        // Share of the day's takings per counter + the day totals.
        $counters = $counters->map(function ($c) use ($dayNet) {
            $c['share_pct'] = $dayNet > 0 ? round($c['net'] / $dayNet * 100, 1) : 0;

            return $c;
        })->sortByDesc('net')->values();

        $totals = [
            'orders' => $counters->sum('orders'),
            'items' => $counters->sum('items'),
            'gross' => round($counters->sum('gross'), 2),
            'discounts' => round($counters->sum('discounts'), 2),
            'refunds' => round($counters->sum('refunds'), 2),
            'net' => round($counters->sum('net'), 2),
            'cash' => round($counters->sum(fn ($c) => $c['tenders']['cash']), 2),
            'card' => round($counters->sum(fn ($c) => $c['tenders']['card']), 2),
            'mobile' => round($counters->sum(fn ($c) => $c['tenders']['mobile']), 2),
            'credit' => round($counters->sum(fn ($c) => $c['tenders']['credit']), 2),
        ];
        $totals['avg_ticket'] = $totals['orders'] > 0 ? round($totals['gross'] / $totals['orders'], 2) : 0;

        return response()->json([
            'date' => $date,
            'totals' => $totals,
            'best' => $counters->first()['user_id'] ?? null,
            'counters' => $counters,
        ]);
    }
}
