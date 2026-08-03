<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customers = Customer::when($request->filled('search'), function ($qq) use ($request) {
            $s = '%'.$request->string('search').'%';
            $qq->where(fn ($w) => $w->where('name', 'like', $s)->orWhere('phone', 'like', $s));
        })->orderBy('name')->limit(200)->get();

        // The loyalty badge travels with the row so the list can show it
        // without every page re-deriving the thresholds.
        $customers->each(fn ($c) => $c->setAttribute('loyalty', \App\Support\Loyalty::summary($c)));

        return response()->json($customers);
    }

    /**
     * One customer's dashboard: who they are, the loyalty badge their spend
     * has earned, and how they actually shop (basket size, favourite goods,
     * a year of monthly spend).
     */
    public function dashboard(Customer $customer): JsonResponse
    {
        $sales = \App\Models\Sale::where('customer_id', $customer->id)
            ->where('status', '!=', 'void')
            ->get(['id', 'invoice_no', 'sold_at', 'total', 'paid', 'channel']);

        $orders = $sales->count();
        $spend = (float) $sales->sum('total');

        // Twelve months of spend, zero-filled so the chart never has gaps.
        $byMonth = $sales->groupBy(fn ($s) => \Illuminate\Support\Carbon::parse($s->sold_at)->format('Y-m'));
        $months = [];
        for ($m = now()->copy()->subMonths(11)->startOfMonth(); $m <= now(); $m->addMonth()) {
            $key = $m->format('Y-m');
            $months[] = [
                'month' => $key,
                'total' => round((float) ($byMonth->get($key)?->sum('total') ?? 0), 2),
                'orders' => $byMonth->get($key)?->count() ?? 0,
            ];
        }

        // What they keep coming back for.
        $favourites = \App\Models\SaleItem::whereIn('sale_id', $sales->pluck('id'))
            ->selectRaw('product_id, MAX(name) AS name, SUM(qty) AS qty, SUM(line_total) AS total')
            ->groupBy('product_id')->orderByDesc('qty')->limit(8)->get()
            ->map(fn ($r) => [
                'product_id' => $r->product_id,
                'name' => $r->name,
                'qty' => (float) $r->qty,
                'total' => round((float) $r->total, 2),
            ]);

        return response()->json([
            'customer' => $customer,
            // The running totals on the record are what the POS increments;
            // the sales table is the truth, so report both and let the UI
            // show the ledger figure.
            'loyalty' => \App\Support\Loyalty::summary($customer),
            'tiers' => \App\Support\Loyalty::TIERS,
            'stats' => [
                'orders' => $orders,
                'spend' => round($spend, 2),
                'avg_basket' => $orders > 0 ? round($spend / $orders, 2) : 0,
                'largest_order' => round((float) $sales->max('total'), 2),
                'first_order_at' => $sales->min('sold_at'),
                'last_order_at' => $sales->max('sold_at'),
                'outstanding' => round((float) $customer->balance, 2),
                'credit_limit' => round((float) $customer->credit_limit, 2),
            ],
            'months' => $months,
            'favourites' => $favourites,
            'base' => 'AFN',
        ]);
    }

    /** The customer's full, paged ledgers. */
    public function history(Request $request, Customer $customer): JsonResponse
    {
        $type = (string) $request->query('type', 'sales');
        $perPage = min(100, max(10, (int) $request->query('per_page', 25)));

        $page = match ($type) {
            'sales' => \App\Models\Sale::where('customer_id', $customer->id)
                ->with('cashier:id,name')
                ->orderByDesc('id')->paginate($perPage)
                ->through(fn ($s) => [
                    'id' => $s->id,
                    'reference' => $s->invoice_no,
                    'date' => $s->sold_at,
                    'channel' => $s->channel,
                    'status' => $s->status,
                    'user' => $s->cashier?->name,
                    'items' => (int) $s->items()->count(),
                    'total' => (float) $s->total,
                    'paid' => (float) $s->paid,
                ]),

            'items' => \App\Models\SaleItem::whereHas('sale', fn ($q) => $q->where('customer_id', $customer->id))
                ->with('sale:id,invoice_no,sold_at')
                ->orderByDesc('id')->paginate($perPage)
                ->through(fn ($l) => [
                    'id' => $l->id,
                    'date' => $l->sale?->sold_at,
                    'reference' => $l->sale?->invoice_no,
                    'name' => $l->name,
                    'qty' => (float) $l->qty,
                    'unit_price' => (float) $l->unit_price,
                    'total' => (float) $l->line_total,
                ]),

            default => abort(422, "Unknown history type \"{$type}\"."),
        };

        return response()->json($page);
    }

    public function store(Request $request): JsonResponse
    {
        $customer = Customer::create($this->validated($request));

        ActivityLog::log('created', 'Customer', "Added customer \"{$customer->name}\"");

        return response()->json($customer, 201);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $customer->update($this->validated($request));

        ActivityLog::log('updated', 'Customer', "Updated customer \"{$customer->name}\"");

        return response()->json($customer);
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $name = $customer->name;
        $customer->delete();

        ActivityLog::log('deleted', 'Customer', "Deleted customer \"{$name}\"");

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
