<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function index(): JsonResponse
    {
        $branches = Branch::orderBy('name')->get();

        // Live per-location performance for the branch cards.
        $salesToday = \App\Models\Sale::where('status', '!=', 'void')->whereDate('sold_at', today())
            ->selectRaw('branch_id, SUM(total) AS total, COUNT(*) AS orders')
            ->groupBy('branch_id')->get()->keyBy('branch_id');
        $sales30 = \App\Models\Sale::where('status', '!=', 'void')
            ->whereDate('sold_at', '>=', now()->subDays(29)->toDateString())
            ->selectRaw('branch_id, SUM(total) AS total')
            ->groupBy('branch_id')->pluck('total', 'branch_id');
        $team = \Illuminate\Support\Facades\DB::table('branch_user')
            ->selectRaw('branch_id, COUNT(*) AS c')->groupBy('branch_id')->pluck('c', 'branch_id');
        $openShifts = \App\Models\Shift::where('status', 'open')
            ->selectRaw('branch_id, COUNT(*) AS c')->groupBy('branch_id')->pluck('c', 'branch_id');

        return response()->json($branches->map(fn (Branch $b) => array_merge($b->toArray(), [
            'sales_today' => round((float) ($salesToday[$b->id]->total ?? 0), 2),
            'orders_today' => (int) ($salesToday[$b->id]->orders ?? 0),
            'sales_30d' => round((float) ($sales30[$b->id] ?? 0), 2),
            'team_count' => (int) ($team[$b->id] ?? 0),
            'open_shifts' => (int) ($openShifts[$b->id] ?? 0),
        ])));
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertBranchProvisioningAllowed($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:50'],
            'active' => ['boolean'],
        ]);

        $branch = Branch::create($data);

        ActivityLog::log('created', 'Branch', "Created branch \"{$branch->name}\"");

        return response()->json($branch, 201);
    }

    public function show(Branch $branch): JsonResponse
    {
        return response()->json($branch);
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:50'],
            'active' => ['boolean'],
        ]);

        $branch->update($data);

        ActivityLog::log('updated', 'Branch', "Updated branch \"{$branch->name}\"");

        return response()->json($branch);
    }

    public function destroy(Request $request, Branch $branch): JsonResponse
    {
        $this->assertBranchProvisioningAllowed($request);

        $name = $branch->name;
        $branch->delete();

        ActivityLog::log('deleted', 'Branch', "Deleted branch \"{$name}\"");

        return response()->json(['message' => 'Deleted.']);
    }

    /**
     * Branch (store location) provisioning is ordinary administration in this
     * retail system: allowed for the Platform Owner, super admins, or any role
     * granted the `branch-create` permission. (The former SaaS Platform-Owner-
     * only gate was Aria-inherited and does not fit a shopping-center.)
     */
    private function assertBranchProvisioningAllowed(Request $request): void
    {
        $user = $request->user();
        $allowed = $user?->isPlatformOwner()
            || (bool) $user?->is_super_admin
            || (bool) $user?->can('branch-create');

        abort_unless($allowed, 403, 'You do not have permission to manage store locations.');
    }

    /** Switch the signed-in user's active branch (null/"all" = all branches). */
    public function switch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $user = $request->user();
        $branchId = $data['branch_id'] ?? null;

        // Only privileged users may pick "all branches"; others must pick one
        // of their assigned branches.
        if ($branchId !== null && ! $user->seesAllBranches()
            && ! in_array($branchId, $user->accessibleBranchIds(), true)) {
            abort(403, 'You are not assigned to that branch.');
        }

        $user->current_branch = $branchId;
        $user->save();

        $name = $branchId ? optional(Branch::find($branchId))->name : 'All Branches';
        ActivityLog::log('updated', 'Branch', "Switched active branch to \"{$name}\"");

        return response()->json(['current_branch' => $branchId, 'name' => $name]);
    }
}
