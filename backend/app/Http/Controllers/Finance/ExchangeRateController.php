<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\ExchangeRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExchangeRateController extends Controller
{
    public function baseCode(): string
    {
        return Currency::where('is_base', true)->value('code') ?? 'AFN';
    }

    /**
     * Current (latest) rate for every active non-base currency, plus recent history.
     */
    public function index(): JsonResponse
    {
        $base = $this->baseCode();

        $currencies = Currency::where('active', true)->where('is_base', false)->orderBy('code')->get();

        $current = $currencies->map(function ($c) {
            $latest = ExchangeRate::where('currency_code', $c->code)
                ->orderByDesc('rate_date')->orderByDesc('id')->first();

            return [
                'currency_code' => $c->code,
                'name' => $c->name,
                'rate_to_base' => $latest?->rate_to_base,
                'rate_date' => $latest?->rate_date?->toDateString(),
            ];
        });

        $history = ExchangeRate::with('user:id,name')
            ->orderByDesc('rate_date')->orderByDesc('id')->limit(50)->get();

        return response()->json([
            'base' => $base,
            'current' => $current,
            'history' => $history,
        ]);
    }

    /**
     * Map of current rates keyed by currency code, e.g. { "USD": 70, "AFN": 1 }.
     * Used by transaction forms to prefill (but not lock) the rate.
     */
    public function current(): JsonResponse
    {
        $base = $this->baseCode();
        $map = [$base => 1];

        Currency::where('active', true)->where('is_base', false)->get()->each(function ($c) use (&$map) {
            $latest = ExchangeRate::where('currency_code', $c->code)
                ->orderByDesc('rate_date')->orderByDesc('id')->first();
            $map[$c->code] = $latest ? (float) $latest->rate_to_base : null;
        });

        return response()->json(['base' => $base, 'rates' => $map]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'currency_code' => ['required', 'string', 'max:10'],
            'rate_to_base' => ['required', 'numeric', 'min:0'],
            'rate_date' => ['nullable', 'date'],
        ]);
        $data['currency_code'] = strtoupper($data['currency_code']);
        $data['rate_date'] = $data['rate_date'] ?? now()->toDateString();

        if ($data['currency_code'] === $this->baseCode()) {
            return response()->json(['message' => 'The base currency always has a rate of 1.'], 422);
        }

        $rate = ExchangeRate::updateOrCreate(
            [
                'company_id' => \App\Support\Tenant::id(),
                'currency_code' => $data['currency_code'],
                'rate_date' => $data['rate_date'],
            ],
            [
                'rate_to_base' => $data['rate_to_base'],
                'user_id' => $request->user()?->id,
            ]
        );

        ActivityLog::log('created', 'ExchangeRate', "Set {$rate->currency_code} rate = {$rate->rate_to_base} on {$data['rate_date']}");

        return response()->json($rate->load('user:id,name'), 201);
    }
}
