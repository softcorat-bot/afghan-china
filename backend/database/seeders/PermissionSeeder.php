<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    /**
     * Entities that exist in this platform scaffold. Products/Inventory,
     * POS, Sales, Purchases, HR... entities are added here once those
     * modules ship.
     */
    protected array $entities = [
        'company', 'user', 'role', 'branch',
        // Retail / POS core
        'product', 'category', 'pos', 'sale', 'customer', 'shift', 'wholesale',
        'expense',
        // Purchasing & inventory
        'supplier', 'purchase', 'stock-adjustment', 'transfer',
        // HR
        'attendance', 'payroll',
        // Reports & analytics
        'report',
        'currency', 'exchange-rate',
        'backup', 'log', 'notification', 'dashboard',
        // Offline POS fleet & the sync conflict centre
        'device', 'sync-conflict', 'sync-log',
        // System feature visibility — a Super Admin unticks the "-list" box in
        // a role to hide the feature for that role's users.
        'theme', 'language', 'lang-en', 'lang-fa', 'lang-pa', 'lang-zh',
    ];

    protected array $actions = ['list', 'create', 'edit', 'show', 'delete'];

    /** Extra permissions that don't fit the entity-action grid. */
    protected array $custom = [
        // Register a sale at the POS, apply a manual discount, refund a sale.
        'pos-sell', 'pos-discount', 'sale-refund',
        // Receive a purchase into stock.
        'purchase-receive',
        // View/switch across ALL branches (otherwise pinned to assigned branches).
        'all-branches',
        // Approve/reject a counter's end-of-day cash submission.
        'approve-counter-reports',
        // The owner's private Main Cost perspective. Deliberately NOT granted
        // to Super Admin — only the Platform Owner (or someone the owner
        // explicitly grants these to) may hold them. 'main-cost' = view the
        // financial mirror; 'main-cost-edit' = change real costs.
        'main-cost', 'main-cost-edit',
        // Offline POS: authorize/disable a till, and decide what happens when a
        // device and the server disagree. Financial conflicts need the extra
        // override permission, held by the owner alone by default.
        'manage-devices', 'sync-now', 'resolve-sync-conflicts', 'override-financial-sync-conflicts'];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Trash moved out of the role grid — it's Super-Admin-only now.
        Permission::where('name', 'like', 'trash-%')->delete();

        foreach ($this->entities as $entity) {
            foreach ($this->actions as $action) {
                Permission::firstOrCreate([
                    'name' => "{$entity}-{$action}",
                    'guard_name' => 'web',
                ]);
            }
        }

        foreach ($this->custom as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $admin = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $admin->syncPermissions(Permission::whereNotIn('name', ['main-cost', 'main-cost-edit'])->get());

        // VIP — the owner's trusted seat: sees AND edits the Main Price
        // (real cost), plus the full financial/reporting surface.
        $vip = Role::firstOrCreate(['name' => 'VIP', 'guard_name' => 'web']);
        $vip->syncPermissions([
            'dashboard-list', 'report-list',
            'expense-list', 'expense-create', 'expense-edit', 'expense-delete', 'expense-show',
            'main-cost', 'main-cost-edit',
            'attendance-list', 'attendance-create', 'attendance-edit', 'attendance-show',
            'payroll-list', 'payroll-create', 'payroll-edit', 'payroll-show',
            'wholesale-list', 'wholesale-create', 'wholesale-edit', 'wholesale-show',
            'product-list', 'product-show', 'category-list',
            'sale-list', 'sale-show', 'purchase-list', 'supplier-list',
            'currency-list', 'exchange-rate-list', 'branch-list',
            'device-list', 'device-show', 'sync-conflict-list', 'sync-conflict-show',
            'sync-conflict-edit', 'sync-log-list',
            'manage-devices', 'sync-now', 'resolve-sync-conflicts', 'override-financial-sync-conflicts',
            'notification-list', 'theme-list',
            'language-list', 'lang-en-list', 'lang-fa-list', 'lang-pa-list', 'lang-zh-list',
        ]);

        // Finance Manager — the reporting/finance surface at NORMAL prices.
        // The Main Cost mirror is deliberately NOT granted: the owner's real
        // buy price is reserved for the Platform Owner and the VIP seat.
        $finance = Role::firstOrCreate(['name' => 'Finance Manager', 'guard_name' => 'web']);
        $finance->syncPermissions([
            'dashboard-list', 'report-list',
            'expense-list', 'expense-create', 'expense-edit', 'expense-delete', 'expense-show',
            'product-list', 'product-show', 'category-list',
            'sale-list', 'sale-show', 'purchase-list', 'supplier-list',
            'currency-list', 'exchange-rate-list',
            'notification-list', 'theme-list',
            'language-list', 'lang-en-list', 'lang-fa-list', 'lang-pa-list', 'lang-zh-list',
        ]);

        // Accountant — reports only, at normal prices (no Main Cost mirror).
        $accountant = Role::firstOrCreate(['name' => 'Accountant', 'guard_name' => 'web']);
        $accountant->syncPermissions([
            'dashboard-list', 'report-list',
            'expense-list', 'expense-show',
            'product-list', 'product-show', 'category-list',
            'sale-list', 'sale-show', 'purchase-list', 'supplier-list',
            'currency-list', 'exchange-rate-list',
            'notification-list', 'theme-list',
            'language-list', 'lang-en-list', 'lang-fa-list', 'lang-pa-list', 'lang-zh-list',
        ]);

        // Counter (cashier) — the register seat: POS selling, own shifts,
        // sales lookup and walk-in customer capture. No catalog/admin access.
        $counter = Role::firstOrCreate(['name' => 'Counter', 'guard_name' => 'web']);
        $counter->syncPermissions([
            'dashboard-list',
            'pos-list', 'pos-sell', 'pos-discount',
            'shift-list', 'shift-create', 'shift-edit', 'shift-show',
            'sale-list', 'sale-show',
            'customer-list', 'customer-create',
            'notification-list', 'theme-list',
            'language-list', 'lang-en-list', 'lang-fa-list', 'lang-pa-list', 'lang-zh-list',
        ]);
    }
}
