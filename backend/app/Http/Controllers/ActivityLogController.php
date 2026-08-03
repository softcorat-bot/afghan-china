<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    /**
     * Filterable audit trail: by person, module, action, date range and
     * free text — everything the Log page's filter bar sends.
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = Tenant::id();

        $logs = ActivityLog::with('user')
            ->where('company_id', $companyId)
            ->when($request->query('user_id'), fn ($q, $v) => $q->where('user_id', $v))
            ->when($request->query('module'), fn ($q, $v) => $q->where('module', $v))
            ->when($request->query('action'), fn ($q, $v) => $q->where('action', $v))
            ->when($request->query('from'), fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when(trim((string) $request->query('search')), fn ($q, $v) => $q->where('description', 'like', "%{$v}%"))
            ->latest()
            ->limit(min((int) ($request->query('limit') ?: 200), 500))
            ->get();

        return response()->json([
            'logs' => $logs,
            'modules' => ActivityLog::where('company_id', $companyId)
                ->whereNotNull('module')->distinct()->orderBy('module')->pluck('module'),
            'users' => User::where('company_id', $companyId)->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
