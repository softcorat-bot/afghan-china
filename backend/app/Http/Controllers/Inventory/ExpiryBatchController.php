<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ExpiryTrackedCategory;
use App\Models\ExpiryAlert;
use App\Models\InventoryExpiryBatch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Product Expiry Batch Management
 * Handles batch creation, tracking, discarding, and expiry alerts
 */
class ExpiryBatchController extends Controller
{
    /**
     * Get all expiry-tracked categories
     */
    public function trackedCategories(): JsonResponse
    {
        $categories = ExpiryTrackedCategory::where('company_id', Tenant::id())
            ->with('category')
            ->where('require_expiry', true)
            ->get();

        return response()->json($categories);
    }

    /**
     * Get all products that require expiry tracking
     */
    public function trackedProducts(): JsonResponse
    {
        $categoryIds = ExpiryTrackedCategory::where('company_id', Tenant::id())
            ->where('require_expiry', true)
            ->pluck('category_id');

        $products = Product::where('company_id', Tenant::id())
            ->whereIn('category_id', $categoryIds)
            ->with('category')
            ->get();

        return response()->json($products);
    }

    /**
     * List all inventory batches
     */
    public function index(Request $request): JsonResponse
    {
        $query = InventoryExpiryBatch::where('company_id', Tenant::id())
            ->with(['product', 'product.category', 'branch']);

        if ($request->status) {
            $query->where('status', $request->status);
        }

        if ($request->product_id) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->expiring) {
            // Find batches expiring within X days
            $days = $request->expiring;
            $query->whereDate('expiry_date', '<=', now()->addDays($days))
                ->whereDate('expiry_date', '>', today());
        }

        $batches = $query->orderBy('expiry_date', 'asc')
            ->paginate($request->per_page ?? 50);

        return response()->json($batches);
    }

    /**
     * Create new batch
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'batch_number' => 'required|string|unique:inventory_expiry_batches,batch_number',
            'manufacture_date' => 'required|date',
            'expiry_date' => 'required|date|after:manufacture_date',
            'quantity_received' => 'required|numeric|min:0.01',
            'unit_cost' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:500',
            'branch_id' => 'nullable|exists:branches,id',
        ]);

        $product = Product::findOrFail($data['product_id']);

        $batch = InventoryExpiryBatch::create([
            'company_id' => Tenant::id(),
            'product_id' => $data['product_id'],
            'branch_id' => $data['branch_id'] ?? null,
            'batch_number' => $data['batch_number'],
            'manufacture_date' => $data['manufacture_date'],
            'expiry_date' => $data['expiry_date'],
            'quantity_received' => $data['quantity_received'],
            'quantity_available' => $data['quantity_received'],
            'unit_cost' => $data['unit_cost'],
            'total_cost' => $data['quantity_received'] * $data['unit_cost'],
            'notes' => $data['notes'] ?? null,
            'status' => now()->toDateString() > $data['expiry_date'] ? 'expired' : 'active',
        ]);

        ActivityLog::log('created', 'InventoryBatch', "Batch {$data['batch_number']} for {$product->name}");

        return response()->json($batch, 201);
    }

    /**
     * Get batch details
     */
    public function show(InventoryExpiryBatch $batch): JsonResponse
    {
        abort_unless($batch->company_id === Tenant::id(), 403);

        $batch->load(['product', 'product.category', 'branch', 'alerts']);

        return response()->json($batch);
    }

    /**
     * Update batch details
     */
    public function update(Request $request, InventoryExpiryBatch $batch): JsonResponse
    {
        abort_unless($batch->company_id === Tenant::id(), 403);

        $data = $request->validate([
            'notes' => 'nullable|string|max:500',
            'quantity_received' => 'nullable|numeric|min:0',
        ]);

        $batch->update($data);

        return response()->json($batch);
    }

    /**
     * Discard batch or portion of batch
     */
    public function discard(Request $request, InventoryExpiryBatch $batch): JsonResponse
    {
        abort_unless($batch->company_id === Tenant::id(), 403);

        $data = $request->validate([
            'quantity' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($data['quantity'] > $batch->quantity_available) {
            return response()->json(['error' => 'Quantity exceeds available stock'], 400);
        }

        $batch->discard($data['quantity']);

        ActivityLog::log('updated', 'InventoryBatch', "Batch {$batch->batch_number} discarded: {$data['quantity']} units ({$data['reason']})");

        return response()->json($batch);
    }

    /**
     * Mark batch as sold (FIFO selling)
     */
    public function sell(Request $request, InventoryExpiryBatch $batch): JsonResponse
    {
        abort_unless($batch->company_id === Tenant::id(), 403);
        abort_if($batch->status !== 'active', 400, 'Cannot sell from inactive batch');

        $data = $request->validate([
            'quantity' => 'required|numeric|min:0.01',
        ]);

        if ($data['quantity'] > $batch->quantity_available) {
            return response()->json(['error' => 'Quantity exceeds available stock'], 400);
        }

        $batch->sell($data['quantity']);

        return response()->json($batch);
    }

    /**
     * Get expiry alerts
     */
    public function alerts(Request $request): JsonResponse
    {
        $query = ExpiryAlert::where('company_id', Tenant::id())
            ->with(['batch', 'batch.product', 'batch.product.category']);

        if ($request->acknowledged === 'false') {
            $query->where('acknowledged', false);
        }

        if ($request->type) {
            $query->where('alert_type', $request->type);
        }

        $alerts = $query->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 50);

        return response()->json($alerts);
    }

    /**
     * Acknowledge an alert
     */
    public function acknowledgeAlert(ExpiryAlert $alert): JsonResponse
    {
        abort_unless($alert->company_id === Tenant::id(), 403);

        $alert->acknowledge();

        return response()->json($alert);
    }

    /**
     * Acknowledge all alerts
     */
    public function acknowledgeAllAlerts(): JsonResponse
    {
        ExpiryAlert::where('company_id', Tenant::id())
            ->where('acknowledged', false)
            ->update([
                'acknowledged' => true,
                'acknowledged_at' => now(),
                'acknowledged_by' => auth()->id(),
            ]);

        return response()->json(['message' => 'All alerts acknowledged']);
    }

    /**
     * Get expiry report/summary
     */
    public function summary(): JsonResponse
    {
        $batches = InventoryExpiryBatch::where('company_id', Tenant::id())->get();

        $summary = [
            'total_batches' => $batches->count(),
            'active_batches' => $batches->where('status', 'active')->count(),
            'expiring_soon' => $batches->filter(fn ($b) => $b->is_expiring)->count(),
            'expired_batches' => $batches->where('status', 'expired')->count(),
            'discarded_batches' => $batches->where('status', 'discarded')->count(),

            'total_quantity' => $batches->sum('quantity_received'),
            'available_quantity' => $batches->sum('quantity_available'),
            'sold_quantity' => $batches->sum('quantity_sold'),
            'discarded_quantity' => $batches->sum('quantity_discarded'),

            'total_investment' => $batches->sum('total_cost'),
            'available_value' => $batches->sum(fn ($b) => $b->quantity_available * $b->unit_cost),
            'loss_from_expiry' => $batches->sum(fn ($b) => $b->quantity_discarded * $b->unit_cost),

            'pending_alerts' => ExpiryAlert::where('company_id', Tenant::id())
                ->where('acknowledged', false)
                ->count(),
        ];

        return response()->json($summary);
    }

    /**
     * Setup expiry tracking for categories (admin)
     */
    public function setupCategory(Request $request, ProductCategory $category): JsonResponse
    {
        abort_unless(auth()->user()->hasPermissionTo('admin'), 403);

        $data = $request->validate([
            'require_expiry' => 'boolean',
            'warning_days' => 'numeric|min:1|max:365',
        ]);

        ExpiryTrackedCategory::updateOrCreate(
            ['company_id' => Tenant::id(), 'category_id' => $category->id],
            $data
        );

        return response()->json(['message' => 'Category updated']);
    }
}
