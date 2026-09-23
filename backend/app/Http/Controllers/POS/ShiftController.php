<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CashMovement;
use App\Models\SalePayment;
use App\Models\Shift;
use App\Support\Branch;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Cashier shift / cash-drawer sessions. A cashier opens a shift with a
 * starting float; sales made during it attach to the shift; cash pay-in/out
 * is logged; closing counts the drawer against the expected cash and records
 * the over/short variance. X = live snapshot, Z = the closing report.
 */
class ShiftController extends Controller
{
    /** The caller's currently-open shift (branch-aware) or null. */
    public function current(Request $request): JsonResponse
    {
        $shift = $this->openShiftFor($request);

        return response()->json($shift ? $this->withLive($shift) : null);
    }

    public function index(): JsonResponse
    {
        $shifts = Shift::with('cashier:id,name')->orderByDesc('id')->limit(200)->get();

        return response()->json($shifts);
    }

    public function show(Shift $shift): JsonResponse
    {
        $shift->load(['cashier:id,name', 'movements.user:id,name']);

        return response()->json($shift->status === 'open' ? $this->withLive($shift) : $shift);
    }

    public function open(Request $request): JsonResponse
    {
        $data = $request->validate([
            'opening_float' => ['required', 'numeric', 'min:0'],
            'counter_id' => ['nullable', 'integer', 'exists:counters,id'],
            'note' => ['nullable', 'string'],
        ]);

        if ($this->openShiftFor($request)) {
            return response()->json(['message' => 'A shift is already open.'], 422);
        }

        $shift = Shift::create([
            'company_id' => Tenant::id(),
            'branch_id' => Branch::id(),
            'counter_id' => $data['counter_id'] ?? null,
            'user_id' => $request->user()->id,
            'opened_at' => now(),
            'opening_float' => round((float) $data['opening_float'], 2),
            'status' => 'open',
            'note' => $data['note'] ?? null,
        ]);

        ActivityLog::log('created', 'Shift', "Opened shift #{$shift->id} (float {$shift->opening_float})");

        return response()->json($this->withLive($shift), 201);
    }

    public function movement(Request $request, Shift $shift): JsonResponse
    {
        if ($shift->status !== 'open') {
            return response()->json(['message' => 'Shift is closed.'], 422);
        }
        $data = $request->validate([
            'type' => ['required', 'in:in,out'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string'],
        ]);

        $mv = CashMovement::create([
            'company_id' => Tenant::id(),
            'shift_id' => $shift->id,
            'user_id' => $request->user()->id,
            'type' => $data['type'],
            'amount' => round((float) $data['amount'], 2),
            'reason' => $data['reason'] ?? null,
            'note' => $data['note'] ?? null,
        ]);

        ActivityLog::log('created', 'CashMovement', "Cash {$data['type']} {$mv->amount} on shift #{$shift->id}");

        return response()->json($this->withLive($shift->fresh()));
    }

    /** X report — live figures without closing. */
    public function xreport(Shift $shift): JsonResponse
    {
        return response()->json($this->withLive($shift));
    }

    public function close(Request $request, Shift $shift): JsonResponse
    {
        if ($shift->status !== 'open') {
            return response()->json(['message' => 'Shift is already closed.'], 422);
        }
        $data = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string'],
        ]);

        $shift = DB::transaction(function () use ($shift, $data) {
            $live = $this->liveTotals($shift);
            $counted = round((float) $data['counted_cash'], 2);

            $shift->update([
                'closed_at' => now(),
                'status' => 'closed',
                'counted_cash' => $counted,
                'expected_cash' => $live['expected_cash'],
                'variance' => round($counted - $live['expected_cash'], 2),
                'cash_sales' => $live['cash_sales'],
                'card_sales' => $live['card_sales'],
                'mobile_sales' => $live['mobile_sales'],
                'cash_in' => $live['cash_in'],
                'cash_out' => $live['cash_out'],
                'total_sales' => $live['total_sales'],
                'orders_count' => $live['orders_count'],
                'note' => $data['note'] ?? $shift->note,
            ]);

            return $shift;
        });

        ActivityLog::log('updated', 'Shift', "Closed shift #{$shift->id} (variance {$shift->variance})");

        return response()->json($shift->load('cashier:id,name'));
    }

    // ── helpers ──────────────────────────────────────────────

    private function openShiftFor(Request $request): ?Shift
    {
        return Shift::where('status', 'open')
            ->where('user_id', $request->user()->id)
            ->when(Branch::check(), fn ($q) => $q->where('branch_id', Branch::id()))
            ->latest('id')->first();
    }

    /** Compute live cash-drawer figures for an open shift. */
    private function liveTotals(Shift $shift): array
    {
        $saleIds = $shift->sales()->where('status', '!=', 'void')->pluck('id');

        $byMethod = SalePayment::whereIn('sale_id', $saleIds)
            ->selectRaw('method, SUM(amount) AS amount')->groupBy('method')->pluck('amount', 'method');

        $cashSales = (float) ($byMethod['cash'] ?? 0);
        $cardSales = (float) ($byMethod['card'] ?? 0);
        $mobileSales = (float) ($byMethod['mobile'] ?? 0);

        $cashIn = (float) $shift->movements()->where('type', 'in')->sum('amount');
        $cashOut = (float) $shift->movements()->where('type', 'out')->sum('amount');

        $totalSales = (float) \App\Models\Sale::whereIn('id', $saleIds)->sum('total');

        return [
            'cash_sales' => round($cashSales, 2),
            'card_sales' => round($cardSales, 2),
            'mobile_sales' => round($mobileSales, 2),
            'cash_in' => round($cashIn, 2),
            'cash_out' => round($cashOut, 2),
            'total_sales' => round($totalSales, 2),
            'orders_count' => $saleIds->count(),
            'expected_cash' => round((float) $shift->opening_float + $cashSales + $cashIn - $cashOut, 2),
        ];
    }

    private function withLive(Shift $shift): array
    {
        return array_merge($shift->load(['cashier:id,name', 'movements.user:id,name'])->toArray(), [
            'live' => $this->liveTotals($shift),
        ]);
    }
}
