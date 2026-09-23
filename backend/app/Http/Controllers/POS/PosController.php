<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Support\Branch;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Point of Sale register endpoints: fast product lookup for the grid /
 * barcode scanner, and a single transactional checkout that records the
 * sale, its lines and tenders, and deducts shop stock atomically.
 */
class PosController extends Controller
{
    /** Fast catalog for the register: active, sellable products only. */
    public function catalog(Request $request): JsonResponse
    {
        $products = Product::with('category:id,name')
            ->where('status', 'active')
            ->where('active', true)
            ->when($request->filled('search'), function ($qq) use ($request) {
                $s = '%'.$request->string('search').'%';
                $qq->where(fn ($w) => $w->where('name', 'like', $s)
                    ->orWhere('sku', 'like', $s)
                    ->orWhere('barcode', 'like', $s));
            })
            ->when($request->filled('category_id'), fn ($qq) => $qq->where('category_id', $request->integer('category_id')))
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'name_fa', 'sku', 'barcode', 'category_id', 'sale_price', 'compare_at_price', 'tax_rate', 'track_inventory', 'stock_qty', 'unit', 'image']);

        return response()->json($products);
    }

    /** Exact barcode resolve — returns one product or 404. */
    public function scan(Request $request): JsonResponse
    {
        $code = trim((string) $request->query('barcode', ''));
        $product = Product::where('status', 'active')->where('active', true)
            ->where(fn ($w) => $w->where('barcode', $code)->orWhere('sku', $code))
            ->first(['id', 'name', 'name_fa', 'sku', 'barcode', 'category_id', 'sale_price', 'compare_at_price', 'tax_rate', 'track_inventory', 'stock_qty', 'unit', 'image']);

        if (! $product) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($product);
    }

    /**
     * Complete a sale. Everything runs in one transaction: the products are
     * re-read with a row lock, stock is checked and deducted, the sale +
     * lines + payments are written, and the customer's running totals bump.
     */
    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'bill_discount' => ['nullable', 'numeric', 'min:0'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'note' => ['nullable', 'string'],
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.method' => ['required', 'in:cash,card,mobile,credit'],
            'payments.*.amount' => ['required', 'numeric', 'min:0'],
        ]);

        $companyId = Tenant::id();

        // Twenty counters can check out in the same instant. Each draw of the
        // invoice number is serialized (lockForUpdate) and, should two still
        // collide, the unique index rejects the copy and the sale simply
        // redraws a fresh number — nothing is lost, nothing conflicts.
        $sale = $this->withInvoiceRetry(fn () => DB::transaction(function () use ($data, $companyId, $request) {
            $ids = collect($data['items'])->pluck('product_id')->all();
            // Lock the product rows for the life of the transaction.
            $products = Product::whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');

            $subtotal = 0;
            $taxTotal = 0;
            $lineDiscTotal = 0;
            $lines = [];

            foreach ($data['items'] as $row) {
                $p = $products[$row['product_id']];
                $qty = (float) $row['qty'];
                $lineDisc = (float) ($row['discount'] ?? 0);

                if ($p->track_inventory && $p->stock_qty < $qty) {
                    abort(422, "Insufficient stock for \"{$p->name}\" (have {$p->stock_qty}, need {$qty}).");
                }

                $gross = round($p->sale_price * $qty, 2);
                $net = max(0, $gross - $lineDisc);
                $tax = round($net * ((float) $p->tax_rate / 100), 2);

                $subtotal += $gross;
                $lineDiscTotal += $lineDisc;
                $taxTotal += $tax;

                $lines[] = [
                    'company_id' => $companyId,
                    'product_id' => $p->id,
                    'name' => $p->name,
                    'barcode' => $p->barcode,
                    'unit_price' => $p->sale_price,
                    'cost_price' => $p->cost_price,
                    'qty' => $qty,
                    'discount' => $lineDisc,
                    'tax' => $tax,
                    'line_total' => round($net + $tax, 2),
                ];

                if ($p->track_inventory) {
                    $p->decrement('stock_qty', $qty);
                }
            }

            $billDiscount = (float) ($data['bill_discount'] ?? 0);
            $total = round(max(0, $subtotal - $lineDiscTotal - $billDiscount + $taxTotal), 2);
            $paid = round(collect($data['payments'])->sum('amount'), 2);

            if ($paid + 0.001 < $total) {
                abort(422, "Payment {$paid} is less than the total {$total}.");
            }

            $invoiceNo = $this->nextInvoiceNo($companyId);

            // Attach to the cashier's currently-open shift (and its counter seat).
            $shift = \App\Models\Shift::where('status', 'open')
                ->where('user_id', $request->user()->id)
                ->when(Branch::check(), fn ($q) => $q->where('branch_id', Branch::id()))
                ->latest('id')->first(\App\Support\Schema::has('shifts', 'counter_id') ? ['id', 'counter_id'] : ['id']);

            // Only write columns this database actually has: a machine that
            // pulled new code but skipped `php artisan migrate` must still be
            // able to sell (it would otherwise 500 on every checkout).
            $sale = Sale::create(\App\Support\Schema::only('sales', [
                'company_id' => $companyId,
                'branch_id' => Branch::id(),
                'shift_id' => $shift?->id,
                'counter_id' => $shift?->counter_id,
                'channel' => 'retail',
                'invoice_no' => $invoiceNo,
                'customer_id' => $data['customer_id'] ?? null,
                'user_id' => $request->user()->id,
                'sold_at' => now(),
                'subtotal' => round($subtotal, 2),
                'discount' => round($lineDiscTotal + $billDiscount, 2),
                'tax' => round($taxTotal, 2),
                'total' => $total,
                'paid' => $paid,
                'change_due' => round(max(0, $paid - $total), 2),
                'status' => 'completed',
                'note' => $data['note'] ?? null,
            ]));

            $sale->items()->createMany($lines);
            $sale->payments()->createMany(collect($data['payments'])
                ->filter(fn ($p) => (float) $p['amount'] > 0)
                ->map(fn ($p) => [
                    'company_id' => $companyId,
                    'method' => $p['method'],
                    'amount' => round((float) $p['amount'], 2),
                    'reference' => $p['reference'] ?? null,
                ])->values()->all());

            if ($sale->customer_id) {
                Customer::whereKey($sale->customer_id)->update([
                    'total_spent' => DB::raw('total_spent + '.$total),
                    'orders_count' => DB::raw('orders_count + 1'),
                    'loyalty_points' => DB::raw('loyalty_points + '.(int) floor($total / 100)),
                ]);
            }

            return $sale;
        }));

        ActivityLog::log('created', 'Sale', "POS sale {$sale->invoice_no} — {$sale->total} AFN");

        // The bill names the seat and the branch it was rung on, so a customer
        // coming back with a question can be traced to the right till.
        $sale->load([
            'items', 'payments',
            'customer:id,name,phone,total_spent,loyalty_points',
            'cashier:id,name', 'counter:id,name', 'branch:id,name',
        ]);
        // The receipt can print the badge the customer holds, so send it along.
        if ($sale->customer) {
            $sale->customer->setAttribute('loyalty', \App\Support\Loyalty::summary($sale->customer));
        }

        return response()->json($sale, 201);
    }

    private function nextInvoiceNo(int $companyId): string
    {
        // The invoice sequence is COMPANY-wide (that is what the unique index
        // covers), so it must ignore the active-branch scope — otherwise a
        // cashier in branch B redraws a number branch A already used, and no
        // amount of retrying helps because the scoped maximum never moves.
        // lockForUpdate serializes concurrent draws on engines with row locks;
        // the (company_id, invoice_no) unique index is the final referee.
        $last = Sale::withTrashed()
            ->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('company_id', $companyId)
            ->orderByDesc('id')->lockForUpdate()->value('invoice_no');
        $n = 0;
        if ($last && preg_match('/(\d+)$/', $last, $m)) {
            $n = (int) $m[1];
        }

        return 'INV-'.str_pad((string) ($n + 1), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Run the checkout; when two counters draw the same invoice number the
     * unique index throws, and the whole transaction is retried with a fresh
     * number. Any other failure propagates untouched.
     */
    private function withInvoiceRetry(callable $checkout, int $attempts = 5)
    {
        for ($try = 1; ; $try++) {
            try {
                return $checkout();
            } catch (\Illuminate\Database\QueryException $e) {
                $duplicateInvoice = str_contains($e->getMessage(), 'invoice_no')
                    && in_array((string) $e->getCode(), ['23000', '23505'], true);
                if (! $duplicateInvoice || $try >= $attempts) {
                    throw $e;
                }
            }
        }
    }
}
