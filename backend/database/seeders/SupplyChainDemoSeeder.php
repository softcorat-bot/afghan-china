<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Customer;
use App\Models\MainPriceLog;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\StockAdjustment;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The other half of the demo story: where the stock came from and how it
 * moved. Without this the per-product history tabs (purchases, stock,
 * transfers, price journal) are empty on a fresh install and read as broken.
 *
 * Also seeds retail customers spread across the loyalty tiers so the badge
 * and gift-eligibility rules are visible immediately.
 *
 * Deterministic and idempotent — every document carries a DEMO reference and
 * is skipped if it already exists.
 */
class SupplyChainDemoSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('abbreviation', 'ACSC')->first();
        if (! $company) {
            return;
        }
        $companyId = $company->id;

        mt_srand(20260725); // deterministic demo

        $products = Product::withoutGlobalScopes()->where('company_id', $companyId)->orderBy('id')->get();
        $suppliers = Supplier::withoutGlobalScopes()->where('company_id', $companyId)->orderBy('id')->get();
        if ($products->isEmpty() || $suppliers->isEmpty()) {
            return;
        }

        $admin = User::where('company_id', $companyId)->where('email', 'admin@afghanchina.af')->first();
        $owner = User::where('email', 'vip@afghanchina.af')->first() ?? $admin;
        if (! $admin) {
            return;
        }

        $this->purchases($companyId, $products, $suppliers, $admin->id);
        $this->transfers($companyId, $products, $admin->id);
        $this->adjustments($companyId, $products, $admin->id);
        $this->priceJournal($companyId, $products, $owner->id);
        $this->loyaltyCustomers($companyId, $products, $admin->id);
        $this->expenses($companyId, $admin->id);
        $this->salaries($companyId);
    }

    /** Four received purchase orders spread over the last two months. */
    private function purchases(int $companyId, $products, $suppliers, int $userId): void
    {
        if (Purchase::withoutGlobalScopes()->where('company_id', $companyId)
            ->where('reference', 'like', 'DEMO-PO-%')->exists()) {
            return;
        }

        $chunks = $products->chunk(max(3, (int) ceil($products->count() / 4)));

        foreach ($chunks as $n => $lines) {
            $supplier = $suppliers[$n % $suppliers->count()];
            $daysAgo = [58, 41, 24, 9][$n % 4];

            $subtotal = 0;
            $rows = [];
            foreach ($lines as $p) {
                // Bought a little cheaper than the declared cost, in whole cartons.
                $unit = round((float) $p->cost_price * (1 - (mt_rand(2, 9) / 100)), 2);
                $qty = mt_rand(2, 10) * 6;
                $total = round($unit * $qty, 2);
                $subtotal += $total;
                $rows[] = [
                    'company_id' => $companyId,
                    'product_id' => $p->id,
                    'name' => $p->name,
                    'cost_price' => $unit,
                    'qty' => $qty,
                    'discount' => 0,
                    'tax' => 0,
                    'line_total' => $total,
                    'returned_qty' => 0,
                ];
            }

            $purchase = Purchase::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'reference' => 'DEMO-PO-'.str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT),
                'supplier_invoice' => 'SI-'.mt_rand(10000, 99999),
                'supplier_id' => $supplier->id,
                'user_id' => $userId,
                'purchased_at' => now()->subDays($daysAgo),
                'subtotal' => round($subtotal, 2),
                'discount' => 0,
                'tax' => 0,
                'total' => round($subtotal, 2),
                // The two oldest are settled, the newest still owes.
                'paid' => $n < 2 ? round($subtotal, 2) : round($subtotal * 0.5, 2),
                'status' => 'received',
                'note' => 'Demo purchase — received into stock',
                'created_at' => now()->subDays($daysAgo),
                'updated_at' => now()->subDays($daysAgo),
            ]);

            foreach ($rows as $row) {
                PurchaseItem::withoutGlobalScopes()->create($row + [
                    'purchase_id' => $purchase->id,
                    'created_at' => now()->subDays($daysAgo),
                    'updated_at' => now()->subDays($daysAgo),
                ]);
            }
        }
    }

    /** Warehouse → shop replenishment, plus one inbound receiving document. */
    private function transfers(int $companyId, $products, int $userId): void
    {
        if (StockTransfer::withoutGlobalScopes()->where('company_id', $companyId)
            ->where('reference', 'like', 'DEMO-TR-%')->exists()) {
            return;
        }

        $plan = [
            ['direction' => 'in', 'source' => 'Guangzhou · China', 'destination' => 'warehouse', 'days' => 46, 'take' => 8],
            ['direction' => 'out', 'source' => 'warehouse', 'destination' => 'shop', 'days' => 30, 'take' => 6],
            ['direction' => 'out', 'source' => 'warehouse', 'destination' => 'shop', 'days' => 17, 'take' => 5],
            ['direction' => 'out', 'source' => 'warehouse', 'destination' => 'shop', 'days' => 4, 'take' => 7],
        ];

        foreach ($plan as $n => $doc) {
            $lines = $products->shuffle()->take($doc['take']);
            $at = now()->subDays($doc['days']);

            $transfer = StockTransfer::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'reference' => 'DEMO-TR-'.str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT),
                'direction' => $doc['direction'],
                'source' => $doc['source'],
                'destination' => $doc['destination'],
                'user_id' => $userId,
                'total_qty' => 0,
                'lines_count' => $lines->count(),
                'note' => 'Demo movement',
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            $totalQty = 0;
            foreach ($lines as $p) {
                $qty = mt_rand(3, 24);
                $totalQty += $qty;
                StockTransferItem::withoutGlobalScopes()->create([
                    'company_id' => $companyId,
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => $p->id,
                    'name' => $p->name,
                    'barcode' => $p->barcode,
                    'qty' => $qty,
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
            }
            $transfer->forceFill(['total_qty' => $totalQty])->save();
        }
    }

    /** A handful of counts and write-offs, with the reasons a shop really uses. */
    private function adjustments(int $companyId, $products, int $userId): void
    {
        if (StockAdjustment::withoutGlobalScopes()->where('company_id', $companyId)
            ->where('note', 'Demo adjustment')->exists()) {
            return;
        }

        $reasons = [
            ['increase', 'Stock count correction', 'shop'],
            ['decrease', 'Damaged in transit', 'warehouse'],
            ['decrease', 'Expired', 'shop'],
            ['increase', 'Found in storeroom', 'warehouse'],
            ['decrease', 'Sample given to customer', 'shop'],
            ['decrease', 'Broken on the shelf', 'shop'],
        ];

        foreach ($reasons as $n => [$type, $reason, $location]) {
            $p = $products[($n * 3) % $products->count()];
            $qty = mt_rand(1, 6);
            $before = (float) $p->stock_qty;
            $after = $type === 'increase' ? $before + $qty : max(0, $before - $qty);
            $at = now()->subDays([52, 38, 27, 19, 11, 3][$n % 6]);

            StockAdjustment::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'product_id' => $p->id,
                'user_id' => $userId,
                'type' => $type,
                'reason' => $reason,
                'qty' => $qty,
                'stock_before' => $before,
                'stock_after' => $after,
                'location' => $location,
                'note' => 'Demo adjustment',
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }
    }

    /** The owner's private Main Price journal — two revisions per product. */
    private function priceJournal(int $companyId, $products, int $userId): void
    {
        if (MainPriceLog::withoutGlobalScopes()->where('company_id', $companyId)->exists()) {
            return;
        }

        foreach ($products->whereNotNull('main_price')->take(8) as $n => $p) {
            $current = (float) $p->main_price;
            // The first entry set the price; the second is the revision that
            // brought it to what the desk shows now.
            $first = round($current * 1.06, 2);
            foreach ([[null, $first, 64], [$first, $current, 21]] as [$old, $new, $days]) {
                $at = now()->subDays($days + $n);
                MainPriceLog::withoutGlobalScopes()->create([
                    'company_id' => $companyId,
                    'product_id' => $p->id,
                    'user_id' => $userId,
                    'old_price' => $old,
                    'new_price' => $new,
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
            }
        }
    }

    /**
     * Retail customers spread across the loyalty tiers, so the badges and
     * gift eligibility are visible on a fresh install rather than theoretical.
     *
     * Their lifetime spend is built from **real sales**, not typed into the
     * customer record: a dashboard that claims 214,500 AFN of loyalty while
     * its own ledger shows nothing is worse than no demo data at all.
     */
    private function loyaltyCustomers(int $companyId, $products, int $userId): void
    {
        // name, phone, roughly this much lifetime spend, over this many months
        $people = [
            ['Fatima Ahmadi', '0700666001', 214_000, 22],
            ['Najibullah Sadat', '0700666002', 128_000, 18],
            ['Zahra Hosseini', '0700666003', 74_000, 14],
            ['Mustafa Rahimi', '0700666004', 52_000, 12],
            ['Sohaila Karimi', '0700666005', 48_000, 11],
            ['Bilal Yousufi', '0700666006', 31_000, 9],
            ['Marwa Azizi', '0700666007', 18_000, 7],
            ['Hamid Sherzai', '0700666008', 9_800, 5],
            ['Roya Naderi', '0700666009', 4_200, 3],
            ['Omid Faizi', '0700666010', 1_100, 2],
        ];

        // Continue the LOY series from whatever is already in the database.
        // Starting at 1 every time is a real bug: a run interrupted half-way
        // leaves some customers with sales and some without, and the next run
        // skips the ones that have them (without advancing the counter) then
        // collides on LOY-000001 for the first one that does not.
        $last = \App\Models\Sale::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('invoice_no', 'like', 'LOY-%')
            ->orderByDesc('invoice_no')->value('invoice_no');
        $seq = ($last && preg_match('/(\d+)$/', $last, $m)) ? ((int) $m[1]) + 1 : 1;

        foreach ($people as [$name, $phone, $target, $months]) {
            $customer = Customer::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $companyId, 'phone' => $phone],
                [
                    'name' => $name,
                    'type' => 'retail',
                    'active' => true,
                    'total_spent' => 0,
                    'orders_count' => 0,
                    'loyalty_points' => 0,
                    'created_at' => now()->subMonths($months)->subDays(mt_rand(1, 25)),
                ]
            );

            // Already has history from a previous run — leave it alone.
            if (\App\Models\Sale::withoutGlobalScopes()
                ->where('customer_id', $customer->id)->exists()) {
                continue;
            }

            $spent = 0.0;
            $orders = 0;
            // Keep buying until the target spend is reached, spread over the
            // months since they became a customer.
            while ($spent < $target && $orders < 220) {
                $soldAt = now()->subDays(mt_rand(0, max(1, $months * 30)))
                    ->setTime(mt_rand(8, 19), mt_rand(0, 59));

                $lines = [];
                $subtotal = 0.0;
                foreach ($products->random(min($products->count(), mt_rand(1, 4))) as $p) {
                    $qty = mt_rand(1, 5);
                    $lineTotal = round((float) $p->sale_price * $qty, 2);
                    $subtotal += $lineTotal;
                    $lines[] = [
                        'company_id' => $companyId,
                        'product_id' => $p->id,
                        'name' => $p->name,
                        'barcode' => $p->barcode,
                        'unit_price' => (float) $p->sale_price,
                        'cost_price' => (float) $p->cost_price,
                        'qty' => $qty,
                        'discount' => 0,
                        'tax' => 0,
                        'line_total' => $lineTotal,
                    ];
                }
                if ($subtotal <= 0) {
                    break;
                }

                $total = round($subtotal, 2);
                $sale = \App\Models\Sale::withoutGlobalScopes()->create([
                    'company_id' => $companyId,
                    'branch_id' => 1,
                    'invoice_no' => 'LOY-'.str_pad((string) $seq++, 6, '0', STR_PAD_LEFT),
                    'customer_id' => $customer->id,
                    'user_id' => $userId,
                    'sold_at' => $soldAt,
                    'subtotal' => $total,
                    'discount' => 0,
                    'tax' => 0,
                    'total' => $total,
                    'paid' => $total,
                    'change_due' => 0,
                    'status' => 'completed',
                    'channel' => 'retail',
                    'created_at' => $soldAt,
                    'updated_at' => $soldAt,
                ]);
                $sale->items()->createMany($lines);
                $sale->payments()->create([
                    'company_id' => $companyId,
                    'method' => ['cash', 'cash', 'cash', 'card', 'mobile'][mt_rand(0, 4)],
                    'amount' => $total,
                ]);

                $spent += $total;
                $orders++;
            }

            // The record now agrees with its own ledger.
            $customer->forceFill([
                'total_spent' => round($spent, 2),
                'orders_count' => $orders,
                'loyalty_points' => (int) floor($spent / 100),
            ])->save();
        }
    }

    /** Three months of the running costs a Kabul shop actually books. */
    private function expenses(int $companyId, int $userId): void
    {
        if (\App\Models\Expense::withoutGlobalScopes()->where('company_id', $companyId)->exists()) {
            return;
        }

        // category, payee, roughly this much, how often (months apart)
        $recurring = [
            ['rent', 'Shahr-e-Naw landlord', 45000, 1],
            ['electricity', 'Breshna', 6200, 1],
            ['water', 'Municipality', 900, 1],
            ['internet', 'Etisalat Business', 2400, 1],
            ['cleaning', 'Daily cleaner', 3500, 1],
        ];
        $occasional = [
            ['transport', 'Truck hire — Guangzhou shipment', 18500],
            ['repairs', 'Cold room compressor', 7400],
            ['marketing', 'Shop banner + flyers', 3100],
            ['government', 'Business licence renewal', 5200],
            ['wages', 'Extra hands, Eid week', 9000],
            ['transport', 'Delivery van fuel', 2600],
            ['other', 'Tea and hospitality', 1200],
        ];

        // The monthly ones, for each of the last three months.
        foreach ([2, 1, 0] as $monthsAgo) {
            foreach ($recurring as $i => [$cat, $payee, $amount, $_]) {
                $day = now()->subMonths($monthsAgo)->startOfMonth()->addDays(2 + $i);
                if ($day->isFuture()) {
                    continue;
                }
                \App\Models\Expense::withoutGlobalScopes()->create([
                    'company_id' => $companyId,
                    'branch_id' => 1,
                    'user_id' => $userId,
                    'spent_on' => $day->toDateString(),
                    'category' => $cat,
                    'payee' => $payee,
                    // A little variance so the chart is not a flat line.
                    'amount' => round($amount * (1 + (mt_rand(-4, 6) / 100)), 2),
                    'method' => $cat === 'rent' ? 'bank' : 'cash',
                    'note' => 'Demo expense',
                ]);
            }
        }

        // And the one-offs, scattered through the period.
        foreach ($occasional as $n => [$cat, $payee, $amount]) {
            \App\Models\Expense::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'branch_id' => 1,
                'user_id' => $userId,
                'spent_on' => now()->subDays(mt_rand(1, 85))->toDateString(),
                'category' => $cat,
                'payee' => $payee,
                'amount' => round($amount * (1 + (mt_rand(-8, 8) / 100)), 2),
                'method' => ['cash', 'cash', 'bank', 'mobile'][mt_rand(0, 3)],
                'note' => 'Demo expense',
            ]);
        }
    }

    /**
     * Monthly salaries for the shop's staff. Without these every payroll run
     * generates as a page of zeros, which reads as a broken module rather than
     * an unconfigured one. Only fills in what has not been set.
     */
    private function salaries(int $companyId): void
    {
        $monthly = [
            'admin@afghanchina.af' => 35000,
            'finance@afghanchina.af' => 28000,
            'accountant@afghanchina.af' => 22000,
            'vip@afghanchina.af' => 40000,
            'counter1@afghanchina.af' => 14000,
            'counter2@afghanchina.af' => 13000,
            'counter3@afghanchina.af' => 12500,
        ];

        foreach ($monthly as $email => $amount) {
            User::where('company_id', $companyId)->where('email', $email)
                ->where(fn ($q) => $q->whereNull('basic_salary')->orWhere('basic_salary', 0))
                ->update(['basic_salary' => $amount]);
        }
    }
}
