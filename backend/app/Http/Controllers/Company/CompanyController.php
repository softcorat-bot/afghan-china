<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json($request->user()->companies()->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:255'],
            'name_fa' => ['nullable', 'string'],
            'name_pa' => ['nullable', 'string'],
            'abbreviation' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string'],
            'email' => ['nullable', 'email'],
            'website' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'country' => ['nullable', 'string'],
            'currency' => ['nullable', 'string'],
            'lang' => ['nullable', 'string'],
            'calendar_type' => ['nullable', 'string'],
            'business_type' => ['nullable', 'string'],
            'financial_start_date' => ['nullable', 'date'],
            'financial_end_date' => ['nullable', 'date'],
        ]);

        $company = Company::create($data);

        $user = $request->user();
        $user->companies()->syncWithoutDetaching([$company->id]);
        $user->forceFill(['current_company' => $company->id])->save();

        return response()->json($company, 201);
    }

    public function show(Company $company): JsonResponse
    {
        return response()->json($company);
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        $company->update($request->all());

        return response()->json($company);
    }

    public function destroy(Company $company): JsonResponse
    {
        $company->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    public function select(Request $request, Company $company): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->companies()->whereKey($company->id)->exists(), 403);

        $user->forceFill(['current_company' => $company->id])->save();

        return response()->json(['message' => 'Company switched.', 'current_company' => $company->id]);
    }
}
