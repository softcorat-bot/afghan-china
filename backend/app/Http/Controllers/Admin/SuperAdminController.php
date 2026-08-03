<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class SuperAdminController extends Controller
{
    // GET /admin/companies — all companies with stats
    public function companies(): JsonResponse
    {
        $companies = Company::withCount([
            'branches' => fn ($q) => $q->withoutGlobalScopes(),
            'users',
        ])->get();

        return response()->json($companies);
    }

    // GET /admin/companies/{id}/stats — detailed stats for one company
    public function companyStats(Company $company): JsonResponse
    {
        return response()->json([
            'id' => $company->id,
            'name_en' => $company->name_en,
            'business_type' => $company->business_type,
            'branches_count' => $company->branches()->count(),
            'users_count' => $company->users()->count(),
        ]);
    }

    // GET /admin/users
    public function users(): JsonResponse
    {
        return response()->json(User::with('companies')->get());
    }

    // PUT /admin/users/{id}/toggle-super-admin
    public function toggleSuperAdmin(User $user): JsonResponse
    {
        $user->update(['is_super_admin' => ! $user->is_super_admin]);

        return response()->json($user);
    }

    // DELETE /admin/companies/{id}
    public function destroyCompany(Company $company): JsonResponse
    {
        $company->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
