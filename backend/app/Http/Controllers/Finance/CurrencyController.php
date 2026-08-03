<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrencyController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Currency::orderByDesc('is_base')->orderBy('code')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:10'],
            'name' => ['required', 'string', 'max:255'],
            'symbol' => ['nullable', 'string', 'max:10'],
            'active' => ['boolean'],
        ]);
        $data['code'] = strtoupper($data['code']);

        $currency = Currency::create($data);

        ActivityLog::log('created', 'Currency', "Added currency {$currency->code}");

        return response()->json($currency, 201);
    }

    public function update(Request $request, Currency $currency): JsonResponse
    {
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:10'],
            'name' => ['sometimes', 'string', 'max:255'],
            'symbol' => ['nullable', 'string', 'max:10'],
            'active' => ['boolean'],
        ]);
        if (isset($data['code'])) {
            $data['code'] = strtoupper($data['code']);
        }

        $currency->update($data);

        ActivityLog::log('updated', 'Currency', "Updated currency {$currency->code}");

        return response()->json($currency);
    }

    public function destroy(Currency $currency): JsonResponse
    {
        if ($currency->is_base) {
            return response()->json(['message' => 'Cannot delete the base currency.'], 422);
        }

        $currency->delete();

        ActivityLog::log('deleted', 'Currency', "Deleted currency {$currency->code}");

        return response()->json(['message' => 'Deleted.']);
    }

    public function setBase(Currency $currency): JsonResponse
    {
        Currency::query()->update(['is_base' => false]);
        $currency->update(['is_base' => true, 'active' => true]);

        ActivityLog::log('updated', 'Currency', "Set base currency to {$currency->code}");

        return response()->json(['message' => 'Base currency updated.', 'base' => $currency->code]);
    }
}
