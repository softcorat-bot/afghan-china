// Menu in operating order — the day flows top to bottom:
// see the numbers → sell → manage the money → manage the goods →
// judge performance → run the people → administer → system utilities.
// Every leaf keeps its own permission; empty groups collapse.
export const menus = [
  // ── Overview ──────────────────────────────────────────────────────────
  {
    room: 'Dashboard', permission: 'dashboard-list', icon: 'mdi-monitor-dashboard',
    name: 'Dashboard', status: true, color: 'blue', url: '/', is_sub: []
  },

  // ── Owner / VIP layer (gold) ──────────────────────────────────────────
  {
    room: 'PlatformControl', platform: true, permission: null, icon: 'mdi-shield-crown',
    name: 'VIPControlCenter', status: true, color: 'amber', url: '/platform', is_sub: []
  },
  {
    room: 'MainCost', mainCost: true, permission: null, icon: 'mdi-diamond-stone',
    name: 'MainCost', status: true, color: 'amber', url: '/main-cost', is_sub: []
  },

  // ── Selling (the register comes first — it is the busiest screen) ────
  {
    room: 'POS', permission: 'pos-list', icon: 'point_of_sale',
    name: 'PointOfSale', status: true, color: 'deep-orange', url: '/pos', is_sub: []
  },
  {
    room: 'CashRegister', permission: 'shift-list', icon: 'savings',
    name: 'CashRegister', status: true, color: 'green', url: '/shifts', is_sub: [
      { room: 'CashRegister', permission: 'shift-list', icon: 'savings', name: 'CashRegister', status: true, color: 'green', url: '/shifts', add_url: null, is_sub: [] },
      { room: 'CounterEndOfDay', permission: 'shift-list', icon: 'point_of_sale', name: 'CounterEndOfDay', status: true, color: 'green', url: '/counter-end-of-day', add_url: null, is_sub: [] },
    ]
  },
  {
    room: 'SalesGroup', permission: 'sale-list', icon: 'sell',
    name: 'SalesAndCustomers', status: true, color: 'teal', url: null,
    is_sub: [
      { room: 'Sales', permission: 'sale-list', icon: 'receipt_long', name: 'Sales', status: true, color: 'black', url: '/sales', add_url: null, is_sub: [] },
      { room: 'Wholesale', permission: 'wholesale-list', icon: 'business_center', name: 'WholesaleB2B', status: true, color: 'black', url: '/wholesale', add_url: null, is_sub: [] },
      { room: 'Customers', permission: 'customer-list', icon: 'groups', name: 'Customers', status: true, color: 'black', url: '/customers', add_url: null, is_sub: [] }
    ]
  },

  // ── Goods (catalog, then how stock moves in and around) ──────────────
  {
    room: 'Catalog', permission: 'product-list', icon: 'storefront',
    name: 'Catalog', status: true, color: 'deep-orange', url: null,
    is_sub: [
      { room: 'Products', permission: 'product-list', icon: 'inventory_2', name: 'Products', status: true, color: 'black', url: '/products', add_url: null, is_sub: [] },
      { room: 'ProductCategories', permission: 'category-list', icon: 'category', name: 'ProductCategories', status: true, color: 'black', url: '/product-categories', add_url: null, is_sub: [] },
      { room: 'BarcodeLabels', permission: 'product-list', icon: 'qr_code_2', name: 'BarcodeLabels', status: true, color: 'black', url: '/labels', add_url: null, is_sub: [] },
      { room: 'ImportProducts', permission: 'product-create', icon: 'upload_file', name: 'ImportProducts', status: true, color: 'black', url: '/products/import', add_url: null, is_sub: [] }
    ]
  },
  {
    room: 'InventoryGroup', permission: 'purchase-list', icon: 'warehouse',
    name: 'InventoryAndPurchasing', status: true, color: 'brown', url: null,
    is_sub: [
      { room: 'InventoryControl', permission: 'transfer-list', icon: 'inventory', name: 'InventoryControl', status: true, color: 'black', url: '/transfers', add_url: null, is_sub: [] },
      { room: 'ExpiryManagement', permission: 'purchase-list', icon: 'medication_liquid', name: 'ExpiryTracking', status: true, color: 'black', url: '/inventory/expiry', add_url: null, is_sub: [] },
      { room: 'Purchases', permission: 'purchase-list', icon: 'shopping_cart', name: 'Purchases', status: true, color: 'black', url: '/purchases', add_url: null, is_sub: [] },
      { room: 'Suppliers', permission: 'supplier-list', icon: 'local_shipping', name: 'Suppliers', status: true, color: 'black', url: '/suppliers', add_url: null, is_sub: [] }
    ]
  },

  // ── Performance & analytics ───────────────────────────────────────────
  {
    room: 'Performance', permission: 'report-list', icon: 'insights',
    name: 'PerformanceAndReports', status: true, color: 'indigo', url: null,
    is_sub: [
      { room: 'Reports', permission: 'report-list', icon: 'assessment', name: 'Reports', status: true, color: 'black', url: '/reports', add_url: null, is_sub: [] },
      { room: 'CounterReports', permission: 'report-list', icon: 'point_of_sale', name: 'CounterReports', status: true, color: 'black', url: '/counter-reports', add_url: null, is_sub: [] },
      { room: 'BranchReports', permission: 'report-list', icon: 'store', name: 'BranchReports', status: true, color: 'black', url: '/branch-reports', add_url: null, is_sub: [] },
      { room: 'CompanyReports', permission: 'report-list', icon: 'business', name: 'CompanyReports', status: true, color: 'black', url: '/company-reports', add_url: null, is_sub: [] },
      { room: 'Counters', permission: 'report-list', icon: 'storefront', name: 'Counters', status: true, color: 'black', url: '/counters', add_url: null, is_sub: [] }
    ]
  },

  // ── People (HR) ───────────────────────────────────────────────────────
  {
    room: 'HR', permission: 'attendance-list', icon: 'diversity_3',
    name: 'HRAndPayroll', status: true, color: 'pink', url: null,
    is_sub: [
      { room: 'Attendance', permission: 'attendance-list', icon: 'fact_check', name: 'Attendance', status: true, color: 'black', url: '/attendance', add_url: null, is_sub: [] },
      { room: 'Payroll', permission: 'payroll-list', icon: 'account_balance_wallet', name: 'Payroll', status: true, color: 'black', url: '/payroll', add_url: null, is_sub: [] }
    ]
  },

  // ── Money foundation ──────────────────────────────────────────────────
  {
    room: 'FinanceAccounting', permission: 'expense-list', icon: 'account_balance_wallet',
    name: 'FinanceAndAccounting', status: true, color: 'green', url: null,
    is_sub: [
      { room: 'Expenses', permission: 'expense-list', icon: 'payments', name: 'Expenses', status: true, color: 'black', url: '/finance/expenses', add_url: null, is_sub: [] },
      { room: 'ExchangeRates', permission: 'exchange-rate-list', icon: 'currency_exchange', name: 'ExchangeRates', status: true, color: 'black', url: '/finance/exchange-rates', add_url: null, is_sub: [] },
      { room: 'Currencies', permission: 'currency-list', icon: 'attach_money', name: 'Currencies', status: true, color: 'black', url: '/finance/currencies', add_url: null, is_sub: [] }
    ]
  },

  // ── Administration ────────────────────────────────────────────────────
  {
    room: 'Administration', permission: 'user-list', icon: 'admin_panel_settings',
    name: 'Administration', status: true, color: 'blue-grey', url: null,
    is_sub: [
      { room: 'User', permission: 'user-list', icon: 'manage_accounts', name: 'User', status: true, color: 'black', url: '/user', add_url: null, is_sub: [] },
      { room: 'Role', permission: 'role-list', icon: 'rule', name: 'UserRole', status: true, color: 'black', url: '/role', add_url: null, is_sub: [] },
      { room: 'Branch', permission: 'branch-list', icon: 'store', name: 'Branch', status: true, color: 'black', url: '/branch', add_url: null, is_sub: [] }
    ]
  },

  // ── System utilities ──────────────────────────────────────────────────
  {
    room: 'System', permission: 'dashboard-list', icon: 'settings_suggest',
    name: 'System', status: true, color: 'blue', url: null,
    is_sub: [
      { room: 'Notification', permission: 'notification-list', icon: 'notifications', name: 'Notification', status: true, color: 'black', url: '/notification', add_url: null, is_sub: [] },
      { room: 'Backup', permission: 'backup-list', icon: 'backup', name: 'Backup', status: true, color: 'black', url: '/backup', add_url: null, is_sub: [] },
      { room: 'Log', permission: 'log-list', icon: 'visibility', name: 'Log', status: true, color: 'black', url: '/log', add_url: null, is_sub: [] },
      { room: 'HardwareDevices', permission: 'dashboard-list', icon: 'fingerprint', name: 'HardwareDevices', status: true, color: 'black', url: '/hardware-devices', add_url: null, is_sub: [] },
      { room: 'PosDevices', permission: 'device-list', icon: 'point_of_sale', name: 'PosDevices', status: true, color: 'black', url: '/pos-devices', add_url: null, is_sub: [] },
      { room: 'SyncMonitor', permission: 'sync-log-list', icon: 'sync', name: 'SyncMonitor', status: true, color: 'black', url: '/sync-monitor', add_url: null, is_sub: [] },
      { room: 'SyncCenter', permission: 'sync-now', icon: 'cloud_sync', name: 'SyncCenter', status: true, color: 'black', url: '/sync-center', add_url: null, is_sub: [] },
      { room: 'SyncConflicts', permission: 'sync-conflict-list', icon: 'sync_problem', name: 'SyncConflicts', status: true, color: 'black', url: '/sync-conflicts', add_url: null, is_sub: [] },
      { room: 'Trash', superAdmin: true, permission: null, icon: 'auto_delete', name: 'Trashes', status: true, color: 'black', url: '/trash', add_url: null, is_sub: [] },
      { room: 'ReceiptDesigner', permission: 'theme-list', icon: 'receipt_long', name: 'ReceiptDesigner', status: true, color: 'black', url: '/receipt-designer', add_url: null, is_sub: [] },
      { room: 'Theme', permission: 'theme-list', icon: 'palette', name: 'ThemeAppearance', status: true, color: 'black', url: '/theme', add_url: null, is_sub: [] }
    ]
  },

  { room: 'Templates', permission: 'dashboard-list', icon: 'mdi-logout', name: 'Logout', status: true, color: 'blue', url: '', is_sub: [] }
]
