<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AttendanceRecord;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Daily staff attendance — the take-attendance sheet: every active user,
 * a present/absent/leave state per day, and each person's running
 * month statistics beside their toggle.
 */
class AttendanceController extends Controller
{
    /** The sheet for one date + per-person stats for that month. */
    public function sheet(Request $request): JsonResponse
    {
        $date = $request->query('date') ?: now()->toDateString();
        $month = substr($date, 0, 7);
        $companyId = Tenant::id();

        // Everyone is PRESENT by default: opening the sheet marks all
        // unmarked active staff present for that date, and the manager
        // only flips the exceptions.
        if ($request->boolean('init')) {
            $existing = AttendanceRecord::where('att_date', $date)->pluck('user_id');
            $missing = User::where('company_id', $companyId)->where('active', true)
                ->whereNotIn('id', $existing)->pluck('id');
            foreach ($missing as $uid) {
                AttendanceRecord::firstOrCreate(
                    ['user_id' => $uid, 'att_date' => $date],
                    ['company_id' => $companyId, 'status' => 'present', 'marked_by' => $request->user()->id]
                );
            }
        }

        $marks = AttendanceRecord::where('att_date', $date)->get()->keyBy('user_id');
        $monthly = AttendanceRecord::whereBetween('att_date', ["{$month}-01", "{$month}-31"])
            ->selectRaw("user_id,
                SUM(CASE WHEN status='present' THEN 1 ELSE 0 END) AS present,
                SUM(CASE WHEN status='absent'  THEN 1 ELSE 0 END) AS absent,
                SUM(CASE WHEN status='leave'   THEN 1 ELSE 0 END) AS leave_days")
            ->groupBy('user_id')->get()->keyBy('user_id');

        $rows = User::where('company_id', $companyId)->where('active', true)
            ->orderBy('name')->get(['id', 'name', 'email', 'basic_salary'])
            ->map(function (User $u) use ($marks, $monthly) {
                $m = $monthly->get($u->id);
                $present = (int) ($m->present ?? 0);
                $absent = (int) ($m->absent ?? 0);
                $total = $present + $absent;

                return [
                    'user_id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'roles' => $u->getRoleNames(),
                    'status' => $marks->get($u->id)?->status,   // null = unmarked
                    'month_present' => $present,
                    'month_absent' => $absent,
                    'month_leave' => (int) ($m->leave_days ?? 0),
                    'month_pct' => $total > 0 ? round($present / $total * 100, 1) : null,
                ];
            });

        return response()->json(['date' => $date, 'rows' => $rows]);
    }

    /** Flip one person's state for a date (upsert — instant save). */
    public function mark(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', 'in:present,absent,leave'],
            'note' => ['nullable', 'string'],
        ]);

        $rec = AttendanceRecord::updateOrCreate(
            ['user_id' => $data['user_id'], 'att_date' => $data['date']],
            [
                'company_id' => Tenant::id(),
                'status' => $data['status'],
                'note' => $data['note'] ?? null,
                'marked_by' => $request->user()->id,
            ]
        );

        return response()->json($rec);
    }

    /** One person's month grid (for the profile drill-down). */
    public function person(Request $request, User $user): JsonResponse
    {
        $month = $request->query('month') ?: now()->format('Y-m');

        return response()->json(
            AttendanceRecord::where('user_id', $user->id)
                ->whereBetween('att_date', ["{$month}-01", "{$month}-31"])
                ->orderBy('att_date')->get(['att_date', 'status', 'note'])
        );
    }
}
