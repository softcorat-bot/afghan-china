<?php

use App\Models\CashMovement;
use App\Models\CounterEndOfDay;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\StockAdjustment;

return [

    /*
    |--------------------------------------------------------------------------
    | Offline Mode
    |--------------------------------------------------------------------------
    | The SAME Laravel application, running on the shop's own PC against its
    | own local SQLite file. When true, every local write is captured into the
    | offline_outbox and the Sync Center (UI + API + CLI) becomes available.
    | When false, everything in this module is inert: no observers, no routes,
    | no behavior change to the Online application whatsoever.
    */
    'enabled' => filter_var(env('OFFLINE_MODE', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Central server
    |--------------------------------------------------------------------------
    | Where this installation pushes its work and pulls shared changes from.
    | Example: https://mis.afghanchina.af
    */
    'central_url' => rtrim((string) env('CENTRAL_URL', ''), '/'),

    // Device identity. ENV wins when set (containers, testing); otherwise the
    // installer/registration stores it in storage/app/offline/device.json (0600).
    'device_id' => env('OFFLINE_DEVICE_ID'),
    'device_token' => env('OFFLINE_DEVICE_TOKEN'),

    'app_version' => env('OFFLINE_APP_VERSION', '1.0.0'),

    // HTTP + batching knobs. Push batches stay well under the server's
    // sync.max_push_batch (500); pull pages under sync.max_pull_limit (2000).
    'timeout' => (int) env('OFFLINE_HTTP_TIMEOUT', 30),
    'connect_timeout' => (int) env('OFFLINE_HTTP_CONNECT_TIMEOUT', 8),
    'push_batch' => (int) env('OFFLINE_PUSH_BATCH', 200),
    'pull_limit' => (int) env('OFFLINE_PULL_LIMIT', 500),

    // Minutes between automatic sync attempts (0 = manual "Sync Now" only).
    'auto_sync_minutes' => (int) env('OFFLINE_AUTO_SYNC_MINUTES', 0),

    // How many local SQLite backups to keep (Sync Center + CLI + scheduler).
    'keep_backups' => (int) env('OFFLINE_KEEP_BACKUPS', 14),

    /*
    |--------------------------------------------------------------------------
    | Outbox capture: model => sync entity_type
    |--------------------------------------------------------------------------
    | Writes to these models (in Offline Mode) are captured into offline_outbox.
    | Children (sale items, payments, refund lines) travel INSIDE their parent
    | payload — one atomic business unit, one idempotency key — so they are not
    | observed individually.
    */
    'observed' => [
        Customer::class => 'customer',
        Product::class => 'product',
        Shift::class => 'cash_session',
        Sale::class => 'sale',
        Refund::class => 'refund',
        CashMovement::class => 'cash_movement',
        StockAdjustment::class => 'stock_movement',
        Expense::class => 'expense',
        CounterEndOfDay::class => 'counter_end_of_day',
    ],

    /*
    | Updates are captured ONLY for these entities. Financial documents are
    | append-only: a pushed sale/refund/payment is never "edited" into Central
    | (corrections are business documents — refunds, adjustments — not UPDATEs).
    | Shifts are the exception: open → closed is a supported server transition.
    */
    'updatable_entities' => ['customer', 'product', 'cash_session'],

    // Soft-deletes captured as tombstones (server destroys nothing silently).
    'deletable_entities' => ['customer', 'product'],

    /*
    |--------------------------------------------------------------------------
    | Push order (parents before children, masters before transactions)
    |--------------------------------------------------------------------------
    | A sale references a customer/shift/counter by uuid; the server resolves
    | them at apply time, so the referenced rows must be pushed first.
    */
    'push_order' => [
        'customer',
        'product',
        'cash_session',
        'sale',
        'refund',
        'cash_movement',
        'stock_movement',
        'expense',
        'counter_end_of_day',
    ],

    /*
    |--------------------------------------------------------------------------
    | Pull apply order (parents before children)
    |--------------------------------------------------------------------------
    | Inserts preserve server ids, so the referenced row must exist first.
    */
    'pull_order' => [
        'branches',
        'users',
        'counters',
        'product_categories',
        'products',
        'suppliers',
        'customers',
        'shifts',
        'sales',
        'sale_items',
        'sale_payments',
        'refunds',
        'refund_items',
        'cash_movements',
        'stock_adjustments',
        'stock_transfers',
        'stock_transfer_items',
        'purchases',
        'purchase_items',
        'purchase_returns',
        'purchase_return_items',
        'expenses',
        'inventory_expiry_batches',
        'counter_end_of_day',
    ],
];
