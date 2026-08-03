<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Counter;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Database\Seeder;

/**
 * Today's trading, on demand.
 *
 * The main demo seeder builds fourteen days ending at the moment it runs, so an
 * install seeded last week shows an empty "today" — which makes the owner's
 * daily net-profit view look broken when it is merely idle. This seeder tops up
 * *today* and is safe to run again whenever a demo needs fresh numbers.
 *
 * It also gives the company a second branch with its own counters, so the
 * branch and counter filters have something real to compare.
 *
 *     php artisan db:seed --class=TodayDemoSeeder
 */
class TodayDemoSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::withoutGlobalScopes()->where('is_main', true)->first()
            ?? Company::withoutGlobalScopes()->first();
        if (! $company) {
            $this->command?->warn('No company — run the main seeder first.');

            return;
        }
        Tenant::set($company->id);

        $products = Product::withoutGlobalScopes()->where('company_id', $company->id)->get();
        if ($products->isEmpty()) {
            $this->command?->warn('No products — run the main seeder first.');

            return;
        }

        $branches = $this->branches($company->id);
        $counters = $this->counters($company->id, $branches);
        $cashiers = User::withTrashed()->where('company_id', $company->id)
            ->whereIn('email', ['counter1@afghanchina.af', 'counter2@afghanchina.af', 'counter3@afghanchina.af'])
            ->pluck('id', 'email')->values();

        // Start clean so re-running does not pile today's takings up twice.
        $wiped = Sale::withoutGlobalScope(BranchScope::class)
            ->where('company_id', $company->id)
            ->where('invoice_no', 'like', 'TODAY-%')
            ->whereDate('sold_at', today())->forceDelete();
        if ($wiped) {
            $this->command?->line("  cleared {$wiped} previous demo sale(s) for today");
        }

        $methods = ['cash', 'cash', 'cash', 'card', 'mobile'];
        $seq = 1;
        $made = 0;

        foreach ($counters as $counter) {
            // Different seats do different trade — that is the point of
            // comparing them, so give each its own volume.
            $howMany = mt_rand(4, 9);

            for ($i = 0; $i < $howMany; $i++) {
                $soldAt = today()->copy()->setTime(mt_rand(8, min(19, max(9, (int) now()->format('H')))), mt_rand(0, 59));
                $lines = [];
                $subtotal = 0.0;

                foreach ($products->random(mt_rand(1, 4)) as $p) {
                    $qty = mt_rand(1, 5);
                    $lineTotal = round((float) $p->sale_price * $qty, 2);
                    $subtotal += $lineTotal;
                    $lines[] = [
                        'company_id' => $company->id,
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
                $sale = Sale::create([
                    'company_id' => $company->id,
                    'branch_id' => $counter->branch_id,
                    'counter_id' => $counter->id,
                    'invoice_no' => 'TODAY-'.str_pad((string) $seq++, 6, '0', STR_PAD_LEFT),
                    'user_id' => $cashiers[$seq % max(1, $cashiers->count())] ?? null,
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
                    'company_id' => $company->id,
                    'method' => $methods[mt_rand(0, count($methods) - 1)],
                    'amount' => $total,
                ]);
                $made++;
            }
        }

        // Today's running costs, so net profit is a real subtraction rather
        // than revenue wearing a different name.
        foreach ($branches as $branch) {
            foreach ([['rent', 1200], ['electricity', 450], ['transport', 300]] as [$cat, $amount]) {
                Expense::withoutGlobalScope(BranchScope::class)->firstOrCreate(
                    [
                        'company_id' => $company->id,
                        'branch_id' => $branch->id,
                        'category' => $cat,
                        'spent_on' => today()->toDateString(),
                    ],
                    ['amount' => $amount, 'payee' => 'Demo', 'method' => 'cash']
                );
            }
        }

        Tenant::clear();

        $this->command?->info("Today: {$made} sale(s) across {$counters->count()} counter(s) in {$branches->count()} branch(es), plus today's expenses.");
    }

    /** The main branch, plus a second one so branch filtering is meaningful. */
    private function branches(int $companyId)
    {
        $main = Branch::withoutGlobalScopes()->where('company_id', $companyId)->orderBy('id')->first();
        if (! $main) {
            $main = Branch::withoutGlobalScopes()->create([
                'company_id' => $companyId, 'name' => 'Main Branch', 'active' => true,
            ]);
        }

        Branch::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $companyId, 'name' => 'City Center Branch'],
            ['active' => true, 'address' => 'Kabul City Center']
        );

        return Branch::withoutGlobalScopes()->where('company_id', $companyId)->orderBy('id')->get();
    }

    /** Every branch gets lettered seats — Counter A, B, (C on the main floor). */
    private function counters(int $companyId, $branches)
    {
        foreach ($branches as $i => $branch) {
            $letters = $i === 0 ? ['A', 'B', 'C'] : ['D', 'E'];
            foreach ($letters as $letter) {
                Counter::withoutGlobalScopes()->firstOrCreate(
                    ['company_id' => $companyId, 'name' => "Counter {$letter}"],
                    ['branch_id' => $branch->id, 'active' => true]
                );
            }
        }

        return Counter::withoutGlobalScopes()->where('company_id', $companyId)
            ->orderBy('branch_id')->orderBy('name')->get();
    }
}
