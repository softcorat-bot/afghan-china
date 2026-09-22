<?php

use App\Http\Controllers\Admin\SuperAdminController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\Branch\BranchController;
use App\Http\Controllers\Company\CompanyController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\HardwareDeviceController;
use App\Http\Controllers\Inventory\ExpiryBatchController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Sync\ConflictController;
use App\Http\Controllers\Sync\DeviceAdminController;
use App\Http\Controllers\Sync\SyncLogController;
use App\Http\Controllers\POS\CounterEndOfDayController;
use App\Http\Controllers\POS\OrderQueueController;
use App\Http\Controllers\Role\RoleController;
use App\Http\Controllers\User\UserController;
use Illuminate\Support\Facades\Route;

// Public auth
Route::post('login', [AuthController::class, 'login']);
Route::get('auth', [AuthController::class, 'check']);

// Catalog photos, streamed through the app — the attachment approach. An
// <img> tag cannot send a bearer token, and catalog photos were always
// public, so this route takes no auth; what it never takes is a detour
// through the web server's static-file handling, which is where every
// per-machine image failure lived. See CatalogPhotoController.
Route::get('catalog-photo/{path}', [\App\Http\Controllers\Catalog\CatalogPhotoController::class, 'show'])
    ->where('path', '.*');

// Counter PIN terminal (rate-limited: a 4-digit door must not be brute-forceable)
Route::middleware('throttle:12,1')->group(function () {
    Route::get('pin/staff', [\App\Http\Controllers\Auth\PinController::class, 'staff']);
    Route::post('pin/login', [\App\Http\Controllers\Auth\PinController::class, 'login']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('admin')->group(function () {
        // VIP Access Control (credential protection for super admins)
        Route::get('vip-status', [\App\Http\Controllers\Admin\VipAccessController::class, 'checkVipStatus']);
        Route::get('users-secure', [\App\Http\Controllers\Admin\VipAccessController::class, 'getUsers']);
        Route::get('users/{user}/secure', [\App\Http\Controllers\Admin\VipAccessController::class, 'getUser']);
        Route::put('users/{user}/secure', [\App\Http\Controllers\Admin\VipAccessController::class, 'updateUser']);
        Route::delete('users/{user}/secure', [\App\Http\Controllers\Admin\VipAccessController::class, 'deleteUser']);
        Route::post('users/{user}/set-vip-password', [\App\Http\Controllers\Admin\VipAccessController::class, 'setSuperAdminPassword']);

        // Super admin only routes
        Route::middleware(['super_admin'])->group(function () {
            Route::get('companies', [SuperAdminController::class, 'companies']);
            Route::get('companies/{company}/stats', [SuperAdminController::class, 'companyStats']);
            Route::get('users', [SuperAdminController::class, 'users']);
            Route::put('users/{user}/toggle-super-admin', [SuperAdminController::class, 'toggleSuperAdmin']);
            Route::delete('companies/{company}', [SuperAdminController::class, 'destroyCompany']);
        });
    });

    // VIP Control Center — Platform Owner only (server-enforced, role-independent)
    Route::prefix('platform')->middleware(['platform_owner'])->group(function () {
        Route::get('dashboard', [\App\Http\Controllers\Platform\PlatformController::class, 'dashboard']);
        Route::get('organizations', [\App\Http\Controllers\Platform\PlatformController::class, 'organizations']);
        Route::post('organizations', [\App\Http\Controllers\Platform\PlatformController::class, 'createOrganization']);
        Route::put('organizations/{company}/toggle', [\App\Http\Controllers\Platform\PlatformController::class, 'toggleOrganization']);
        Route::put('organizations/{company}/self-service', [\App\Http\Controllers\Platform\PlatformController::class, 'setSelfService']);
        Route::get('branches', [\App\Http\Controllers\Platform\PlatformController::class, 'branches']);
        Route::post('branches', [\App\Http\Controllers\Platform\PlatformController::class, 'createBranch']);
        Route::put('branches/{branch}/rename', [\App\Http\Controllers\Platform\PlatformController::class, 'renameBranch']);
        Route::put('branches/{branch}/toggle', [\App\Http\Controllers\Platform\PlatformController::class, 'toggleBranch']);
        Route::put('branches/{branch}/transfer', [\App\Http\Controllers\Platform\PlatformController::class, 'transferBranch']);
        Route::delete('branches/{branch}/archive', [\App\Http\Controllers\Platform\PlatformController::class, 'archiveBranch']);
        Route::delete('branches/{branch}', [\App\Http\Controllers\Platform\PlatformController::class, 'deleteBranch']);
        Route::get('requests', [\App\Http\Controllers\Platform\PlatformController::class, 'requests']);
        Route::put('requests/{platformRequest}/decide', [\App\Http\Controllers\Platform\PlatformController::class, 'decideRequest']);
        Route::get('audit', [\App\Http\Controllers\Platform\PlatformController::class, 'audit']);
    });

    // A tenant admin raises a platform request (owner decides it later)
    Route::post('platform/requests', [\App\Http\Controllers\Platform\PlatformController::class, 'submitRequest']);

    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('user', [AuthController::class, 'user']);

    // Company management (master scope, no tenant required)
    Route::get('company', [CompanyController::class, 'index']);
    Route::post('company/store', [CompanyController::class, 'store']);
    Route::get('company/select/{company}', [CompanyController::class, 'select']);
    Route::get('company/{company}', [CompanyController::class, 'show']);
    Route::put('company/{company}', [CompanyController::class, 'update']);
    Route::delete('company/{company}', [CompanyController::class, 'destroy']);

    // Tenant-scoped routes
    Route::middleware(['tenant', 'branch'])->group(function () {
        Route::get('dashboard_data', [DashboardController::class, 'dashboard']);
        Route::get('search', [\App\Http\Controllers\SearchController::class, 'index']);

        Route::apiResource('users', UserController::class);
        Route::get('users/{user}/performance', [\App\Http\Controllers\User\UserPerformanceController::class, 'show']);
        Route::put('users/{user}/pin', [\App\Http\Controllers\Auth\PinController::class, 'set']);

        // ── Counter seats + performance ──
        Route::get('counters', [\App\Http\Controllers\Counter\CounterController::class, 'index']);
        Route::post('counters', [\App\Http\Controllers\Counter\CounterController::class, 'store']);
        Route::put('counters/{counter}', [\App\Http\Controllers\Counter\CounterController::class, 'update']);
        Route::get('counters/{counter}/performance', [\App\Http\Controllers\Counter\CounterController::class, 'performance']);

        // ── Counter End-of-Day Reports ──
        Route::get('counter-eod', [\App\Http\Controllers\POS\CounterEndOfDayController::class, 'index']);
        Route::get('counters/{counter}/eod', [\App\Http\Controllers\POS\CounterEndOfDayController::class, 'show']);
        Route::post('counters/{counter}/eod/submit', [\App\Http\Controllers\POS\CounterEndOfDayController::class, 'submit']);
        Route::get('counters/{counter}/eod/summary', [\App\Http\Controllers\POS\CounterEndOfDayController::class, 'summary']);
        Route::post('counter-eod/{report}/approve', [\App\Http\Controllers\POS\CounterEndOfDayController::class, 'approve']);
        Route::post('counter-eod/{report}/reject', [\App\Http\Controllers\POS\CounterEndOfDayController::class, 'reject']);

        Route::get('roles', [RoleController::class, 'index']);
        Route::get('permissions', [RoleController::class, 'permissions']);
        Route::post('roles', [RoleController::class, 'store']);
        Route::put('roles/{role}', [RoleController::class, 'update']);
        Route::delete('roles/{role}', [RoleController::class, 'destroy']);

        Route::apiResource('branches', BranchController::class);
        Route::post('me/branch', [BranchController::class, 'switch']);

        // Universal attachments (photos / documents on any whitelisted record)
        Route::get('attachments', [\App\Http\Controllers\AttachmentController::class, 'index']);
        Route::post('attachments', [\App\Http\Controllers\AttachmentController::class, 'store']);
        Route::get('attachments/{attachment}/view', [\App\Http\Controllers\AttachmentController::class, 'view']);
        Route::delete('attachments/{attachment}', [\App\Http\Controllers\AttachmentController::class, 'destroy']);

        // ── Catalog: Products & Categories (Shopify-style) ──────────────
        // The picture library — set a photo by picking one instead of
        // hunting for the file again.
        // One lookup behind every scanner in the ERP (POS, purchases,
        // transfers, returns, adjustments, catalog…). Deliberately readable by
        // anyone who may list products — scanning is not a privileged act.
        Route::get('products/lookup', [\App\Http\Controllers\Catalog\ProductLookupController::class, 'scan']);

        Route::get('products/image-library', [\App\Http\Controllers\Catalog\ProductImageController::class, 'library']);
        Route::post('products/{product}/image-from-library', [\App\Http\Controllers\Catalog\ProductImageController::class, 'fromLibrary']);
        Route::post('products/{product}/image', [\App\Http\Controllers\Catalog\ProductImageController::class, 'upload']);
        Route::delete('products/{product}/image', [\App\Http\Controllers\Catalog\ProductImageController::class, 'destroy']);
        Route::post('products/import', [\App\Http\Controllers\Catalog\ProductImportController::class, 'import']);
        Route::get('products/{product}/dashboard', [\App\Http\Controllers\Catalog\ProductInsightsController::class, 'dashboard']);
        Route::get('products/{product}/history', [\App\Http\Controllers\Catalog\ProductInsightsController::class, 'history']);
        Route::apiResource('products', \App\Http\Controllers\Catalog\ProductController::class);
        Route::get('product-categories', [\App\Http\Controllers\Catalog\ProductCategoryController::class, 'index']);
        Route::post('product-categories', [\App\Http\Controllers\Catalog\ProductCategoryController::class, 'store']);
        Route::put('product-categories/{category}', [\App\Http\Controllers\Catalog\ProductCategoryController::class, 'update']);
        Route::delete('product-categories/{category}', [\App\Http\Controllers\Catalog\ProductCategoryController::class, 'destroy']);

        // ── Point of Sale (the register) ────────────────────────────────
        Route::get('pos/catalog', [\App\Http\Controllers\Pos\PosController::class, 'catalog']);
        Route::get('pos/scan', [\App\Http\Controllers\Pos\PosController::class, 'scan']);
        Route::post('pos/checkout', [\App\Http\Controllers\Pos\PosController::class, 'checkout']);

        // ── Order Queue Management ──
        Route::get('pos/queue', [\App\Http\Controllers\POS\OrderQueueController::class, 'index']);
        Route::post('pos/queue/create', [\App\Http\Controllers\POS\OrderQueueController::class, 'create']);
        Route::post('pos/queue/{order}/switch', [\App\Http\Controllers\POS\OrderQueueController::class, 'switchOrder']);
        Route::post('pos/queue/{order}/abandon', [\App\Http\Controllers\POS\OrderQueueController::class, 'abandon']);

        // ── Bill Printing ──
        Route::get('bills/{sale}/html', [\App\Http\Controllers\POS\BillPrintController::class, 'html']);
        Route::get('bills/{sale}/data', [\App\Http\Controllers\POS\BillPrintController::class, 'data']);

        // ── Cashier shifts / cash drawer ────────────────────────────────
        Route::get('shifts/current', [\App\Http\Controllers\Pos\ShiftController::class, 'current']);
        Route::get('shifts', [\App\Http\Controllers\Pos\ShiftController::class, 'index']);
        Route::get('shifts/{shift}', [\App\Http\Controllers\Pos\ShiftController::class, 'show']);
        Route::get('shifts/{shift}/xreport', [\App\Http\Controllers\Pos\ShiftController::class, 'xreport']);
        Route::post('shifts/open', [\App\Http\Controllers\Pos\ShiftController::class, 'open']);
        Route::post('shifts/{shift}/movement', [\App\Http\Controllers\Pos\ShiftController::class, 'movement']);
        Route::post('shifts/{shift}/close', [\App\Http\Controllers\Pos\ShiftController::class, 'close']);

        // ── Purchasing: Suppliers & Goods Receiving ─────────────────────
        Route::get('suppliers/{supplier}/dashboard', [\App\Http\Controllers\Purchasing\SupplierController::class, 'dashboard']);
        Route::apiResource('suppliers', \App\Http\Controllers\Purchasing\SupplierController::class)->except(['show']);
        Route::get('purchases', [\App\Http\Controllers\Purchasing\PurchaseController::class, 'index']);
        Route::get('purchases/{purchase}', [\App\Http\Controllers\Purchasing\PurchaseController::class, 'show']);
        Route::post('purchases', [\App\Http\Controllers\Purchasing\PurchaseController::class, 'store']);
        Route::post('purchases/{purchase}/receive', [\App\Http\Controllers\Purchasing\PurchaseController::class, 'receive']);
        Route::delete('purchases/{purchase}', [\App\Http\Controllers\Purchasing\PurchaseController::class, 'destroy']);
        Route::post('purchases/{purchase}/return', [\App\Http\Controllers\Purchasing\PurchaseReturnController::class, 'store']);

        // ── Inventory: manual stock adjustments ─────────────────────────
        Route::get('stock-adjustments', [\App\Http\Controllers\Inventory\StockAdjustmentController::class, 'index']);
        Route::post('stock-adjustments', [\App\Http\Controllers\Inventory\StockAdjustmentController::class, 'store']);

        // ── Warehouse ⇄ shop stock transfers ──
        Route::get('stock-transfers', [\App\Http\Controllers\Inventory\StockTransferController::class, 'index']);
        Route::post('stock-transfers', [\App\Http\Controllers\Inventory\StockTransferController::class, 'store']);

        // ── Product Expiry Tracking ──
        Route::get('expiry-categories', [\App\Http\Controllers\Inventory\ExpiryBatchController::class, 'trackedCategories']);
        Route::get('expiry-products', [\App\Http\Controllers\Inventory\ExpiryBatchController::class, 'trackedProducts']);
        Route::get('inventory/batches', [\App\Http\Controllers\Inventory\ExpiryBatchController::class, 'index']);
        Route::post('inventory/batches', [\App\Http\Controllers\Inventory\ExpiryBatchController::class, 'store']);
        Route::get('inventory/batches/{batch}', [\App\Http\Controllers\Inventory\ExpiryBatchController::class, 'show']);
        Route::put('inventory/batches/{batch}', [\App\Http\Controllers\Inventory\ExpiryBatchController::class, 'update']);
        Route::put('inventory/batches/{batch}/discard', [\App\Http\Controllers\Inventory\ExpiryBatchController::class, 'discard']);
        Route::post('inventory/batches/{batch}/sell', [\App\Http\Controllers\Inventory\ExpiryBatchController::class, 'sell']);
        Route::get('expiry-alerts', [\App\Http\Controllers\Inventory\ExpiryBatchController::class, 'alerts']);
        Route::post('expiry-alerts/{alert}/acknowledge', [\App\Http\Controllers\Inventory\ExpiryBatchController::class, 'acknowledgeAlert']);
        Route::post('expiry-alerts/acknowledge-all', [\App\Http\Controllers\Inventory\ExpiryBatchController::class, 'acknowledgeAllAlerts']);
        Route::get('inventory/expiry-summary', [\App\Http\Controllers\Inventory\ExpiryBatchController::class, 'summary']);
        Route::post('product-categories/{category}/setup-expiry', [\App\Http\Controllers\Inventory\ExpiryBatchController::class, 'setupCategory']);

        // ── Reports & analytics ─────────────────────────────────────────
        Route::get('reports/counters', [\App\Http\Controllers\Report\CounterReportController::class, 'daily']);
        Route::get('reports/sales', [\App\Http\Controllers\Report\ReportController::class, 'sales']);
        Route::get('reports/inventory', [\App\Http\Controllers\Report\ReportController::class, 'inventory']);
        Route::get('reports/suppliers', [\App\Http\Controllers\Report\ReportController::class, 'suppliers']);

        // ── Comprehensive Counter & Branch Reports ──
        Route::get('reports/counter/{counter}', [\App\Http\Controllers\Report\ComprehensiveCounterReportController::class, 'counterReport']);
        Route::get('reports/branch/{branch}', [\App\Http\Controllers\Report\ComprehensiveCounterReportController::class, 'branchReport']);
        Route::get('reports/company', [\App\Http\Controllers\Report\ComprehensiveCounterReportController::class, 'companyReport']);

        // ── Sales & Customers ───────────────────────────────────────────
        Route::get('sales/summary', [\App\Http\Controllers\Sales\SaleController::class, 'summary']);
        Route::get('sales', [\App\Http\Controllers\Sales\SaleController::class, 'index']);
        Route::get('sales/{sale}', [\App\Http\Controllers\Sales\SaleController::class, 'show']);
        Route::post('sales/{sale}/refund', [\App\Http\Controllers\Sales\SaleController::class, 'refund']);
        Route::get('customers/{customer}/dashboard', [\App\Http\Controllers\Sales\CustomerController::class, 'dashboard']);
        Route::get('customers/{customer}/history', [\App\Http\Controllers\Sales\CustomerController::class, 'history']);
        Route::apiResource('customers', \App\Http\Controllers\Sales\CustomerController::class)->except(['show']);

        // Money foundation — currency + daily-rate + rate-lock engine
        Route::apiResource('currencies', \App\Http\Controllers\Finance\CurrencyController::class)->except(['show']);
        Route::put('currencies/{currency}/set-base', [\App\Http\Controllers\Finance\CurrencyController::class, 'setBase']);
        Route::get('exchange-rates', [\App\Http\Controllers\Finance\ExchangeRateController::class, 'index']);
        Route::get('exchange-rates/current', [\App\Http\Controllers\Finance\ExchangeRateController::class, 'current']);
        Route::post('exchange-rates', [\App\Http\Controllers\Finance\ExchangeRateController::class, 'store']);

        // System
        Route::get('activity-logs', [\App\Http\Controllers\ActivityLogController::class, 'index']);
        // ── HR: attendance + payroll (staff = users) ──
        Route::get('hr/attendance', [\App\Http\Controllers\HR\AttendanceController::class, 'sheet']);
        Route::post('hr/attendance', [\App\Http\Controllers\HR\AttendanceController::class, 'mark']);
        Route::get('hr/attendance/{user}', [\App\Http\Controllers\HR\AttendanceController::class, 'person']);
        Route::get('hr/payroll', [\App\Http\Controllers\HR\PayrollController::class, 'index']);
        Route::post('hr/payroll/generate', [\App\Http\Controllers\HR\PayrollController::class, 'generate']);
        Route::get('hr/payroll/salaries', [\App\Http\Controllers\HR\PayrollController::class, 'salaries']);
        Route::put('hr/payroll/salaries/{user}', [\App\Http\Controllers\HR\PayrollController::class, 'setSalary']);
        Route::get('hr/payroll/{payroll}', [\App\Http\Controllers\HR\PayrollController::class, 'show']);
        Route::put('hr/payroll/items/{item}', [\App\Http\Controllers\HR\PayrollController::class, 'updateItem']);
        Route::post('hr/payroll/{payroll}/paid', [\App\Http\Controllers\HR\PayrollController::class, 'markPaid']);
        Route::delete('hr/payroll/{payroll}', [\App\Http\Controllers\HR\PayrollController::class, 'destroy']);

        // ── Wholesale (B2B) — same products & stock, business pricing ──
        Route::prefix('wholesale')->group(function () {
            Route::get('dashboard', [\App\Http\Controllers\Wholesale\WholesaleController::class, 'dashboard']);
            Route::get('catalog', [\App\Http\Controllers\Wholesale\WholesaleController::class, 'catalog']);
            Route::get('customers', [\App\Http\Controllers\Wholesale\WholesaleController::class, 'customers']);
            Route::post('customers', [\App\Http\Controllers\Wholesale\WholesaleController::class, 'storeCustomer']);
            Route::put('customers/{customer}', [\App\Http\Controllers\Wholesale\WholesaleController::class, 'updateCustomer']);
            Route::get('customers/{customer}/ledger', [\App\Http\Controllers\Wholesale\WholesaleController::class, 'ledger']);
            Route::get('orders', [\App\Http\Controllers\Wholesale\WholesaleController::class, 'orders']);
            Route::post('orders', [\App\Http\Controllers\Wholesale\WholesaleController::class, 'storeOrder']);
            Route::get('orders/{sale}', [\App\Http\Controllers\Wholesale\WholesaleController::class, 'showOrder']);
            Route::post('orders/{sale}/convert', [\App\Http\Controllers\Wholesale\WholesaleController::class, 'convert']);
            Route::post('orders/{sale}/payment', [\App\Http\Controllers\Wholesale\WholesaleController::class, 'payment']);
        });

        // ── Trash (recycle bin over every soft-deleting module) ──
        Route::get('trash-counts', [\App\Http\Controllers\TrashController::class, 'counts']);
        Route::get('trash/{type}', [\App\Http\Controllers\TrashController::class, 'index']);
        Route::post('trash/{type}/{id}/restore', [\App\Http\Controllers\TrashController::class, 'restore']);
        Route::delete('trash/{type}/{id}', [\App\Http\Controllers\TrashController::class, 'destroy']);

        // ── Main Cost — the owner's private desk (gated in-controller) ──
        Route::get('owner/main-cost/products', [\App\Http\Controllers\Owner\MainCostController::class, 'index']);
        Route::put('owner/main-cost/products/{product}', [\App\Http\Controllers\Owner\MainCostController::class, 'update']);
        Route::get('owner/main-cost/report', [\App\Http\Controllers\Owner\MainCostController::class, 'report']);

        // The SAME period report for every manager — the owner's real-cost
        // columns are added only for a Main Cost holder, so the two reports
        // can never disagree about revenue or orders.
        Route::get('reports/period', function (\Illuminate\Http\Request $request) {
            return app(\App\Http\Controllers\Owner\MainCostController::class)
                ->periodReport($request, \App\Support\EffectiveCost::canView($request->user()));
        });
        Route::get('owner/main-cost/history', [\App\Http\Controllers\Owner\MainCostController::class, 'history']);

        // فایده خالص — net profit in one call: any period, any branch, any
        // counter. This is the owner's headline figure.
        Route::get('owner/net-profit', [\App\Http\Controllers\Owner\NetProfitController::class, 'index']);

        // ── Per-counter net report — revenue − Main-Cost COGS − divided
        //    expense share, over daily/weekly/monthly/yearly/lifetime/custom.
        //    Same in-controller gate as the rest of the owner's desk. ──
        Route::get('owner/counter-reports', [\App\Http\Controllers\Report\ComprehensiveCounterReportController::class, 'allCounters']);
        Route::get('owner/counter-reports/counter/{counter}', [\App\Http\Controllers\Report\ComprehensiveCounterReportController::class, 'counterReport']);
        Route::get('owner/counter-reports/branch/{branch}', [\App\Http\Controllers\Report\ComprehensiveCounterReportController::class, 'branchReport']);
        Route::get('owner/counter-reports/company', [\App\Http\Controllers\Report\ComprehensiveCounterReportController::class, 'companyReport']);

        // ── Receipt layout (admin/VIP): what a customer is handed ──
        // ── How the register behaves (read by every cashier, edited by admin/VIP) ──
        Route::get('settings/pos', [\App\Http\Controllers\Settings\PosSettingsController::class, 'show']);
        Route::put('settings/pos', [\App\Http\Controllers\Settings\PosSettingsController::class, 'update']);

        Route::get('settings/receipt', [\App\Http\Controllers\Settings\ReceiptSettingsController::class, 'show']);
        Route::put('settings/receipt', [\App\Http\Controllers\Settings\ReceiptSettingsController::class, 'update']);

        // ── Expenses: the shop's running costs ──
        Route::apiResource('expenses', \App\Http\Controllers\Finance\ExpenseController::class)->except(['show']);

        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/mark-read', [NotificationController::class, 'markRead']);
        Route::get('backup/download', [BackupController::class, 'download']);
        Route::get('backup/list', [BackupController::class, 'index']);
        Route::post('backup/restore', [BackupController::class, 'restore']);

        // ── Hardware devices (biometric, RFID, QR attendance) ──
        Route::prefix('hardware-devices')->group(function () {
            Route::get('/', [\App\Http\Controllers\HardwareDeviceController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\HardwareDeviceController::class, 'store']);
            Route::get('/{device}', [\App\Http\Controllers\HardwareDeviceController::class, 'show']);
            Route::put('/{device}', [\App\Http\Controllers\HardwareDeviceController::class, 'update']);
            Route::delete('/{device}', [\App\Http\Controllers\HardwareDeviceController::class, 'destroy']);
            Route::post('/{device}/test-connection', [\App\Http\Controllers\HardwareDeviceController::class, 'testConnection']);
            Route::post('/{device}/sync-records', [\App\Http\Controllers\HardwareDeviceController::class, 'syncRecords']);
            Route::get('/{device}/unsynced-records', [\App\Http\Controllers\HardwareDeviceController::class, 'getUnsyncedRecords']);
        });

        // ── Inventory expiry management ──
        Route::prefix('inventory/expiry')->group(function () {
            Route::get('products', [\App\Http\Controllers\Inventory\ExpiryBatchController::class, 'trackedProducts']);
            Route::get('alerts', [\App\Http\Controllers\Inventory\ExpiryBatchController::class, 'alerts']);
            Route::get('categories', [\App\Http\Controllers\Inventory\ExpiryBatchController::class, 'trackedCategories']);
            Route::get('summary', [\App\Http\Controllers\Inventory\ExpiryBatchController::class, 'summary']);
            Route::post('batches', [\App\Http\Controllers\Inventory\ExpiryBatchController::class, 'store']);
            Route::get('batches', [\App\Http\Controllers\Inventory\ExpiryBatchController::class, 'index']);
            Route::get('batches/{batch}', [\App\Http\Controllers\Inventory\ExpiryBatchController::class, 'show']);
            Route::put('batches/{batch}', [\App\Http\Controllers\Inventory\ExpiryBatchController::class, 'update']);
            Route::post('batches/{batch}/discard', [\App\Http\Controllers\Inventory\ExpiryBatchController::class, 'discard']);
            Route::post('batches/{batch}/sell', [\App\Http\Controllers\Inventory\ExpiryBatchController::class, 'sell']);
            Route::post('alerts/{alert}/acknowledge', [\App\Http\Controllers\Inventory\ExpiryBatchController::class, 'acknowledgeAlert']);
        });

        // ── POS: Counter end-of-day & order queue ──
        Route::prefix('pos')->group(function () {
            Route::post('end-of-day', [\App\Http\Controllers\POS\CounterEndOfDayController::class, 'close']);
            Route::get('end-of-day/summary', [\App\Http\Controllers\POS\CounterEndOfDayController::class, 'summary']);
            Route::get('queue', [\App\Http\Controllers\POS\OrderQueueController::class, 'index']);
            Route::post('queue/{order}/print', [\App\Http\Controllers\POS\OrderQueueController::class, 'print']);
        });

        // ── Offline POS fleet: Settings → Devices ──
        // Authorize a Windows till, watch it, and cut it off if it is lost.
        Route::prefix('devices')->group(function () {
            Route::get('/', [DeviceAdminController::class, 'index'])->middleware('sync_admin:device-list,manage-devices');
            Route::post('/', [DeviceAdminController::class, 'store'])->middleware('sync_admin:manage-devices');
            Route::get('{device}', [DeviceAdminController::class, 'show'])->middleware('sync_admin:device-list,device-show,manage-devices');
            Route::put('{device}', [DeviceAdminController::class, 'update'])->middleware('sync_admin:manage-devices');
            Route::post('{device}/enable', [DeviceAdminController::class, 'enable'])->middleware('sync_admin:manage-devices');
            Route::post('{device}/disable', [DeviceAdminController::class, 'disable'])->middleware('sync_admin:manage-devices');
            Route::post('{device}/revoke', [DeviceAdminController::class, 'revoke'])->middleware('sync_admin:manage-devices');
            Route::post('{device}/reauthorize', [DeviceAdminController::class, 'reauthorize'])->middleware('sync_admin:manage-devices');
            Route::delete('{device}', [DeviceAdminController::class, 'destroy'])->middleware('sync_admin:manage-devices');
        });

        // ── Sync Conflict Center: Settings → Synchronization → Conflicts ──
        Route::prefix('sync-conflicts')->group(function () {
            Route::get('/', [ConflictController::class, 'index'])->middleware('sync_admin:sync-conflict-list,resolve-sync-conflicts');
            Route::get('pending', [ConflictController::class, 'pending'])->middleware('sync_admin:sync-conflict-list,resolve-sync-conflicts');
            Route::get('{conflict}', [ConflictController::class, 'show'])->middleware('sync_admin:sync-conflict-list,sync-conflict-show,resolve-sync-conflicts');
            Route::post('{conflict}/resolve', [ConflictController::class, 'resolve'])->middleware('sync_admin:resolve-sync-conflicts');
        });

        // ── Synchronization overview: Settings → Synchronization ──
        Route::prefix('sync')->group(function () {
            Route::get('overview', [SyncLogController::class, 'overview'])->middleware('sync_admin:sync-log-list,sync-now');
            Route::get('batches', [SyncLogController::class, 'batches'])->middleware('sync_admin:sync-log-list,sync-now');
            Route::post('prune', [SyncLogController::class, 'prune'])->middleware('sync_admin:manage-devices');
        });

        // ── Sync Center: the local agent's API on an Offline installation ──
        // Same guards as the fleet admin surface; the `offline.mode` middleware
        // 404s every one of these routes on an Online installation.
        Route::prefix('offline')->middleware('offline.mode')->group(function () {
            Route::get('status', [\App\Http\Controllers\Offline\SyncCenterController::class, 'status'])->middleware('sync_admin:sync-log-list,sync-now');
            Route::post('sync', [\App\Http\Controllers\Offline\SyncCenterController::class, 'sync'])->middleware('sync_admin:sync-now');
            Route::post('register', [\App\Http\Controllers\Offline\SyncCenterController::class, 'register'])->middleware('sync_admin:manage-devices');
            Route::post('retry', [\App\Http\Controllers\Offline\SyncCenterController::class, 'retry'])->middleware('sync_admin:sync-now');
            Route::get('outbox', [\App\Http\Controllers\Offline\SyncCenterController::class, 'outbox'])->middleware('sync_admin:sync-log-list,sync-now');
            Route::get('conflicts', [\App\Http\Controllers\Offline\SyncCenterController::class, 'conflicts'])->middleware('sync_admin:sync-conflict-list,resolve-sync-conflicts');
            Route::get('backups', [\App\Http\Controllers\Offline\SyncCenterController::class, 'backups'])->middleware('sync_admin:manage-devices');
            Route::post('backup', [\App\Http\Controllers\Offline\SyncCenterController::class, 'backup'])->middleware('sync_admin:manage-devices');
            Route::post('restore', [\App\Http\Controllers\Offline\SyncCenterController::class, 'restore'])->middleware('sync_admin:manage-devices');
        });
    });
});

/*
|--------------------------------------------------------------------------
| Offline POS sync API
|--------------------------------------------------------------------------
|
| What a Windows till talks to when it has no browser and no human: it holds its
| own device token (issued once, at activation), pushes what it did offline,
| pulls what changed centrally and acknowledges what it stored.
|
| /api/v1/sync/* is the documented surface; the older /api/sync/* paths are
| registered from the same definition so nothing built against them breaks.
|
*/
$offlineSyncRoutes = function (): void {
    Route::post('register', [\App\Http\Controllers\Sync\DeviceSyncController::class, 'register'])
        ->middleware('throttle:20,1');

    Route::middleware('device_auth')->group(function () {
        Route::match(['get', 'post'], 'status', [\App\Http\Controllers\Sync\SyncController::class, 'status']);
        Route::post('heartbeat', [\App\Http\Controllers\Sync\SyncController::class, 'heartbeat']);
        Route::post('push', [\App\Http\Controllers\Sync\SyncController::class, 'push']);
        Route::match(['get', 'post'], 'pull', [\App\Http\Controllers\Sync\SyncController::class, 'pull']);
        Route::post('ack', [\App\Http\Controllers\Sync\SyncController::class, 'ack']);
        Route::get('conflicts', [\App\Http\Controllers\Sync\SyncController::class, 'conflicts']);
        Route::match(['get', 'post'], 'context', [\App\Http\Controllers\Sync\SyncController::class, 'context']);
        Route::post('token/rotate', [\App\Http\Controllers\Sync\DeviceSyncController::class, 'rotate']);
    });
};

Route::prefix('v1/sync')->group($offlineSyncRoutes);
Route::prefix('sync')->group($offlineSyncRoutes);
