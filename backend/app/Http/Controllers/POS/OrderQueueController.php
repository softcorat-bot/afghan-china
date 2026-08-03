<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Order Queue Management for POS
 * Multiple orders can be stacked, but only the first one can be purchased.
 * Subsequent orders are locked until the first order completes.
 */
class OrderQueueController extends Controller
{
    /**
     * Get current order queue for a counter.
     * Returns: first order (active), queued orders (locked)
     */
    public function index(Request $request): JsonResponse
    {
        $counterId = $request->counter_id;
        $userId = auth()->id();

        // Get all draft sales for this counter/user (not yet finalized)
        $orders = Sale::where('company_id', Tenant::id())
            ->where('user_id', $userId)
            ->where('counter_id', $counterId)
            ->whereIn('status', ['draft', 'pending']) // draft = being built, pending = waiting for payment
            ->with(['items', 'customer'])
            ->orderBy('created_at', 'asc')
            ->get();

        $activeOrder = $orders->first();
        $queuedOrders = $orders->skip(1)->values();

        return response()->json([
            'counter_id' => $counterId,
            'active_order' => $activeOrder ? [
                'id' => $activeOrder->id,
                'order_number' => $activeOrder->id,
                'status' => $activeOrder->status,
                'customer' => $activeOrder->customer,
                'items_count' => $activeOrder->items()->count(),
                'total' => $activeOrder->total,
                'created_at' => $activeOrder->created_at,
            ] : null,
            'queue' => $queuedOrders->map(fn ($order) => [
                'id' => $order->id,
                'order_number' => $order->id,
                'status' => 'locked',
                'customer' => $order->customer,
                'items_count' => $order->items()->count(),
                'total' => $order->total,
                'created_at' => $order->created_at,
                'position' => $queuedOrders->search(fn ($q) => $q->id === $order->id) + 2, // Position in queue (1 = active, 2+ = queued)
            ])->values()->toArray(),
            'can_purchase' => $activeOrder !== null,
            'message' => !$activeOrder
                ? 'No active order'
                : ($queuedOrders->count() > 0
                    ? "Finish order #{$activeOrder->id} before purchasing the next one"
                    : null),
        ]);
    }

    /**
     * Create a new order (new item for the queue).
     */
    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'counter_id' => 'required|exists:counters,id',
            'customer_id' => 'nullable|exists:customers,id',
        ]);

        // Check if there are already orders in the queue
        $existingOrders = Sale::where('company_id', Tenant::id())
            ->where('user_id', auth()->id())
            ->where('counter_id', $data['counter_id'])
            ->whereIn('status', ['draft', 'pending'])
            ->count();

        // If there are existing orders, the new one will be queued
        $willBeQueued = $existingOrders > 0;

        $order = Sale::create([
            'company_id' => Tenant::id(),
            'counter_id' => $data['counter_id'],
            'user_id' => auth()->id(),
            'customer_id' => $data['customer_id'] ?? null,
            'status' => 'draft',
            'sold_at' => now(),
            'subtotal' => 0,
            'discount' => 0,
            'tax' => 0,
            'total' => 0,
            'paid' => 0,
            'change_due' => 0,
        ]);

        return response()->json([
            'order' => $order,
            'queued' => $willBeQueued,
            'message' => $willBeQueued
                ? "Order #{$order->id} added to queue. Finish the current order first."
                : "Order #{$order->id} is active. Start adding items.",
        ], 201);
    }

    /**
     * Switch to a queued order (only for admin/manager to skip)
     * Regular cashiers cannot switch.
     */
    public function switchOrder(Request $request, Sale $order): JsonResponse
    {
        // Only managers/admins can skip orders
        abort_unless(auth()->user()->hasPermissionTo('manage-pos-orders'), 403);

        $data = $request->validate([
            'counter_id' => 'required|exists:counters,id',
        ]);

        // Verify the order belongs to this counter
        abort_unless($order->counter_id === (int)$data['counter_id'], 403);

        // Mark all previous orders as abandoned/cancelled
        Sale::where('company_id', Tenant::id())
            ->where('counter_id', $data['counter_id'])
            ->where('created_at', '<', $order->created_at)
            ->whereIn('status', ['draft', 'pending'])
            ->update(['status' => 'cancelled']);

        $order->update(['status' => 'draft']); // Make it active

        return response()->json([
            'message' => "Switched to order #{$order->id}",
            'order' => $order,
        ]);
    }

    /**
     * Abandon an order (without payment).
     */
    public function abandon(Request $request, Sale $order): JsonResponse
    {
        $order->update(['status' => 'cancelled']);

        return response()->json(['message' => "Order #{$order->id} abandoned."]);
    }
}
