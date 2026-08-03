<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Manual stock corrections (recount, damage, theft, supplier return). Each
 * adjustment locks the product, applies a signed delta, and records the
 * before/after so the movement is auditable.
 */
class StockAdjustmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = StockAdjustment::with(['product:id,name', 'user:id,name'])
            ->when($request->filled('product_id'), fn ($qq) => $qq->where('product_id', $request->integer('product_id')))
            ->orderByDesc('id')->limit(500)->get();

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'type' => ['required', 'in:increase,decrease'],
            'location' => ['nullable', 'in:shop,warehouse'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string'],
        ]);
        $location = $data['location'] ?? 'shop';
        $column = $location === 'warehouse' ? 'warehouse_qty' : 'stock_qty';

        $adjustment = DB::transaction(function () use ($data, $request, $location, $column) {
            $p = Product::whereKey($data['product_id'])->lockForUpdate()->firstOrFail();
            $before = (float) $p->{$column};
            $delta = $data['type'] === 'increase' ? (float) $data['qty'] : -(float) $data['qty'];
            $after = $before + $delta;

            if ($after < 0) {
                abort(422, 'Adjustment would drive stock below zero.');
            }

            $p->{$column} = $after;
            $p->save();

            return StockAdjustment::create([
                'company_id' => Tenant::id(),
                'product_id' => $p->id,
                'user_id' => $request->user()->id,
                'type' => $data['type'],
                'location' => $location,
                'reason' => $data['reason'] ?? null,
                'qty' => (float) $data['qty'],
                'stock_before' => $before,
                'stock_after' => $after,
                'note' => $data['note'] ?? null,
            ]);
        });

        ActivityLog::log('created', 'StockAdjustment', "Adjusted {$location} stock ({$data['type']} {$data['qty']}) for product #{$data['product_id']}");

        return response()->json($adjustment->load('product:id,name'), 201);
    }
}
