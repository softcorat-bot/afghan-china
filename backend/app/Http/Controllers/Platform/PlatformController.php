<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PlatformAudit;
use App\Models\PlatformRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * VIP Control Center — reserved to the Platform Owner (enforced by the
 * platform_owner middleware). Handles tenant/branch provisioning and the
 * approval workflow. Every mutating action is written to the platform audit
 * trail with before/after values.
 */
class PlatformController extends Controller
{
    public function dashboard(): JsonResponse
    {
        $orgs = Company::withoutGlobalScopes()->withCount(['branches' => fn ($q) => $q->withoutGlobalScopes()])->get();
        $branches = Branch::withoutGlobalScopes()->withTrashed()->get();

        return response()->json([
            'total_organizations' => $orgs->count(),
            'active_organizations' => $orgs->where('active', true)->count(),
            'total_branches' => $branches->whereNull('deleted_at')->count(),
            'active_branches' => $branches->where('active', true)->whereNull('deleted_at')->count(),
            'suspended_branches' => $branches->where('active', false)->whereNull('deleted_at')->count(),
            'archived_branches' => $branches->whereNotNull('deleted_at')->count(),
            'new_branches_30d' => $branches->where('created_at', '>=', now()->subDays(30))->count(),
            'pending_requests' => PlatformRequest::where('status', 'pending')->count(),
            'branch_distribution' => $orgs->map(fn ($o) => [
                'organization' => $o->name_en ?? $o->abbreviation,
                'branches' => $o->branches_count,
                'active' => (bool) $o->active,
                'self_service' => (bool) $o->branch_self_service,
            ])->values(),
        ]);
    }

    // ── Organizations (tenants) ──
    public function organizations(): JsonResponse
    {
        $rows = Company::withoutGlobalScopes()
            ->withCount(['branches' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderBy('name_en')->get();

        return response()->json($rows);
    }

    public function createOrganization(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:255'],
            'abbreviation' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:40'],
            'country' => ['nullable', 'string', 'max:80'],
        ]);
        $data['active'] = true;

        $org = Company::create($data);
        PlatformAudit::record('organization.create', $org, null, $org->toArray());

        return response()->json($org, 201);
    }

    public function toggleOrganization(Company $company): JsonResponse
    {
        $before = $company->only(['active']);
        $company->update(['active' => ! $company->active]);
        PlatformAudit::record('organization.'.($company->active ? 'activate' : 'suspend'), $company, $before, $company->only(['active']));

        return response()->json($company);
    }

    public function setSelfService(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $before = $company->only(['branch_self_service']);
        $company->update(['branch_self_service' => $data['enabled']]);
        PlatformAudit::record('organization.branch_self_service', $company, $before, $company->only(['branch_self_service']));

        return response()->json($company);
    }

    // ── Branches ──
    public function branches(): JsonResponse
    {
        $rows = Branch::withoutGlobalScopes()->withTrashed()
            ->with(['company' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderByDesc('id')->get();

        return response()->json($rows);
    }

    public function createBranch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);
        $data['active'] = true;

        $branch = Branch::create($data);
        PlatformAudit::record('branch.create', $branch, null, $branch->toArray());

        return response()->json($branch, 201);
    }

    public function renameBranch(Request $request, Branch $branch): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $before = $branch->only(['name']);
        $branch->update($data);
        PlatformAudit::record('branch.rename', $branch, $before, $branch->only(['name']));

        return response()->json($branch);
    }

    public function toggleBranch(Branch $branch): JsonResponse
    {
        $before = $branch->only(['active']);
        $branch->update(['active' => ! $branch->active]);
        PlatformAudit::record('branch.'.($branch->active ? 'activate' : 'suspend'), $branch, $before, $branch->only(['active']));

        return response()->json($branch);
    }

    public function transferBranch(Request $request, Branch $branch): JsonResponse
    {
        $data = $request->validate(['company_id' => ['required', 'integer']]);
        $before = $branch->only(['company_id']);
        $branch->update(['company_id' => $data['company_id']]);
        PlatformAudit::record('branch.transfer', $branch, $before, $branch->only(['company_id']));

        return response()->json($branch);
    }

    public function archiveBranch(Branch $branch): JsonResponse
    {
        $before = $branch->toArray();
        $branch->delete(); // soft delete = archive
        PlatformAudit::record('branch.archive', $branch, $before, null);

        return response()->json(['message' => 'Branch archived.']);
    }

    public function deleteBranch(Branch $branch): JsonResponse
    {
        $before = $branch->toArray();
        $branch->forceDelete();
        PlatformAudit::record('branch.delete', null, $before, null);

        return response()->json(['message' => 'Branch permanently deleted.']);
    }

    // ── Approval workflow ──
    public function requests(): JsonResponse
    {
        $rows = PlatformRequest::with(['company' => fn ($q) => $q->withoutGlobalScopes(), 'requester:id,name'])
            ->orderByRaw("status = 'pending' desc")->orderByDesc('id')->get();

        return response()->json($rows);
    }

    public function decideRequest(Request $request, PlatformRequest $platformRequest): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected,info_requested,scheduled,assigned,escalated'],
            'note' => ['nullable', 'string', 'max:500'],
            'scheduled_for' => ['nullable', 'date'],
        ]);
        $before = $platformRequest->only(['status']);
        $platformRequest->update([
            'status' => $data['decision'],
            'note' => $data['note'] ?? $platformRequest->note,
            'scheduled_for' => $data['scheduled_for'] ?? $platformRequest->scheduled_for,
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
        ]);
        PlatformAudit::record('request.'.$data['decision'], $platformRequest, $before, $platformRequest->only(['status']));

        return response()->json($platformRequest->fresh());
    }

    /**
     * A tenant administrator raises a request for a platform-gated capability.
     * Available to authenticated tenant users (NOT platform-owner gated); only
     * the Platform Owner can decide it.
     */
    public function submitRequest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'max:60'],
            'title' => ['required', 'string', 'max:255'],
            'payload' => ['nullable', 'array'],
        ]);

        $req = PlatformRequest::create([
            'company_id' => \App\Support\Tenant::id(),
            'requested_by' => $request->user()->id,
            'requested_by_name' => $request->user()->name,
            'type' => $data['type'],
            'title' => $data['title'],
            'payload' => $data['payload'] ?? null,
            'status' => 'pending',
        ]);

        return response()->json($req, 201);
    }

    /** Immutable platform audit trail (read-only). */
    public function audit(): JsonResponse
    {
        return response()->json(
            PlatformAudit::with('actor:id,name')->orderByDesc('id')->limit(500)->get()
        );
    }
}
