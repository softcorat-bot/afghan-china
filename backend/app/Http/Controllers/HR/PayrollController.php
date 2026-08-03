<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AttendanceRecord;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Monthly payroll runs over the staff (users). Generate builds a draft
 * from salaries + the month's attendance (absence deduction prefilled at
 * basic/30 per missed day); every component stays editable until the run
 * is marked paid. Modeled on Aria Herat's payroll.
 */
class PayrollController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            PayrollRun::withCount('items')->withSum('items as net_total', 'net')
                ->orderByDesc('period')->get()
        );
    }

    public function generate(Request $request): JsonResponse
    {
        $period = $request->validate(['period' => ['required', 'date_format:Y-m']])['period'];
        abort_if(PayrollRun::where('period', $period)->exists(), 422, "A run for {$period} already exists.");

        // A run and its lines are one document. Without a transaction, an insert
        // that fails half-way leaves a payroll run holding *some* of the staff —
        // and a total that looks authoritative while being wrong.
        $run = \Illuminate\Support\Facades\DB::transaction(function () use ($period) {
            $run = PayrollRun::create([
                'company_id' => Tenant::id(),
                'period' => $period, 'status' => 'draft', 'currency' => 'AFN',
            ]);

            $tallies = AttendanceRecord::whereBetween('att_date', ["{$period}-01", "{$period}-31"])
                ->get(['user_id', 'status'])->groupBy('user_id');

            foreach (User::where('company_id', Tenant::id())->where('active', true)->get() as $u) {
                $mine = $tallies->get($u->id) ?? collect();
                $absent = $mine->where('status', 'absent')->count();
                $present = $mine->where('status', 'present')->count();
                $leave = $mine->where('status', 'leave')->count();

                $basic = (float) $u->basic_salary;
                $deduction = round($basic / 30 * $absent, 2);

                $run->items()->create([
                    'company_id' => Tenant::id(),
                    'user_id' => $u->id,
                    'basic' => $basic,
                    'allowances' => 0, 'bonus' => 0, 'overtime' => 0,
                    'deductions' => $deduction,
                    'present_days' => $present, 'absent_days' => $absent, 'leave_days' => $leave,
                    'net' => round($basic - $deduction, 2),
                ]);
            }

            return $run;
        });

        ActivityLog::log('created', 'Payroll', "Generated payroll draft for {$period}");

        return response()->json($this->payload($run), 201);
    }

    public function show(PayrollRun $payroll): JsonResponse
    {
        return response()->json($this->payload($payroll));
    }

    /** Edit one line while the run is still a draft. */
    public function updateItem(Request $request, PayrollItem $item): JsonResponse
    {
        abort_if(PayrollRun::find($item->payroll_run_id)?->status === 'paid', 422, 'Run is already paid.');

        $data = $request->validate([
            'basic' => ['nullable', 'numeric', 'min:0'],
            'allowances' => ['nullable', 'numeric', 'min:0'],
            'bonus' => ['nullable', 'numeric', 'min:0'],
            'overtime' => ['nullable', 'numeric', 'min:0'],
            'deductions' => ['nullable', 'numeric', 'min:0'],
        ]);

        $item->fill(array_filter($data, fn ($v) => $v !== null));
        $item->net = round((float) $item->basic + (float) $item->allowances + (float) $item->bonus
            + (float) $item->overtime - (float) $item->deductions, 2);
        $item->save();

        return response()->json($item->load('user:id,name'));
    }

    public function markPaid(PayrollRun $payroll): JsonResponse
    {
        abort_if($payroll->status === 'paid', 422, 'Already paid.');
        $payroll->update(['status' => 'paid']);

        $total = (float) $payroll->items()->sum('net');
        ActivityLog::log('updated', 'Payroll', "Payroll {$payroll->period} marked PAID — ".number_format($total, 2).' AFN');

        return response()->json($this->payload($payroll));
    }

    public function destroy(PayrollRun $payroll): JsonResponse
    {
        abort_if($payroll->status === 'paid', 422, 'A paid run cannot be deleted.');
        $period = $payroll->period;
        $payroll->delete();
        ActivityLog::log('deleted', 'Payroll', "Deleted draft payroll {$period}");

        return response()->json(['message' => 'Deleted.']);
    }

    /** Staff salary book: list + set basic salaries (payroll-gated). */
    public function salaries(): JsonResponse
    {
        return response()->json(
            User::where('company_id', Tenant::id())->orderBy('name')
                ->get(['id', 'name', 'email', 'basic_salary', 'active'])
                ->map(fn (User $u) => [
                    'id' => $u->id, 'name' => $u->name, 'email' => $u->email,
                    'basic_salary' => (float) $u->basic_salary, 'active' => (bool) $u->active,
                    'roles' => $u->getRoleNames(),
                ])
        );
    }

    public function setSalary(Request $request, User $user): JsonResponse
    {
        abort_unless($user->company_id === Tenant::id(), 404);
        $data = $request->validate(['basic_salary' => ['required', 'numeric', 'min:0']]);
        $user->forceFill(['basic_salary' => round((float) $data['basic_salary'], 2)])->save();

        ActivityLog::log('updated', 'Payroll', "Set salary for \"{$user->name}\"");

        return response()->json(['id' => $user->id, 'basic_salary' => (float) $user->basic_salary]);
    }

    private function payload(PayrollRun $run): array
    {
        $items = $run->items()->with('user:id,name,email')->orderBy('id')->get();

        return [
            'run' => $run,
            'items' => $items,
            'totals' => [
                'basic' => round((float) $items->sum('basic'), 2),
                'allowances' => round((float) $items->sum('allowances'), 2),
                'bonus' => round((float) $items->sum('bonus'), 2),
                'overtime' => round((float) $items->sum('overtime'), 2),
                'deductions' => round((float) $items->sum('deductions'), 2),
                'net' => round((float) $items->sum('net'), 2),
            ],
        ];
    }
}
