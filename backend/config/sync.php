<?php

use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\Counter;
use App\Models\CounterEndOfDay;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\InventoryExpiryBatch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Refund;
use App\Models\RefundItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Shift;
use App\Models\StockAdjustment;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Supplier;
use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Identity columns
    |--------------------------------------------------------------------------
    | Every synchronizable row carries a globally unique `uuid` (never the local
    | auto-increment id), a `revision` for conflict detection, and a `sync_seq`
    | from the global sequence used by incremental pull.
    */
    'key_column' => 'uuid',
    'revision_column' => 'revision',
    'seq_column' => 'sync_seq',
    'origin_column' => 'origin',
    'device_column' => 'device_id',
    'synced_column' => 'synced_at',

    'pull_limit' => 500,
    'max_pull_limit' => 2000,
    'max_push_batch' => 500,
    'idempotency_ttl_days' => 120,

    /*
    |--------------------------------------------------------------------------
    | Syncable tables
    |--------------------------------------------------------------------------
    | These tables are the ones the sync engine may read/write. Anything not
    | listed here is invisible to a device — deliberately: the engine can only
    | touch data someone has reasoned about.
    */
    'tables' => [
        'products' => Product::class,
        'product_categories' => ProductCategory::class,
        'customers' => Customer::class,
        'suppliers' => Supplier::class,
        'users' => User::class,
        'counters' => Counter::class,
        'branches' => Branch::class,
        'sales' => Sale::class,
        'sale_items' => SaleItem::class,
        'sale_payments' => SalePayment::class,
        'refunds' => Refund::class,
        'refund_items' => RefundItem::class,
        'shifts' => Shift::class,
        'cash_movements' => CashMovement::class,
        'stock_adjustments' => StockAdjustment::class,
        'stock_transfers' => StockTransfer::class,
        'stock_transfer_items' => StockTransferItem::class,
        'purchases' => Purchase::class,
        'purchase_items' => PurchaseItem::class,
        'purchase_returns' => PurchaseReturn::class,
        'purchase_return_items' => PurchaseReturnItem::class,
        'expenses' => Expense::class,
        'inventory_expiry_batches' => InventoryExpiryBatch::class,
        'counter_end_of_day' => CounterEndOfDay::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | What flows in which direction
    |--------------------------------------------------------------------------
    | Server → POS: the masters a till needs to keep selling with no internet.
    | POS → Server: transactions, which are append-only and idempotent.
    */
    'pull_tables' => [
        'products', 'product_categories', 'customers', 'suppliers',
        'users', 'counters', 'branches',
    ],

    // Note: a sale's payments and lines travel *inside* the sale change — one
    // atomic unit, one idempotency key. `stock_adjustment` is accepted as an
    // alias of `stock_movement` for devices that already speak that name.
    'push_entities' => [
        'sale', 'refund', 'stock_movement', 'stock_adjustment', 'cash_session',
        'cash_movement', 'customer', 'product', 'expense', 'counter_end_of_day',
    ],

    /*
    |--------------------------------------------------------------------------
    | Conflict policy per entity
    |--------------------------------------------------------------------------
    | server_wins  — the central value is authoritative (master data edited on
    |                the server wins; the device is told to refresh).
    | client_wins  — the device is allowed to correct this field.
    | field_merge  — merge field by field using `merge_fields` below.
    | manual       — never resolved automatically: raise a conflict for a human.
    | append_only  — financial records. A duplicate uuid is a replay (handled by
    |                idempotency); the same uuid with *different* financial
    |                content is a critical conflict, never an overwrite.
    */
    'conflict_policies' => [
        'product' => 'field_merge',
        'product_category' => 'server_wins',
        'customer' => 'field_merge',
        'supplier' => 'server_wins',
        'user' => 'server_wins',
        'sale' => 'append_only',
        'sale_payment' => 'append_only',
        'refund' => 'append_only',
        'cash_session' => 'append_only',
        'cash_movement' => 'append_only',
        'stock_movement' => 'append_only',
        'stock_adjustment' => 'append_only',
        'expense' => 'append_only',
        'counter_end_of_day' => 'append_only',
    ],

    /*
    | Fields an offline till is allowed to have changed (field_merge policy).
    | Anything not listed here is server-authoritative on conflict.
    */
    'merge_fields' => [
        'product' => ['sale_price', 'wholesale_price', 'tax_rate', 'min_stock', 'name_fa', 'image', 'active'],
        'customer' => ['name', 'phone', 'email', 'address'],
    ],

    /*
    | Fields the server owns outright: a device may never set them, no matter
    | what its payload contains.
    */
    'server_owned_fields' => [
        'id', 'company_id', 'uuid', 'revision', 'sync_seq', 'created_at', 'updated_at', 'deleted_at',
        'stock_qty',              // stock is a projection of movements, never a pushed number
        'total_spent', 'orders_count', 'loyalty_points', 'last_purchase_at',
    ],

    /*
    | A device may only pull rows for its own branch plus shared master data.
    */
    'branch_scoped' => ['sales', 'refunds', 'shifts', 'cash_movements', 'stock_adjustments', 'expenses'],
];
