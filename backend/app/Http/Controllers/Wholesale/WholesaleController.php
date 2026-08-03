<?php

namespace App\Http\Controllers\Wholesale;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Support\Branch;
use App\Support\Sql;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Wholesale (B2B): the SAME products, stock and ledger as retail — a second
 * business layer, never a second database. Orders are `sales` rows with
 * channel=wholesale; quotations are the same rows with status=quote and no
 * stock/balance effect until converted. Wholesale price = wholesale_price
 * ?? sale_price (retail fallback). Outstanding credit lives on the
 * customer's `balance` and is settled through recorded payments.
 */
class WholesaleController extends Controller
{
    /* ── Customers ─────────────────────────────────────────────── */

    public function customers(Request $request): JsonResponse
    {
        $rows = Customer::where('type', 'wholesale')
            ->when(trim((string) $request->query('search')), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$s}%")
                ->orWhere('company_name', 'like', "%{$s}%")
                ->orWhere('phone', 'like', "%{$s}%")))
            ->orderBy('company_name')->orderBy('name')->get();

        return response()->json($rows);
    }

    public function storeCustomer(Request $request): JsonResponse
    {
        $data = $this->validatedCustomer($request);
        $data['type'] = 'wholesale';
        $customer = Customer::create($data);

        ActivityLog::log('created', 'Wholesale', "Added wholesale customer \"{$customer->display_name}\"");

        return response()->json($customer, 201);
    }

    public function updateCustomer(Request $request, Customer $customer): JsonResponse
    {
        $customer->update($this->validatedCustomer($request));

        ActivityLog::log('updated', 'Wholesale', "Updated wholesale customer \"{$customer->display_name}\"");

        return response()->json($customer);
    }

    private function validatedCustomer(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],          // contact person display
            'company_name' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'payment_terms' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
            'active' => ['boolean'],
        ]);
    }

    /** Full account ledger: orders, payments and the running balance. */
    public function ledger(Customer $customer): JsonResponse
    {
        $orders = Sale::where('customer_id', $customer->id)
            ->where('channel', 'wholesale')
            ->with('payments:id,sale_id,method,amount,created_at')
            ->orderByDesc('id')->limit(200)
            ->get(['id', 'invoice_no', 'status', 'total', 'paid', 'sold_at', 'created_at']);

        return response()->json([
            'customer' => $customer,
            'orders' => $orders->map(fn ($s) => [
                'id' => $s->id,
                'invoice_no' => $s->invoice_no,
                'status' => $s->status,
                'total' => (float) $s->total,
                'paid' => (float) $s->paid,
                'outstanding' => $s->status === 'quote' ? 0 : round((float) $s->total - (float) $s->paid, 2),
                'date' => optional($s->sold_at ?? $s->created_at)->toDateString(),
                'payments' => $s->payments,
            ]),
        ]);
    }

    /* ── Catalog through the wholesale lens ─────────────────────── */

    public function catalog(Request $request): JsonResponse
    {
        $rows = Product::where('status', 'active')
            ->when(trim((string) $request->query('search')), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$s}%")
                ->orWhere('sku', 'like', "%{$s}%")
                ->orWhere('barcode', 'like', "%{$s}%")))
            ->orderBy('name')->limit(60)
            ->get(['id', 'name', 'name_fa', 'sku', 'barcode', 'unit', 'image',
                'sale_price', 'wholesale_price', 'stock_qty', 'track_inventory', 'tax_rate']);

        return response()->json($rows->map(fn (Product $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'sku' => $p->sku,
            'barcode' => $p->barcode,
            'unit' => $p->unit,
            'image_url' => $p->image_url,
            'stock_qty' => (float) $p->stock_qty,
            'retail_price' => (float) $p->sale_price,
            // wholesale ?? retail — the documented fallback
            'wholesale_price' => (float) ($p->wholesale_price ?? $p->sale_price),
            'has_wholesale_price' => $p->wholesale_price !== null,
        ]));
    }

    /* ── Orders & quotations ────────────────────────────────────── */

    public function orders(Request $request): JsonResponse
    {
        $rows = Sale::where('channel', 'wholesale')
            ->with('customer:id,name,company_name')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('customer_id'), fn ($q, $v) => $q->where('customer_id', $v))
            ->orderByDesc('id')->limit(200)
            ->get(['id', 'invoice_no', 'customer_id', 'status', 'subtotal', 'discount', 'total', 'paid', 'sold_at', 'created_at']);

        return response()->json($rows->map(fn ($s) => [
            'id' => $s->id,
            'invoice_no' => $s->invoice_no,
            'customer' => $s->customer?->company_name ?: $s->customer?->name,
            'customer_id' => $s->customer_id,
            'status' => $s->status,
            'subtotal' => (float) $s->subtotal,
            'discount' => (float) $s->discount,
            'total' => (float) $s->total,
            'paid' => (float) $s->paid,
            'outstanding' => $s->status === 'quote' ? 0 : round((float) $s->total - (float) $s->paid, 2),
            'date' => optional($s->sold_at ?? $s->created_at)->format('Y-m-d H:i'),
        ]));
    }

    public function showOrder(Sale $sale): JsonResponse
    {
        abort_unless($sale->channel === 'wholesale', 404);

        return response()->json($sale->load(['items', 'payments', 'customer']));
    }

    public function storeOrder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'kind' => ['required', 'in:invoice,quote'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'paid' => ['nullable', 'numeric', 'min:0'],
            'method' => ['nullable', 'in:cash,card,mobile,credit'],
            'note' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'], // negotiated
        ]);

        $customer = Customer::findOrFail($data['customer_id']);
        abort_unless($customer->type === 'wholesale', 422, 'Not a wholesale customer.');

        $isQuote = $data['kind'] === 'quote';

        $sale = DB::transaction(function () use ($data, $request, $customer, $isQuote) {
            $subtotal = 0.0;
            $lines = [];

            foreach ($data['items'] as $row) {
                $p = Product::whereKey($row['product_id'])->lockForUpdate()->firstOrFail();
                $qty = round((float) $row['qty'], 3);
                $unit = isset($row['unit_price']) && $row['unit_price'] !== null
                    ? round((float) $row['unit_price'], 2)
                    : round((float) ($p->wholesale_price ?? $p->sale_price), 2);

                if (! $isQuote && $p->track_inventory) {
                    abort_if((float) $p->stock_qty < $qty, 422,
                        "Not enough shop stock for \"{$p->name}\" (available {$p->stock_qty}, requested {$qty}).");
                    $p->stock_qty = (float) $p->stock_qty - $qty;
                    $p->save();
                }

                $lineTotal = round($unit * $qty, 2);
                $subtotal += $lineTotal;
                $lines[] = [
                    'company_id' => Tenant::id(),
                    'product_id' => $p->id,
                    'name' => $p->name,
                    'barcode' => $p->barcode,
                    'unit_price' => $unit,
                    'cost_price' => (float) $p->cost_price,
                    'qty' => $qty,
                    'discount' => 0,
                    'tax' => 0,
                    'line_total' => $lineTotal,
                ];
            }

            $discount = round((float) ($data['discount'] ?? 0), 2);
            $total = round(max(0, $subtotal - $discount), 2);
            $paid = $isQuote ? 0.0 : min(round((float) ($data['paid'] ?? 0), 2), $total);

            $sale = Sale::create([
                'company_id' => Tenant::id(),
                'branch_id' => Branch::id(),
                'invoice_no' => $this->nextNo(Tenant::id()),
                'customer_id' => $customer->id,
                'user_id' => $request->user()->id,
                'sold_at' => $isQuote ? null : now(),   // null keeps quotes out of every report
                'subtotal' => round($subtotal, 2),
                'discount' => $discount,
                'tax' => 0,
                'total' => $total,
                'paid' => $paid,
                'change_due' => 0,
                'status' => $isQuote ? 'quote' : 'completed',
                'channel' => 'wholesale',
                'note' => $data['note'] ?? null,
            ]);
            $sale->items()->createMany($lines);

            if (! $isQuote) {
                if ($paid > 0) {
                    $sale->payments()->create([
                        'company_id' => Tenant::id(),
                        'method' => $data['method'] ?? 'cash',
                        'amount' => $paid,
                    ]);
                }
                // Whatever isn't paid becomes the customer's outstanding balance.
                $customer->balance = (float) $customer->balance + ($total - $paid);
                $customer->total_spent = (float) $customer->total_spent + $total;
                $customer->orders_count = (int) $customer->orders_count + 1;
                $customer->save();
            }

            return $sale;
        });

        $label = $isQuote ? 'quotation' : 'invoice';
        ActivityLog::log('created', 'Wholesale', "Wholesale {$label} {$sale->invoice_no} — {$sale->total} AFN for \"{$customer->display_name}\"");

        return response()->json($sale->load(['items', 'customer']), 201);
    }

    /** Turn a saved quotation into a live invoice (stock + balance apply now). */
    public function convert(Request $request, Sale $sale): JsonResponse
    {
        abort_unless($sale->channel === 'wholesale' && $sale->status === 'quote', 422, 'Only quotations can be converted.');

        DB::transaction(function () use ($sale) {
            foreach ($sale->items()->get() as $item) {
                if (! $item->product_id) {
                    continue;
                }
                $p = Product::whereKey($item->product_id)->lockForUpdate()->first();
                if ($p && $p->track_inventory) {
                    abort_if((float) $p->stock_qty < (float) $item->qty, 422,
                        "Not enough shop stock for \"{$p->name}\" to convert this quotation.");
                    $p->stock_qty = (float) $p->stock_qty - (float) $item->qty;
                    $p->save();
                }
            }

            $sale->update(['status' => 'completed', 'sold_at' => now()]);

            $customer = $sale->customer;
            if ($customer) {
                $customer->balance = (float) $customer->balance + (float) $sale->total;
                $customer->total_spent = (float) $customer->total_spent + (float) $sale->total;
                $customer->orders_count = (int) $customer->orders_count + 1;
                $customer->save();
            }
        });

        ActivityLog::log('updated', 'Wholesale', "Converted quotation {$sale->invoice_no} to invoice");

        return response()->json($sale->fresh()->load(['items', 'customer']));
    }

    /** Record a payment against an outstanding wholesale invoice. */
    public function payment(Request $request, Sale $sale): JsonResponse
    {
        abort_unless($sale->channel === 'wholesale' && $sale->status !== 'quote', 422, 'Payments apply to invoices only.');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['nullable', 'in:cash,card,mobile,credit'],
        ]);

        $outstanding = round((float) $sale->total - (float) $sale->paid, 2);
        abort_if($outstanding <= 0, 422, 'This invoice is fully paid.');
        $amount = min(round((float) $data['amount'], 2), $outstanding);

        DB::transaction(function () use ($sale, $amount, $data) {
            $sale->payments()->create([
                'company_id' => Tenant::id(),
                'method' => $data['method'] ?? 'cash',
                'amount' => $amount,
            ]);
            $sale->paid = (float) $sale->paid + $amount;
            $sale->save();

            if ($sale->customer) {
                $sale->customer->balance = max(0, (float) $sale->customer->balance - $amount);
                $sale->customer->save();
            }
        });

        ActivityLog::log('created', 'Wholesale', "Payment {$amount} AFN on {$sale->invoice_no}");

        return response()->json($sale->fresh()->load('payments'));
    }

    /* ── Dashboard ─────────────────────────────────────────────── */

    public function dashboard(Request $request): JsonResponse
    {
        $base = Sale::where('channel', 'wholesale')->where('status', '!=', 'quote');

        $sales30 = (float) (clone $base)->whereDate('sold_at', '>=', now()->subDays(29)->toDateString())->sum('total');
        $orders30 = (clone $base)->whereDate('sold_at', '>=', now()->subDays(29)->toDateString())->count();
        $outstanding = (float) Customer::where('type', 'wholesale')->sum('balance');

        $topCustomers = (clone $base)
            ->whereDate('sold_at', '>=', now()->subDays(89)->toDateString())
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->selectRaw("COALESCE(NULLIF(customers.company_name, ''), customers.name) AS label, customers.balance AS balance, SUM(sales.total) AS total, COUNT(*) AS orders")
            ->groupBy('sales.customer_id', 'label', 'customers.balance')
            ->orderByDesc('total')->limit(5)->get();

        $bestProducts = SaleItem::join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.channel', 'wholesale')->where('sales.status', '!=', 'quote')
            ->whereDate('sales.sold_at', '>=', now()->subDays(89)->toDateString())
            ->selectRaw('sale_items.name, SUM(sale_items.qty) AS qty, SUM(sale_items.line_total) AS revenue')
            ->groupBy('sale_items.name')->orderByDesc('revenue')->limit(5)->get();

        $monthly = (clone $base)
            ->whereDate('sold_at', '>=', now()->subMonths(5)->startOfMonth()->toDateString())
            ->selectRaw(Sql::dateFormat('sold_at', '%Y-%m').' AS m, SUM(total) AS total')
            ->groupBy('m')->orderBy('m')->get();

        $recent = (clone $base)->with('customer:id,name,company_name')
            ->orderByDesc('sold_at')->limit(8)
            ->get(['id', 'invoice_no', 'customer_id', 'total', 'paid', 'sold_at'])
            ->map(fn ($s) => [
                'invoice_no' => $s->invoice_no,
                'customer' => $s->customer?->company_name ?: $s->customer?->name,
                'total' => (float) $s->total,
                'outstanding' => round((float) $s->total - (float) $s->paid, 2),
                'date' => optional($s->sold_at)->format('m-d H:i'),
            ]);

        $paidCount = (clone $base)->whereColumn('paid', '>=', 'total')->count();
        $partialCount = (clone $base)->whereColumn('paid', '<', 'total')->count();
        $quotes = Sale::where('channel', 'wholesale')->where('status', 'quote')->count();

        return response()->json([
            'sales_30d' => round($sales30, 2),
            'orders_30d' => $orders30,
            'outstanding' => round($outstanding, 2),
            'customers' => Customer::where('type', 'wholesale')->count(),
            'open_quotes' => $quotes,
            'top_customers' => $topCustomers,
            'best_products' => $bestProducts,
            'monthly' => $monthly,
            'recent_orders' => $recent,
            'payment_status' => ['paid' => $paidCount, 'partial' => $partialCount],
            'base' => 'AFN',
        ]);
    }

    private function nextNo(int $companyId): string
    {
        // Company-wide sequence: must not inherit the active-branch scope.
        $last = Sale::withTrashed()
            ->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('company_id', $companyId)
            ->where('invoice_no', 'like', 'WS-%')->orderByDesc('id')->value('invoice_no');
        $n = $last ? ((int) preg_replace('/\D/', '', $last)) + 1 : 1;

        return 'WS-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }
}
