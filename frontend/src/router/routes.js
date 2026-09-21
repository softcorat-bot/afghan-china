const routes = [
  {
    path: '/login',
    component: () => import('@/layouts/AuthLayout.vue'),
    children: [
      { path: '', name: 'login', component: () => import('@/pages/auth/LoginPage.vue') }
    ],
    meta: { guest: true }
  },
  // Counter PIN terminal — its own full-screen POS hardware view
  {
    path: '/pin',
    name: 'pin-login',
    component: () => import('@/pages/auth/PinLoginPage.vue'),
    meta: { guest: true }
  },
  {
    path: '/',
    component: () => import('@/layouts/MainLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      { path: '', name: 'dashboard', component: () => import('@/pages/DashboardPage.vue') },

      // Users & Roles
      { path: 'users', name: 'users', component: () => import('@/pages/users/UsersPage.vue'), meta: { permission: 'user-list' } },
      { path: 'users/create', name: 'user-create', component: () => import('@/pages/users/UserForm.vue'), meta: { permission: 'user-create' } },
      { path: 'users/edit/:id', name: 'user-edit', component: () => import('@/pages/users/UserForm.vue'), meta: { permission: 'user-edit' } },
      { path: 'roles', name: 'roles', component: () => import('@/pages/roles/RolesPage.vue'), meta: { permission: 'role-list' } },
      { path: 'roles/create', name: 'role-create', component: () => import('@/pages/roles/RoleForm.vue'), meta: { permission: 'role-create' } },
      { path: 'roles/edit/:id', name: 'role-edit', component: () => import('@/pages/roles/RoleForm.vue'), meta: { permission: 'role-edit' } },

      // Branches (multi-branch)
      { path: 'branches', name: 'branches', component: () => import('@/pages/branches/BranchesPage.vue'), meta: { permission: 'branch-list' } },

      // Counter seats + performance analytics
      { path: 'counters', name: 'counters', component: () => import('@/pages/counters/CountersPage.vue'), meta: { permission: 'report-list' } },
      { path: 'counters/:id(\\d+)', name: 'counter-detail', component: () => import('@/pages/counters/PerformancePage.vue'), meta: { permission: 'report-list' } },
      { path: 'users/:id(\\d+)/performance', name: 'user-performance', component: () => import('@/pages/counters/PerformancePage.vue'), meta: { permission: 'user-list' } },

      // ── Point of Sale (the register) ──
      { path: 'pos', name: 'pos', component: () => import('@/pages/pos/PosRegisterPage.vue'), meta: { permission: 'pos-list' } },
      { path: 'shifts', name: 'shifts', component: () => import('@/pages/pos/ShiftsPage.vue'), meta: { permission: 'shift-list' } },
      { path: 'counter-end-of-day', name: 'counter-end-of-day', component: () => import('@/pages/pos/CounterEndOfDayPage.vue'), meta: { permission: 'shift-list' } },
      { path: 'branch-reports', name: 'branch-reports', component: () => import('@/pages/reports/BranchReportsPage.vue'), meta: { permission: 'report-list' } },
      { path: 'company-reports', name: 'company-reports', component: () => import('@/pages/reports/CompanyReportsPage.vue'), meta: { permission: 'report-list' } },

      // ── Catalog: Products & Categories ──
      { path: 'products', name: 'products', component: () => import('@/pages/catalog/ProductsPage.vue'), meta: { permission: 'product-list' } },
      { path: 'product-categories', name: 'product-categories', component: () => import('@/pages/catalog/ProductCategoriesPage.vue'), meta: { permission: 'category-list' } },
      { path: 'labels', name: 'labels', component: () => import('@/pages/catalog/LabelsPage.vue'), meta: { permission: 'product-list' } },
      { path: 'products/import', name: 'products-import', component: () => import('@/pages/catalog/ImportProductsPage.vue'), meta: { permission: 'product-create' } },
      { path: 'products/:id(\\d+)', name: 'product-dashboard', component: () => import('@/pages/catalog/ProductDashboardPage.vue'), meta: { permission: 'product-show' } },

      // ── Sales & Customers ──
      { path: 'sales', name: 'sales', component: () => import('@/pages/sales/SalesPage.vue'), meta: { permission: 'sale-list' } },
      { path: 'wholesale', name: 'wholesale', component: () => import('@/pages/wholesale/WholesalePage.vue'), meta: { permission: 'wholesale-list' } },
      { path: 'customers', name: 'customers', component: () => import('@/pages/sales/CustomersPage.vue'), meta: { permission: 'customer-list' } },
      { path: 'customers/:id(\\d+)', name: 'customer-dashboard', component: () => import('@/pages/sales/CustomerDashboardPage.vue'), meta: { permission: 'customer-show' } },

      // ── HR: attendance + payroll ──
      { path: 'attendance', name: 'attendance', component: () => import('@/pages/hr/AttendancePage.vue'), meta: { permission: 'attendance-list' } },
      { path: 'payroll', name: 'payroll', component: () => import('@/pages/hr/PayrollPage.vue'), meta: { permission: 'payroll-list' } },

      // ── Reports & Analytics ──
      { path: 'reports', name: 'reports', component: () => import('@/pages/reports/ReportsPage.vue'), meta: { permission: 'report-list' } },
      { path: 'counter-reports', name: 'counter-reports', component: () => import('@/pages/reports/CounterReportsPage.vue'), meta: { permission: 'report-list' } },

      // ── Purchasing & Inventory ──
      { path: 'purchases', name: 'purchases', component: () => import('@/pages/purchasing/PurchasesPage.vue'), meta: { permission: 'purchase-list' } },
      { path: 'suppliers', name: 'suppliers', component: () => import('@/pages/purchasing/SuppliersPage.vue'), meta: { permission: 'supplier-list' } },
      { path: 'suppliers/:id(\\d+)', name: 'supplier-dashboard', component: () => import('@/pages/purchasing/SupplierDashboardPage.vue'), meta: { permission: 'supplier-list' } },
      // Stock Adjustments merged into Inventory Control (transfers page)
      { path: 'stock-adjustments', redirect: '/transfers' },
      { path: 'transfers', name: 'transfers', component: () => import('@/pages/inventory/TransfersPage.vue'), meta: { permission: ['transfer-list', 'stock-adjustment-list'] } },
      { path: 'inventory/expiry', name: 'expiry-tracking', component: () => import('@/pages/inventory/ExpiryManagementPage.vue'), meta: { permission: 'purchase-list' } },

      // Money foundation — currency + daily-rate engine
      { path: 'finance/exchange-rates', name: 'exchange-rates', component: () => import('@/pages/finance/ExchangeRatesPage.vue'), meta: { permission: 'exchange-rate-list' } },
      { path: 'finance/currencies', name: 'currencies', component: () => import('@/pages/finance/CurrenciesPage.vue'), meta: { permission: 'currency-list' } },
      { path: 'finance/expenses', name: 'expenses', component: () => import('@/pages/finance/ExpensesPage.vue'), meta: { permission: 'expense-list' } },

      // System pages
      { path: 'backup', name: 'backup', component: () => import('@/pages/system/BackupPage.vue'), meta: { permission: 'backup-list' } },
      { path: 'platform', name: 'platform', component: () => import('@/pages/platform/PlatformPage.vue'), meta: { platform: true } },
      { path: 'main-cost', name: 'main-cost', component: () => import('@/pages/owner/MainCostPage.vue'), meta: { mainCost: true } },
      { path: 'log', name: 'log', component: () => import('@/pages/system/LogPage.vue'), meta: { permission: 'log-list' } },
      { path: 'trash', name: 'trash', component: () => import('@/pages/system/TrashPage.vue'), meta: { superAdmin: true } },
      { path: 'notification', name: 'notification', component: () => import('@/pages/system/NotificationPage.vue'), meta: { permission: 'notification-list' } },
      { path: 'theme', name: 'theme', component: () => import('@/pages/system/ThemePage.vue'), meta: { permission: 'theme-list' } },
      { path: 'receipt-designer', name: 'receipt-designer', component: () => import('@/pages/system/ReceiptDesignerPage.vue'), meta: { permission: 'theme-list' } },
      { path: 'hardware-devices', name: 'hardware-devices', component: () => import('@/pages/system/HardwareDevicesPage.vue'), meta: { permission: 'dashboard-list' } },

      // Offline POS fleet: tills, their synchronization and the conflict centre
      { path: 'pos-devices', name: 'pos-devices', component: () => import('@/pages/system/PosDevicesPage.vue'), meta: { permission: ['device-list', 'manage-devices'] } },
      { path: 'sync-monitor', name: 'sync-monitor', component: () => import('@/pages/system/SyncMonitorPage.vue'), meta: { permission: ['sync-log-list', 'sync-now', 'manage-devices'] } },
      { path: 'sync-conflicts', name: 'sync-conflicts', component: () => import('@/pages/system/SyncConflictsPage.vue'), meta: { permission: ['sync-conflict-list', 'resolve-sync-conflicts'] } },
      // Sync Center: this installation's own sync agent (Offline Mode). On an
      // Online installation the page explains there is nothing to synchronize.
      { path: 'sync-center', name: 'sync-center', component: () => import('@/pages/system/SyncCenterPage.vue'), meta: { permission: ['sync-now', 'sync-log-list'] } },

      // Module previews — Products/Inventory, POS, Sales, Purchases... arrive per phase
      { path: 'coming-soon/:module', name: 'coming-soon', component: () => import('@/pages/ComingSoonPage.vue') },

      // Aliases matching the sidebar's legacy-style single-word URLs
      { path: 'user', name: 'user-alias', component: () => import('@/pages/users/UsersPage.vue') },
      { path: 'role', name: 'role-alias', component: () => import('@/pages/roles/RolesPage.vue') },
      { path: 'branch', name: 'branch-alias', component: () => import('@/pages/branches/BranchesPage.vue') }
    ]
  },
  {
    path: '/:catchAll(.*)*',
    component: () => import('@/pages/ErrorNotFound.vue')
  }
]

export default routes
