<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $suppliers = Supplier::when($request->filled('search'), function ($qq) use ($request) {
            $s = '%'.$request->string('search').'%';
            $qq->where(fn ($w) => $w->where('name', 'like', $s)->orWhere('phone', 'like', $s));
        })->orderBy('name')->get();

        return response()->json($suppliers);
    }

    /**
     * One supplier's dashboard: who they are, everything ever bought from
     * them, returns, the payable ledger and a 12-month purchase curve —
     * the full-page view that replaces the cramped info modal.
     */
    public function dashboard(Supplier $supplier): JsonResponse
    {
        $purchases = \App\Models\Purchase::where('supplier_id', $supplier->id)
            ->orderByDesc('id')
            ->get(['id', 'reference', 'supplier_invoice', 'purchased_at', 'subtotal', 'discount', 'tax', 'total', 'paid', 'status', 'created_at']);

        $received = $purchases->where('status', 'received');
        $value = (float) $received->sum('total');
        $paid = (float) $received->sum('paid');

        $returns = \App\Models\PurchaseReturn::where(function ($q) use ($supplier, $purchases) {
            $q->where('supplier_id', $supplier->id)
                ->orWhereIn('purchase_id', $purchases->pluck('id'));
        })->orderByDesc('id')->get(['id', 'purchase_id', 'reference', 'amount', 'reason', 'created_at']);

        // Twelve months of received purchase value, zero-filled.
        $byMonth = $received->groupBy(fn ($p) => \Illuminate\Support\Carbon::parse($p->purchased_at ?? $p->created_at)->format('Y-m'));
        $months = [];
        for ($m = now()->copy()->subMonths(11)->startOfMonth(); $m <= now(); $m->addMonth()) {
            $key = $m->format('Y-m');
            $months[] = [
                'month' => $key,
                'total' => round((float) ($byMonth->get($key)?->sum('total') ?? 0), 2),
                'orders' => $byMonth->get($key)?->count() ?? 0,
            ];
        }

        // What this supplier actually supplies.
        $topProducts = \App\Models\PurchaseItem::whereIn('purchase_id', $received->pluck('id'))
            ->selectRaw('product_id, MAX(name) AS name, SUM(qty) AS qty, SUM(line_total) AS total')
            ->groupBy('product_id')->orderByDesc('total')->limit(8)->get()
            ->map(fn ($r) => [
                'product_id' => $r->product_id,
                'name' => $r->name,
                'qty' => (float) $r->qty,
                'total' => round((float) $r->total, 2),
            ]);

        return response()->json([
            'supplier' => $supplier,
            'stats' => [
                'purchases' => $purchases->count(),
                'received' => $received->count(),
                'drafts' => $purchases->where('status', 'draft')->count(),
                'value' => round($value, 2),
                'paid' => round($paid, 2),
                'returned' => round((float) $returns->sum('amount'), 2),
                'outstanding' => round((float) $supplier->balance, 2),
                'first_purchase_at' => $purchases->min('created_at'),
                'last_purchase_at' => $purchases->max('created_at'),
            ],
            'months' => $months,
            'top_products' => $topProducts,
            'purchases' => $purchases->values(),
            'returns' => $returns->values(),
            'base' => 'AFN',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $supplier = Supplier::create($this->validated($request));
        ActivityLog::log('created', 'Supplier', "Added supplier \"{$supplier->name}\"");

        return response()->json($supplier, 201);
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $supplier->update($this->validated($request));
        ActivityLog::log('updated', 'Supplier', "Updated supplier \"{$supplier->name}\"");

        return response()->json($supplier);
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        $name = $supplier->name;
        $supplier->delete();
        ActivityLog::log('deleted', 'Supplier', "Deleted supplier \"{$name}\"");

        return response()->json(['message' => 'Deleted.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'active' => ['nullable', 'boolean'],
        ]);
    }
}
