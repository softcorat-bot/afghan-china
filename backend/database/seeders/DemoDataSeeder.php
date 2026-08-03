<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo business data so every screen lights up on a fresh install:
 * two weeks of retail sales across the three counters, two wholesale
 * accounts with an invoice / quotation / partial payment, and owner
 * main prices on part of the catalog. Deterministic (seeded RNG) and
 * idempotent — demo bills carry DEMO-/WSD- numbers and are skipped if
 * they already exist. Stock figures are the catalog's demo levels and
 * are not replayed through this history.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('abbreviation', 'ACSC')->first();
        if (! $company) {
            return;
        }
        $companyId = $company->id;

        mt_srand(20260723); // deterministic demo

        $products = Product::withoutGlobalScopes()->where('company_id', $companyId)->get();
        if ($products->isEmpty()) {
            return;
        }

        // ── Owner main prices (real cost ≈ 78–88% of declared) on part of the catalog ──
        foreach ($products->take(8) as $i => $p) {
            if ($p->main_price === null && $p->cost_price > 0) {
                $factor = [0.80, 0.85, 0.78, 0.88, 0.82, 0.84, 0.79, 0.86][$i % 8];
                $p->forceFill(['main_price' => round((float) $p->cost_price * $factor, 2)])->save();
            }
        }

        // ── Wholesale accounts ──
        $karimi = Customer::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $companyId, 'phone' => '0700555001'],
            [
                'name' => 'Ahmad Karimi', 'type' => 'wholesale',
                'company_name' => 'Karimi General Store', 'contact_person' => 'Ahmad Karimi',
                'credit_limit' => 50000, 'payment_terms' => 'net-15', 'active' => true,
            ]
        );
        $herat = Customer::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $companyId, 'phone' => '0700555002'],
            [
                'name' => 'Waheed Noori', 'type' => 'wholesale',
                'company_name' => 'Herat Trading Co', 'contact_person' => 'Waheed Noori',
                'credit_limit' => 80000, 'payment_terms' => 'net-30', 'active' => true,
            ]
        );

        // ── Two weeks of retail sales across the counter seats ──
        if (! Sale::withoutGlobalScopes()->where('company_id', $companyId)->where('invoice_no', 'like', 'DEMO-%')->exists()) {
            $counters = User::where('company_id', $companyId)
                ->whereIn('email', ['counter1@afghanchina.af', 'counter2@afghanchina.af', 'counter3@afghanchina.af'])
                ->pluck('id')->values();
            // cashier user -> the physical seat they usually run
            $seatByUser = [];
            foreach ([1 => 'A', 2 => 'B', 3 => 'C'] as $n => $letter) {
                $uid = User::where('company_id', $companyId)->where('email', "counter{$n}@afghanchina.af")->value('id');
                $seat = \App\Models\Counter::withoutGlobalScopes()
                    ->where('company_id', $companyId)->where('name', "Counter {$letter}")->value('id');
                if ($uid && $seat) {
                    $seatByUser[$uid] = $seat;
                }
            }
            $methods = ['cash', 'cash', 'cash', 'card', 'mobile'];
            $seq = 1;

            for ($daysAgo = 13; $daysAgo >= 0; $daysAgo--) {
                $day = now()->subDays($daysAgo);
                $salesCount = mt_rand(3, 7);

                for ($s = 0; $s < $salesCount; $s++) {
                    $soldAt = $day->copy()->setTime(mt_rand(8, 19), mt_rand(0, 59));
                    $lines = [];
                    $subtotal = 0.0;

                    foreach ($products->random(mt_rand(1, 3)) as $p) {
                        $qty = mt_rand(1, 4);
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

                    $total = round($subtotal, 2);
                    $sellerId = $counters->isNotEmpty() ? $counters[mt_rand(0, $counters->count() - 1)] : null;
                    $sale = Sale::create([
                        'company_id' => $companyId,
                        'branch_id' => 1,
                        'counter_id' => $sellerId ? ($seatByUser[$sellerId] ?? null) : null,
                        'invoice_no' => 'DEMO-'.str_pad((string) $seq++, 6, '0', STR_PAD_LEFT),
                        'user_id' => $sellerId,
                        'sold_at' => $soldAt,
                        'subtotal' => $total,
                        'discount' => 0,
                        'tax' => 0,
                        'total' => $total,
                        'paid' => $total,
                        'change_due' => 0,
                        'status' => 'completed',
                        'channel' => 'retail',
                    ]);
                    $sale->items()->createMany($lines);
                    $sale->payments()->create([
                        'company_id' => $companyId,
                        'method' => $methods[mt_rand(0, count($methods) - 1)],
                        'amount' => $total,
                    ]);
                }
            }
        }

        // ── One wholesale invoice (partial paid) + one open quotation ──
        if (! Sale::withoutGlobalScopes()->where('company_id', $companyId)->where('invoice_no', 'like', 'WSD-%')->exists()) {
            $picks = $products->take(3);
            $subtotal = 0.0;
            $lines = [];
            foreach ($picks as $p) {
                $qty = mt_rand(20, 60);
                $unit = round((float) ($p->wholesale_price ?? $p->sale_price), 2);
                $lineTotal = round($unit * $qty, 2);
                $subtotal += $lineTotal;
                $lines[] = [
                    'company_id' => $companyId, 'product_id' => $p->id, 'name' => $p->name,
                    'barcode' => $p->barcode, 'unit_price' => $unit, 'cost_price' => (float) $p->cost_price,
                    'qty' => $qty, 'discount' => 0, 'tax' => 0, 'line_total' => $lineTotal,
                ];
            }
            $total = round($subtotal, 2);
            $paid = round($total * 0.6, 2);

            $inv = Sale::create([
                'company_id' => $companyId, 'branch_id' => 1,
                'invoice_no' => 'WSD-000001', 'customer_id' => $karimi->id,
                'user_id' => User::where('company_id', $companyId)->where('email', 'admin@afghanchina.af')->value('id'),
                'sold_at' => now()->subDays(3)->setTime(11, 20),
                'subtotal' => $total, 'discount' => 0, 'tax' => 0,
                'total' => $total, 'paid' => $paid, 'change_due' => 0,
                'status' => 'completed', 'channel' => 'wholesale',
            ]);
            $inv->items()->createMany($lines);
            $inv->payments()->create(['company_id' => $companyId, 'method' => 'cash', 'amount' => $paid]);
            $karimi->balance = (float) $karimi->balance + ($total - $paid);
            $karimi->total_spent = (float) $karimi->total_spent + $total;
            $karimi->orders_count = (int) $karimi->orders_count + 1;
            $karimi->save();

            // Open quotation for the second account (no stock / balance effect).
            $q = $products->skip(4)->take(2);
            $qsub = 0.0;
            $qlines = [];
            foreach ($q as $p) {
                $qty = mt_rand(30, 80);
                $unit = round((float) ($p->wholesale_price ?? $p->sale_price), 2);
                $qsub += $unit * $qty;
                $qlines[] = [
                    'company_id' => $companyId, 'product_id' => $p->id, 'name' => $p->name,
                    'barcode' => $p->barcode, 'unit_price' => $unit, 'cost_price' => (float) $p->cost_price,
                    'qty' => $qty, 'discount' => 0, 'tax' => 0, 'line_total' => round($unit * $qty, 2),
                ];
            }
            $quote = Sale::create([
                'company_id' => $companyId, 'branch_id' => 1,
                'invoice_no' => 'WSD-000002', 'customer_id' => $herat->id,
                'user_id' => $inv->user_id,
                'sold_at' => null,
                'subtotal' => round($qsub, 2), 'discount' => 0, 'tax' => 0,
                'total' => round($qsub, 2), 'paid' => 0, 'change_due' => 0,
                'status' => 'quote', 'channel' => 'wholesale',
            ]);
            $quote->items()->createMany($qlines);
        }
    }
}
