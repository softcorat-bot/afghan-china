<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PermissionSeeder::class);

        $company = Company::firstOrCreate(
            ['name_en' => 'Afghan China Shopping Center'],
            [
                'name_fa' => 'مرکز تجارتی افغان چین',
                'abbreviation' => 'ACSC',
                'business_type' => 'retail',
                'is_main' => true,
                'lang' => 'en',
                'calendar_type' => 'en',
                'currency' => 'AFN',
                'city' => 'Kabul',
                'country' => 'Afghanistan',
            ]
        );

        $branch = Branch::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Main Branch'],
            ['active' => true]
        );

        $admin = User::withTrashed()->firstOrCreate(
            ['email' => 'admin@afghanchina.af'],
            [
                'name' => 'Administrator',
                'password' => Hash::make('password'),
                'company_id' => $company->id,
                'current_company' => $company->id,
                'type' => 'admin',
            ]
        );

        $admin->companies()->syncWithoutDetaching([$company->id]);
        $admin->assignRole('Super Admin');

        // Platform Owner (VIP Root) — the immutable root of the platform.
        // Its authority comes from its email (config/platform.php), not a role.
        $owner = User::withTrashed()->firstOrCreate(
            ['email' => config('platform.owner_email', 'support@briskcodes.com')],
            [
                'name' => 'Platform Owner',
                'password' => Hash::make('password'),
                'company_id' => $company->id,
                'current_company' => $company->id,
                'type' => 'admin',
                'is_super_admin' => true,
            ]
        );
        $owner->companies()->syncWithoutDetaching([$company->id]);

        // Finance seats — the authorized Main Cost perspectives.
        foreach ([['vip@afghanchina.af', 'VIP'], ['finance@afghanchina.af', 'Finance Manager'], ['accountant@afghanchina.af', 'Accountant']] as [$email, $role]) {
            $fin = User::withTrashed()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => $role,
                    'password' => Hash::make('password'),
                    'company_id' => $company->id,
                    'current_company' => $company->id,
                    'type' => 'user',
                ]
            );
            $fin->companies()->syncWithoutDetaching([$company->id]);
            $fin->assignRole($role);
        }

        // Physical counter seats (the registers themselves) — lettered, per
        // the owner: Counter A, B, C rather than 1, 2, 3.
        foreach (['A', 'B', 'C'] as $letter) {
            \App\Models\Counter::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'name' => "Counter {$letter}"],
                ['branch_id' => $branch->id, 'active' => true]
            );
        }

        // Standard POS counter seats — Counter A..C, each a cashier login
        // pinned to the main branch (counter1@afghanchina.af / password ...).
        foreach ([1 => 'A', 2 => 'B', 3 => 'C'] as $n => $letter) {
            $counter = User::withTrashed()->firstOrCreate(
                ['email' => "counter{$n}@afghanchina.af"],
                [
                    'name' => "Counter {$letter}",
                    'password' => Hash::make('password'),
                    'company_id' => $company->id,
                    'current_company' => $company->id,
                    'current_branch' => $branch->id,
                    'type' => 'user',
                ]
            );
            $counter->companies()->syncWithoutDetaching([$company->id]);
            $counter->branches()->syncWithoutDetaching([$branch->id]);
            $counter->assignRole('Counter');

            // Demo PIN for the register terminal: 1111 / 2222 / 3333.
            if (! $counter->pin) {
                $counter->forceFill([
                    'pin' => Hash::make(str_repeat((string) $n, 4)),
                    'pin_set_at' => now(),
                ])->save();
            }
        }

        // Base currency + a sample USD daily rate so money forms work day one.
        Tenant::set($company->id);

        // The three trading currencies of the shopping center:
        // Afghani (base), US Dollar, and Chinese Yuan (goods from China).
        Currency::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'AFN'],
            ['name' => 'Afghani', 'symbol' => '؋', 'is_base' => true, 'active' => true]
        );
        Currency::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'USD'],
            ['name' => 'US Dollar', 'symbol' => '$', 'is_base' => false, 'active' => true]
        );
        Currency::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'CNY'],
            ['name' => 'Chinese Yuan', 'symbol' => '¥', 'is_base' => false, 'active' => true]
        );
        ExchangeRate::firstOrCreate(
            ['company_id' => $company->id, 'currency_code' => 'USD', 'rate_date' => now()->toDateString()],
            ['rate_to_base' => 70]
        );
        ExchangeRate::firstOrCreate(
            ['company_id' => $company->id, 'currency_code' => 'CNY', 'rate_date' => now()->toDateString()],
            ['rate_to_base' => 9.7]
        );

        Tenant::clear();

        // The catalog and its photos are the starting point of any install —
        // a shop with no products cannot be set up at all.
        $this->call(CatalogDemoSeeder::class);
        $this->call(ProductImageSeeder::class);

        // The invented *business history*, though, must never land in a real
        // shop's books: hundreds of fake sales would corrupt every report the
        // owner reads. Off in production unless SEED_DEMO_DATA=true.
        if (config('platform.demo_data')) {
            $this->call(DemoDataSeeder::class);
            $this->call(SupplyChainDemoSeeder::class);
        } else {
            $this->command?->warn('  Demo business history skipped (APP_ENV=production).');
            $this->command?->line('  Set SEED_DEMO_DATA=true in .env if you want the demo sales, expenses and loyalty customers.');
        }
    }
}
