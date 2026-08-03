# FAZIL_ERP_REUSE.md — Phase 0 parts-bin catalog

> Catalog of what the **fazil-erp** repository contains and exactly what the v2
> Products/Inventory and POS modules will take from it. Per `MASTER_PROMPT.md`
> §1, fazil-erp is the **implementation base for Products/Inventory and POS**
> (start from its code, restyle per the Aria design system, bend to the v2
> functional spec) and a general backup library of ready pages/components.
> fazil-erp is **read-only**; all porting copies code into `afghan-china`.
>
> Path convention: `fazil:<path>` = path relative to the
> `claude/keller-double-entry-gl` tree; `pharm:<path>` = path relative to the
> `claude/pharmacy-shop-setup-d79367` tree. Every claim below was verified
> against the real files on 2026-07-20.

---

## 1. Source pinning

### 1.1 The authoritative snapshot

| Fact | Value |
|---|---|
| Repository | `BriskCode123/fazil-erp`, local read-only clone at `/home/user/fazil-erp` |
| Branch | `origin/claude/keller-double-entry-gl` |
| Commit | `6f0e11cfb478057ce1f1492a08e8455d44e3e3b7` — 2026-06-25 20:17:42 +0000, "Add Chart of Accounts (groups + tree), Users/Roles pages, POS multi-tender" |
| Tree size | **1,269 files** (`git ls-tree -r … | wc -l`) |
| Identity | `fazil:README.md` calls it **"Daftar ERP (Rebuild)"** — a rebuild of the prior Daftar/MarkERP ERP; the Electron packaging brands it "Fazil ERP" (`fazil:electron/package.json`). The README's "Laravel 12 (PHP 8.4)" line is stale — `fazil:backend/composer.json` (`laravel/framework ^13.8`, `php ^8.3`) is authoritative. |

The checked-out branch and `main` of `/home/user/fazil-erp` contain only a
`.gitignore` — **all content lives on the side branches above.** Do not check
anything out there. Read without touching the repo:

```bash
# single file
git -C /home/user/fazil-erp show origin/claude/keller-double-entry-gl:frontend/src/pages/sale/PosPage.vue
# list a directory
git -C /home/user/fazil-erp ls-tree origin/claude/keller-double-entry-gl:backend/app/Models
# extract the whole tree into a scratch dir (never into the repo)
git -C /home/user/fazil-erp archive origin/claude/keller-double-entry-gl | tar -x -C <scratch-dir>
```

An extracted copy already exists in the session scratchpad
(`…/scratchpad/fazil-keller` and `…/scratchpad/fazil-pharmacy`) — also
read-only.

### 1.2 The newer pharmacy scaffold (`claude/pharmacy-shop-setup-d79367`)

HEAD `eaf096e8ddb003c1220e7b572043aede73712f83` (2026-07-13, author
FazilNusrat). **Its whole tree is only 20 files** — its merge-base with the
keller branch is the repo's bare initial commit (`01d31f6`), so it is an
**overlay of changed files**, not a full application: each file replaces (or
adds to) the keller equivalent. What it adds:

- **Batch/lot + expiry layer**: `pharm:backend/database/migrations/2026_07_13_000001_create_stock_batches_table.php`
  (`stock_batches(company_id, item_id, store_id, batch_no default 'UNBATCHED', expiry_date, quantity)`,
  unique per position, plus `batch_no`/`expiry_date` columns on `purchase_items`);
  model `pharm:backend/app/Models/StockBatch.php`.
- **FEFO engine** `pharm:backend/app/Support/BatchStock.php` —
  `receive()/withdraw()/consumeFefo()/restoreFefo()` (sales consume
  nearest-expiry lots first; oversell goes negative on `UNBATCHED` so batch
  totals always reconcile with `stocks`).
- **Hooked controllers**: `pharm:backend/app/Http/Controllers/Sale/SaleController.php`
  (`applyStock()` additionally calls `BatchStock::consumeFefo/restoreFefo`,
  lines 177-184) and `…/Purchase/PurchaseController.php` (validates + stores
  per-line batch/expiry, calls `BatchStock::receive/withdraw`).
- **New endpoints**: `GET /stock-batches` (`pharm:backend/routes/api.php:76`)
  and `GET /reports/expiry?days=N` (`pharm:backend/routes/api.php:196`,
  handler `pharm:backend/app/Http/Controllers/Report/ReportController.php:212-247`
  — expired / critical ≤30d / warning buckets).
- **Frontend**: `pharm:frontend/src/pages/reports/ExpiryReportPage.vue`,
  batch/expiry columns on purchase lines
  (`pharm:frontend/src/pages/purchase/PurchasesPage.vue:118,121,259,314`),
  route `reports/expiry` (`pharm:frontend/src/router/routes.js:86`), menu entry
  (`pharm:frontend/src/layouts/menus.js:800-806`), batch/expiry i18n keys.
- **Retail seeding/RBAC**: `pharm:backend/database/seeders/PharmacyShopSeeder.php`
  (955 ln — 145 products / 24 categories, 4 stores incl. "Sales Counter"
  code `POS` and "Bulk Warehouse", expiry-dated batches, balanced CoA),
  `PharmacyRolePermissionSeeder.php` (**8 roles**: Pharmacy Owner / Pharmacy
  Manager / Pharmacist / Cashier / Purchase Officer / Store Keeper /
  Accountant / Auditor), one-command installer
  `pharm:backend/app/Console/Commands/PharmacyInstall.php`
  (`php artisan pharmacy:install [--fresh]`).

**Why it matters for v2**: it is the reference implementation for expiry/batch
tracking (the `expiry_tracking` flag on keller items is otherwise inert) and a
ready-made retail role matrix incl. a POS-only Cashier role.

Other branches, for completeness: `claude/kind-keller-v59qef` (2026-06-22,
1,252 files — verified ancestor of keller, ignore) and
`claude/practical-pascal-y8b97l` (39 files — early multi-tab POS scaffold,
superseded by keller's `PosPage.vue`).

### 1.3 ⚠️ `_legacy/` is NOT the afghan-china legacy system

`fazil:_legacy/` is **fazil-erp's OWN prior ERP** (MarkERP/Daftar: Laravel
≤7-era backend — string controller routes, `app/User.php` at app root, 85
migrations 2013→2020; Quasar + Vuex 4 + Options-API-mixins frontend, 402 .vue
files). It is a mining ground for server-side Excel/PDF exports (§6 gem 15) —
nothing more. The **afghan-china behavioral legacy** referenced by
REQUIREMENTS.md is a different repo (`afg-china-olddddd`), which is currently
**empty in this workspace** (`docs/WORKSPACE_MAP.md` §1/§4). Never treat
`_legacy/` behavior as [BASELINE] spec.

---

## 2. Module map

### 2.1 Frontend routes/pages (from `fazil:frontend/src/router/routes.js`, 154 ln)

Layouts: `fazil:frontend/src/layouts/AuthLayout.vue`, `MainLayout.vue`, nav
tree `menus.js`. "Full" = complete CRUD/report page built on the shared global
components (§5).

| Domain | Route → page (`fazil:frontend/src/pages/…`) | Maturity |
|---|---|---|
| Auth/Company | `/login` → `auth/LoginPage.vue` (201 ln); `/company/create` → `company/CompanyCreate.vue` (162); `/company` → `company/CompanySwitchPage.vue` (138) | full |
| Dashboard | `/` → `DashboardPage.vue` (1,046 ln, KPI cards + trends) | full |
| Users/Roles | `/users`(+create/edit) → `users/UsersPage.vue`, `UserForm.vue`; `/roles`(+create/edit) → `roles/RolesPage.vue`, `RoleForm.vue` (permission matrix) | full |
| Accounting masters | `/chart-of-accounts` → `account/ChartOfAccountsPage.vue` (tree); `/account-groups` → `AccountGroupsPage.vue`; `/account`, `/customer`, `/supplier` (+create/edit) → thin wrappers over shared `account/LedgerCrud.vue` (308 ln) | full |
| Vouchers | `/receipt`, `/payment`, `/contra` → 13-line wrappers over `voucher/VoucherCrud.vue` (288 ln). Journal type exists backend-side only (no route) | full |
| Master fast-entry | `/ledger_entry` → `master/LedgerEntryPage.vue` (296); `/ledger_opening`, `/item_entry`, `/item_opening` (thin) | functional |
| **Sales & POS** | `/pos` → `sale/PosPage.vue` (1,019 ln — §4); `/sale` → `SalesPage.vue` (329, offline-capable); `/sale_return` → `SaleReturnsPage.vue`; `/sale_order`, `/sale_quotation` → shared `trade/TradeDocCrud.vue`; `/sale/:id/print` → `InvoicePrint.vue` (209, bare layout, `routes.js:135-140`) | full |
| Purchases | `/purchase`, `/purchase_return`, `/purchase_order`, `/purchase_quotation`; `/purchase/:id/print` → `BillPrint.vue` | full |
| **Inventory** | `/inventory/items`(+create/edit) → `inventory/ItemsPage.vue` (171) / `ItemForm.vue` (568); `/inventory/categories`, `/inventory/stores`, `/inventory/units`, `/inventory/stock`; `/stock_issue`, `/store_transfer`, `/stock_receive` (§3) | full |
| Manufacturing | `/raw`, `/costing/create`, `/material_issue`, `/transfer_note` | functional (backend routes `only(['index','store','destroy'])`) |
| HR | `/department`, `/designation`, `/employee`(+form, 614 ln photo upload), `/master_attendance/create`, `/fast_attendance/create`, `/payroll`, `/salary_payment` | full/functional |
| Reports | `/reports/{ledger,stock,sales,purchases,aging}` + GL-driven `/reports/{trial-balance,profit-loss,balance-sheet}` | full, real endpoints |
| Settings | `/area`, `/route`, `/salesman`, `/delivery_man`, `/marketing_representative`, `/currency`, `/financial_year` | simple, complete |
| System | `/settings` (613 ln company profile + prefixes), `/barcode` → `system/BarcodePage.vue` (545, §6), `/theme`, `/offline` (offline data manager), `/log` (activity log), `/notification` | full/functional |
| System stubs | `/backup` (backend returns canned JSON, `fazil:backend/routes/api.php:188`), `/trash` (backend returns `[]`, `api.php:185`), `/control_room` (nav hub) | **stub** |
| Super-admin | `/admin` → `admin/SuperAdminPage.vue`; `/super-dashboard` → `SuperDashboardPage.vue` (571, cross-company stats) | full |

State management: **the only Pinia store is `fazil:frontend/src/stores/auth.js`**
— every page holds local `ref`/`reactive` state and calls the API directly.
i18n dictionaries: `fazil:frontend/src/i18n/en/index.js` (2,230 ln),
`fa/index.js` (Farsi, 2,201 ln), `pa/index.js` (Pashto, partial, 416 ln).

### 2.2 Backend coverage

Framework: Laravel `^13.8` / PHP `^8.3` / Octane / Sanctum 4 /
spatie-permission 8 (`fazil:backend/composer.json`). API surface:
`fazil:backend/routes/api.php` (204 ln) — Sanctum-guarded, `tenant` middleware
group, apiResources per domain, `GET /next-number?type=…` generator (closure at
`api.php:89-117`, 21-type map), activity-log feed, report endpoints
(`api.php:191-202`).

Controllers by domain (`fazil:backend/app/Http/Controllers/`): `Auth/`,
`Company/`, `Admin/` (super admin), `User/`, `Role/`; `Account/`
(AccountGroup incl. `tree`, Ledger incl. bulk/opening, Voucher);
`Inventory/` (Item, ItemCategory, Unit, Store, Stock, StockIssue,
StockReceive, StoreTransfer); `Sale/` (Sale, SaleReturn, SaleOrder w/
`convert`, SaleQuotation); `Purchase/` (mirror of Sale);
`HR/` (Department, Designation, Employee, Attendance, Payroll, SalaryPayment);
`Manufacturing/` (RawMaterial, Costing, MaterialIssue, TransferNote);
`Settings/` (Area, Route, Salesman, DeliveryMan, MarketingRep, Currency,
FinancialYear); `Report/ReportController` + `Reports/FinancialReportController`;
`Dashboard/`, `NotificationController`.

Models: **55 model classes** in `fazil:backend/app/Models/` (plus `Concerns/`,
`Scopes/` dirs) — doc-header/doc-item pairs for every document type, the
accounting trio (AccountGroup, Ledger, LedgerEntry, Voucher), inventory
(Item, ItemCategory, Unit, Store, Stock + movement docs), HR, manufacturing,
CRM-lite masters, Company/Currency/FinancialYear/ActivityLog/Notification/User.

Tenancy (identical pattern to Aria): `fazil:backend/app/Support/Tenant.php`,
`app/Http/Middleware/SetTenant.php`, `app/Models/Scopes/CompanyScope.php`,
`app/Models/Concerns/BelongsToCompany.php`.

Seeders: Permission, DemoData, Desktop, MultiCompany, Transaction,
ActivityLog, Notification (`fazil:backend/database/seeders/`). Tests: only
`tests/Feature/FoundationTest.php` + examples — effectively untested.

### 2.3 Double-entry GL design (the branch's headline feature)

- `account_groups` (`fazil:backend/database/migrations/2026_06_18_101651_…`):
  per-company, `nature` asset|liability|income|expense, `system` flag.
- `ledgers` (`…_101652_…`): **unified account/party master** — one table for
  customers, suppliers and GL accounts (`type`), `group_id`,
  `balancing_method` (1 Dr / 2 Cr), `opening_balance`, contact fields,
  currency, soft deletes.
- `vouchers` (`2026_06_18_130002_…`): receipt|payment|contra (+journal),
  `from_ledger_id` → `to_ledger_id` money-flow pair, amount, mode, reference.
- `ledger_entries` (`2026_06_21_000000_…`): the journal — one row per Dr/Cr
  leg; `type` debit|credit, `amount`/`currency`/`rate`/`base_amount`
  (multi-currency-aware), `source_type`/`source_id`
  (opening|voucher|sale|purchase), indexed (company,ledger), (source),
  (company,date).
- Posting engine `fazil:backend/app/Support/LedgerPosting.php` (253 ln):
  `postVoucher/postSale/postPurchase/postOpenings/repostCompany` + reverse
  twins; private `reverseSource()` (line 57) makes re-posting idempotent;
  self-healing `systemGroup()/systemLedger()` helpers. Wired via
  `fazil:backend/app/Observers/SaleObserver.php` + `PurchaseObserver.php`
  (registered `fazil:backend/app/Providers/AppServiceProvider.php:23-24`).
- Default CoA: `fazil:backend/app/Support/DefaultAccountGroups.php` — 18
  nature-tagged Tally-style groups seeded per company.
- Statements from the GL: `fazil:backend/app/Http/Controllers/Reports/FinancialReportController.php`
  (trial balance w/ `balanced` flag, P&L, balance sheet via account nature).

---

## 3. Products/Inventory port plan

### 3.1 Exact files

**Frontend pages** (`fazil:frontend/src/pages/…`):

| File | Role |
|---|---|
| `inventory/ItemsPage.vue` (171 ln) | Product list on `action-bar` + `n-table`: code, name, category, unit, type chip, sale_price, active; rich info modal (image, prices, MRP, barcode, min stock). Create/edit are routed pages (`routes.js:38-40`, perms `item-list/create/edit`). |
| `inventory/ItemForm.vue` (568 ln) | The full product form — 3 tabs (§3.2). |
| `inventory/ItemCategoriesPage.vue` (131) | Modal CRUD, name + `parent_id` (hierarchical). |
| `inventory/UnitsPage.vue` (127) | Modal CRUD (name + abbreviation). |
| `inventory/StoresPage.vue` (146) | Modal CRUD for stores/warehouses (name, code, address, phone, active). |
| `inventory/StockPage.vue` (95) | Read-only stock grid (`GET /stocks`), client-side store filter, red highlight + tooltip when qty ≤ `item.min_stock`. |
| `inventory/StockReceivePage.vue` (191) | Adjustment-IN document (receive_no via `/next-number?type=stock-receive`, date, store, lines item/qty/cost). |
| `inventory/StockIssuePage.vue` (190) | Adjustment-OUT, same shape. |
| `inventory/StoreTransferPage.vue` (194) | Store→store transfer, client-validates from ≠ to. |
| `master/ItemEntryPage.vue` (76) | Bulk fast item creation → `POST /items/bulk`. |
| `master/ItemOpeningPage.vue` (90) | Opening stock per store → `POST /stock/opening` (**broken as-is — §3.4**). |
| `system/BarcodePage.vue` (545) | Barcode label manager: per-item copies, label size S/M/L, paper A4/A5/Letter, toggles, live preview, **self-contained Code 128B canvas renderer** (encoder table ~lines 299-336, zero deps), popup-window print. |
| `reports/StockReportPage.vue` (209) | Stock + low-stock report (`GET /reports/stock`, `/reports/low-stock`). |

**Backend** (`fazil:backend/…`):

- Models: `app/Models/{Item,ItemCategory,Unit,Store,Stock,StockReceive,StockReceiveItem,StockIssue,StockIssueItem,StoreTransfer,StoreTransferItem}.php`
  (Item: `$guarded=['id']`, `casts()` method, appended `image_url` accessor).
- Migrations: `database/migrations/2026_06_18_070000_create_item_categories_table.php`,
  `…070100_create_units_table.php`, `…070200_create_stores_table.php`,
  `…070300_create_items_table.php`, `…070400_create_stocks_table.php`,
  `2026_06_18_150000_create_stock_movements_table.php` (stock_issues + store_transfers),
  `2026_06_18_180000_create_stock_receives_table.php`,
  `2026_06_19_200000_add_extra_fields_to_ledgers_and_items.php`
  (barcode, expiry_tracking, reorder_point, hsn_code, location),
  `2026_06_20_100000_add_image_to_items_table.php`,
  `2026_06_20_210001_expand_items_table.php` (the pharmacy-grade expansion).
- Controllers `app/Http/Controllers/Inventory/`: `ItemController.php`
  (apiResource + `POST items/bulk`; full per-field validation rules at lines
  14-66; image upload to `storage/public/items`; destroy blocked if used in
  sales/purchases, lines 105-110), `ItemCategoryController.php`,
  `UnitController.php`, `StoreController.php` (plain apiResources),
  `StockController.php` (`GET /stocks` w/ item.unit+store; `POST /stock/opening`),
  `StockReceiveController.php` / `StockIssueController.php` /
  `StoreTransferController.php` (index/store/destroy; `DB::transaction`,
  `Stock::firstOrCreate` + `increment/decrement` per line; every destroy
  reverses stock symmetrically).
- Endpoints (`routes/api.php:69-75` masters+stocks, `:138-141` movement docs,
  `:89-117` next-number): `item-categories`, `units`, `stores`, `items` (+
  `items/bulk`), `stocks`, `stock/opening`, `stock-issues`, `store-transfers`,
  `stock-receives` (movements are `only(['index','store','destroy'])`).
- Permissions: spatie `entity-action` names generated by
  `fazil:backend/database/seeders/PermissionSeeder.php` (entities × actions
  `list/create/edit/show/delete` — e.g. `item-list`, `stock-receive-create`).

### 3.2 Product field model (as implemented on `items`)

Base (`…070300`): `code`, `name`, `category_id`, `unit_id`, `type`
(product|service|raw-material), `purchase_price`, `sale_price`, `min_stock`
(all prices decimal 15,4), `description`, `active`. Extra (`…_200000`):
**`barcode`**, **`expiry_tracking`** (bool flag; no batch logic in keller —
that is the pharmacy overlay), `reorder_point`, `hsn_code`, `location`.
Image (`…100000`): `image` path + `image_url` accessor, multipart upload
PNG/JPG ≤2 MB. Expansion (`…210001`): `generic_name`, `packing`,
**`mrp_rate` + 3 price tiers `rate_a`/`rate_b`/`rate_c`**, `unit_measure_name`,
`item_company` (manufacturer), `item_group`, `item_salt`, `item_type_detail`,
`rack_no`, `near_to_expire_date` (days, default 90), `fast_search`,
`tax_enabled` + `tax_percentage`, `negative_stock`, and a full discount-scheme
block: `volume_discount`, `item_discount`, `special_discount`,
`discount_on_qty`, `special_on_qty`, `max_discount`, `purchase_discount`,
`min_margin`, `free_scheme` + `free_qty`, `discount_from`/`discount_to`.

`ItemForm.vue` exposes all of this in 3 tabs (Pricing/Unit, Product Details,
Discounts) with `n-select-add` inline quick-add for category/unit, MRP
auto-fill from purchase price and a "selling at loss" warning when
MRP < purchase price (`ItemForm.vue:392-400`).

**Caveat**: the discount-scheme and tier-rate fields are *stored but enforced
nowhere* — no pricing engine reads them (POS uses `sale_price` only). Port the
schema; plan the logic as new v2 work.

### 3.3 Stock model (warehouse/branch/shop split as implemented)

- **One quantity row per (company, item, store)**:
  `fazil:backend/database/migrations/2026_06_18_070400_create_stocks_table.php`
  — `stocks(company_id, item_id, store_id, quantity dec(15,4))`, unique
  `(company_id, item_id, store_id)`.
- Stores are generic records (`…070200`: name, code, address, phone, active).
  **There is no branch entity and no shop-vs-warehouse type flag** — the split
  is by convention only (the pharmacy seeder names stores "Sales Counter"
  code `POS` vs "Bulk Warehouse" code `WH`).
- **No stock-movement ledger table** — quantity is mutated in place inside
  each document controller's transaction; the documents
  (receives/issues/transfers/sales/purchases/returns) plus
  `ActivityLog::log()` rows are the audit trail.
- Multi-tenant scoping on every table via `company_id` + `CompanyScope` +
  `BelongsToCompany` (auto-fills company on create), activated by the
  `tenant` middleware (`SetTenant`).
- Optional lot layer: the pharmacy overlay's `stock_batches` +
  `BatchStock` FEFO service (§1.2) subdivides each `stocks` position into
  expiry-dated lots while keeping `stocks` the source of truth for totals.

### 3.4 Portable as-is vs must-rewrite

**Portable essentially as-is** (restyle only):
- The whole `items` schema + `ItemController` validation set — an unusually
  complete retail product model, clean and tenant-safe.
- The per-store `stocks` design and the three symmetric, transactional
  movement controllers (every destroy reverses stock).
- `ItemsPage`/`ItemForm`/`StockPage`/movement pages — `<script setup>` Quasar 2
  pages that depend only on the shared global components (§5).
- `BarcodePage.vue` with the dependency-free Code 128B renderer.
- `VoucherNumber` + `/next-number` document numbering
  (`fazil:backend/app/Support/VoucherNumber.php` — `PREFIX-YYYYMMDD-0001`,
  `lockForUpdate()` in a transaction).

**Must fix / rewrite during the port**:
1. **`StockController::opening` is broken**: it `updateOrCreate`s with a
   `'cost' => $data['cost'] ?? 0` attribute (`fazil:backend/app/Http/Controllers/Inventory/StockController.php:27-30`)
   but the `stocks` table **has no `cost` column** — every opening call writes
   an unknown column and fails on strict SQL. Either add the column or drop
   the field.
2. **Item edit loads the whole list**: `ItemForm.vue` fetches `GET /items` and
   `.find()`s the row (`fazil:frontend/src/pages/inventory/ItemForm.vue:422-431`)
   although `ItemController::show` exists (line 86). Switch to
   `GET /items/{id}`.
3. **No pagination anywhere** — all index endpoints `->get()` everything
   (items, stocks, sales…). Acceptable for one shop; add server pagination if
   v2's catalog is large.
4. **Sloppy permission metas**: `routes.js:71-72` gate `store_transfer` /
   `stock_receive` pages on `store-list`; movement-doc pages reuse odd perms
   for delete buttons. Re-map to v2's permission matrix (REQUIREMENTS §4).
5. Discount/tier fields need an actual pricing engine (see §3.2 caveat).
6. `items/bulk` only accepts name/code/unit/price/cost — extend if v2 fast
   entry needs more fields.

### 3.5 SPEC-PENDING bends (v2 `docs/REQUIREMENTS.md` not yet in workspace)

The v2 functional spec is absent (`docs/WORKSPACE_MAP.md` §4 — main repo was
empty). The following bends are anticipated from MASTER_PROMPT language but
**must be confirmed against REQUIREMENTS.md when it arrives**:

- **SPEC-PENDING — branch-scoped stock**: fazil has stores but no branch
  concept. If v2 requires branch-scoped inventory (as Aria's backend models
  with its `X-Branch-Id` header + `SetBranch` middleware suggest for the
  platform), decide whether v2 "branches" map 1:1 onto fazil `stores`, or a
  `branch_id` is added above/alongside `store_id` on `stocks` and all
  movement documents. The unique key `(company_id, item_id, store_id)` and
  every `applyStock` call site are the touch points.
- **SPEC-PENDING — `shop_inventory` deduction**: if v2 distinguishes warehouse
  stock from shop-floor (`shop_inventory`) stock with sales deducting from the
  shop pool and replenishment transfers from warehouse→shop, implement it as
  either (a) two conventional stores + the existing `StoreTransfer` flow, or
  (b) a typed store (`kind: warehouse|shop`) with POS bound to the shop store.
  Option (a) is nearly free with ported code.
- **SPEC-PENDING — expiry/batch**: if v2 sells date-sensitive goods, lift the
  pharmacy overlay (§1.2) wholesale; otherwise keep only the `expiry_tracking`
  flag dormant.

---

## 4. POS port plan

### 4.1 Exact files

- **`fazil:frontend/src/pages/sale/PosPage.vue`** (1,019 ln) — the entire POS
  screen; route `/pos` at `routes.js:55` — **note: no `meta.permission`**, any
  authenticated user can open it; only the menu entry gates on `sale-list`
  (`fazil:frontend/src/layouts/menus.js:375-383`). Add a proper `pos`-scoped
  permission in v2.
- Supporting: `fazil:frontend/src/pages/sale/SalesPage.vue` (back-office
  invoice CRUD, currency + exchange rate, offline-capable),
  `sale/InvoicePrint.vue` (print route), `sale/SaleReturnsPage.vue`,
  `purchase/BillPrint.vue`.
- Backend: `fazil:backend/app/Http/Controllers/Sale/SaleController.php`
  (apiResource `sales`, `routes/api.php:119`),
  `Sale/SaleReturnController.php` (apiResource `sale-returns`),
  migrations `2026_06_18_120000_create_sales_table.php` (sales + sale_items,
  softDeletes on sales), `2026_06_20_200000_add_time_to_sales_table.php`,
  `2026_06_19_300000_add_currency_to_sales_purchases.php` (currency + locked
  `exchange_rate`, default AFN — matches Aria's rate-at-entry doctrine),
  `2026_06_19_000002_add_reason_ref_to_returns_tables.php`.

### 4.2 Mechanics as implemented (verified in `PosPage.vue`)

- **Layout**: left product browser (search box, category chips, responsive
  product-card grid with image/code/price/stock badge/in-cart qty badge),
  right dark cart panel; Fullscreen API toggle (~lines 438-450).
- **Multi-tab / held bills** (lines ~376-429): `orders` array of fully
  isolated `{id, seq, label 'Bill N', cart[], customer_id, discount}`; tab bar
  with per-tab qty badge and running total; `newOrder()/closeOrder()`
  (confirm dialog on closing a non-empty bill); **persisted to
  `localStorage['pos_orders_v1']`** on every change via deep watcher
  (`STORAGE` const at line 379, save at line 428) — parked bills survive
  refresh/close. After checkout the bill is spliced out and focus falls to the
  previous bill (`charge()`, lines 590-593).
- **Barcode flow** (`onSearchKey`, lines 456-466): Enter in the search input
  exact-matches input against `item.barcode` or `item.code` and adds to cart —
  works with any keyboard-wedge USB scanner. A `scanMode` toggle buffers
  keystrokes into `barcodeBuffer` with a 120 ms reset timer, **but the buffer
  is never consumed — scan mode is cosmetic**; the Enter path is the real
  mechanism. The same input live-filters the grid by name/code/barcode
  (`filteredItems`, lines 469-481).
- **Cart**: click-to-add/increment, +/- steppers, line remove, clear-cart
  (resets customer + discount); line price is a snapshot of `item.sale_price`
  at add time; unit price NOT editable in POS (only in SalesPage).
- **Discount**: one order-level absolute discount input; per-line `discount: 0`
  is hardcoded in the payload; tier rates (`rate_a/b/c`), `max_discount` and
  the item discount schemes are **not consulted**; there is **no manager
  approval/override mechanism anywhere** (frontend or backend).
- **Customer**: optional select over ledgers `type=customer` + quick-add
  dialog (name+phone → `POST /ledgers`).
- **Payment dialog**: total due; **multi-tender split** — `tenders[]` rows of
  `{method: cash|card|transfer, amount}` (methods defined at lines 432-435),
  add/remove tender, on-screen numpad bound to the focused tender,
  change/remaining indicator, editable sale date & time, defaults to a single
  cash tender for the full amount (line 551).
- **Checkout** `charge()` (lines 568-599): `POST /sales` with
  `{invoice_no:'', date, time, customer_id, store_id: null, discount, tax:0,
  paid: paidSum, payments:[{method,amount}], items:[{item_id, quantity, price,
  discount:0}]}`; on success opens `/sale/{id}/print` in a new tab.
- **Receipt**: `InvoicePrint.vue` — bare-layout route, **A4 "TAX INVOICE"**
  (items table, subtotal/discount/tax/total/paid/balance, signature row),
  auto `window.print()` on mount, closes after print (lines 128-138).
  **No 80 mm thermal layout exists** — build one for v2 if required
  (SPEC-PENDING). Barcode *labels* print from `system/BarcodePage.vue`.
- **Keyboard shortcuts**: `fazil:frontend/src/components/general/ShortcutsPanel.vue`
  documents Alt+D/S/P/C/R navigation, Ctrl+K search etc., but its `onKey`
  handler (lines 86-94) implements **only `?` (toggle panel) and Alt+T (dark
  mode)**. Nothing POS-specific (no F-keys for pay/hold) — advertised
  shortcuts are vapor; implement or trim in v2.
- **Offline**: a full offline stack exists (§5) **but PosPage imports the
  plain `api` from `boot/axios`, not `offlineApi`** — the POS itself is not
  offline-capable in fazil. `SalesPage.vue:182` and `PurchasesPage.vue:181` do
  import `offlineApi as api`. Additionally the sync replay endpoint map is
  buggy: `fazil:frontend/src/services/syncService.js:33-34` replays to
  `POST /sale` / `POST /purchase` (singular) while real routes are `/sales`,
  `/purchases` — queued offline mutations would 404; and the queue drops an
  entry after 5 failed attempts (`syncService.js:93-97`).

### 4.3 Backend sale/refund endpoints and stock deduction

`SaleController` — `store()/update()/destroy()` inside `DB::transaction`;
server recomputes subtotal/total from lines (`header()`:
`total = max(0, subtotal − discount + tax)`); auto invoice number when blank
(`VoucherNumber`); `ActivityLog::log()` on each write; `SaleObserver`
auto-posts every sale to the double-entry GL via `LedgerPosting::postSale`.

- **Stock deduction** `applyStock()` (SaleController.php:158-176):
  per line `Stock::firstOrCreate([company_id, item_id, store_id: sale->store_id])`
  then `quantity += sign * qty` (−1 create, +1 restore on update/delete).
  **It returns early when `store_id` is null (lines 160-162) — and the POS
  always sends `store_id: null` (`PosPage.vue:578`), so POS sales in stock
  fazil never deduct stock.** v2 must bind the POS to a configured store
  (SPEC-PENDING: the shop store per §3.5).
- **Multi-tender is UI-only**: `validateData()` (lines 87-107) has **no
  `payments` rule** — the tender array POSTed by the POS is silently dropped;
  only scalar `paid` persists on `sales`. **No payments/tenders table exists
  in any migration.** v2 needs a `sale_payments` table + validation if split
  tender must be real (and it should be, for the cash-drawer/Z-report side of
  a POS spec).
- **Stock badge bug**: product cards read `item.quantity`
  (`PosPage.vue:91-93`) but `ItemController::index` (line 68-71) returns items
  with only `category`/`unit` — no quantity field. Badges always show 0/red.
  v2 must join per-store stock into the POS product feed.
- **Refund/return flow**: `SaleReturnController` + `SaleReturnsPage.vue` —
  standalone return documents (`return_no` auto `SR-…` via VoucherNumber,
  line 34), **free-text `invoice_no` reference to the original invoice — no FK,
  no lookup, no qty-sold validation**; reason + notes fields; lines re-priced
  manually; `applyStock(+1)` restores goods into the return's store
  (line 40), update/destroy reverse correctly. **No money movement is created
  for the refund** and the flow is not reachable from the POS screen. v2:
  link returns to the original sale, validate quantities, emit a refund
  payment, and surface it in the POS (SPEC-PENDING for exact rules).

### 4.4 Portable vs rewrite — POS summary

Portable: the entire UX layer (multi-bill tabs + localStorage persistence,
split-tender dialog + numpad, category-chip browser, customer quick-add,
fullscreen), the sale schema with currency + locked rate, server-side total
recomputation, GL auto-posting, VoucherNumber invoicing, InvoicePrint page
structure.

Rewrite/add: POS store binding (deduction!), real `sale_payments`, stock in
the product feed, working barcode scan-mode (or delete the toggle), per-line
discount + tier-price + `max_discount` enforcement with manager override,
returns integration + refund money movement, thermal receipt layout, real
keyboard shortcuts, offline wiring (`offlineApi` in PosPage + fix
`syncService` endpoint map) if v2 wants an offline-capable POS, POS
permission meta.

---

## 5. Shared infrastructure a port drags along

### 5.1 What fazil pages assume

- **API client** `fazil:frontend/src/boot/axios.js` — axios instance; base URL
  `window.__ELECTRON_API_URL__` → `VITE_API_URL` → `http://localhost:8000`;
  Bearer token persisted in `localStorage['auth_token']` (`api.setToken`);
  `withCredentials: true`; `$api`/`$axios` globals.
- **Auth store** `fazil:frontend/src/stores/auth.js` (Pinia): user /
  permissions / roles, `can()` getter with Super Admin bypass; route guard in
  `fazil:frontend/src/router/index.js` (requiresAuth, guest, force
  company-create, per-route `meta.permission`).
- **Global component kit** registered in `fazil:frontend/src/boot/globals.js` —
  every inventory/POS page uses these aliases: `m-header` (AppHeader),
  `progress-btn`, `m-backgrounds` (PageBackground), `m-modal` (MainModal),
  `n-header` (ModalHeader), **`n-table`**
  (`fazil:frontend/src/components/tables/DataTable.vue`, 325 ln — q-table
  wrapper with permission-gated edit/info/print/delete actions, column
  show/hide, fullscreen/dense toggles, built-in record-info modal, CSV/XLS/PDF
  export), `n-name`/`n-simple` (field wrappers), `n-submit`,
  **`n-select-add`** (`components/fields/SelectAdd.vue` — self-loading lookup
  select with inline quick-add popup; used for category/unit/store/customer),
  **`action-bar`** (`components/general/ActionBar.vue` — AddNew + advanced
  search + date filter + XLSX/CSV import + export-btn), `export-btn`,
  `shamsi-date` (ShamsiDatePicker), `shortcuts-panel`; plus globals `$can`,
  `$delete` (confirm+DELETE helper used by every list page),
  `$fmtDate`/`$fmtDateTime` (`fazil:frontend/src/utils/date.js`).
- **i18n**: custom flat-key `$t('Key')` (`fazil:frontend/src/boot/i18n.js` +
  `src/i18n/{en,fa,pa}/index.js`). `DataTable`/`ActionBar` call `$t()` on
  column labels — ported pages render raw keys unless their keys exist in the
  v2 dictionaries.
- **Layout/menu**: `fazil:frontend/src/layouts/MainLayout.vue` + `menus.js`
  (permission-filtered nav tree with the POS/Inventory entries); theme CSS
  vars applied in `boot/offline.js`.
- **Offline stack** (optional, wired into boot):
  `fazil:frontend/src/services/localDb.js` (Dexie mirror of
  sales/sale_items/purchases/purchase_items/ledgers/items/stores/employees/units
  with `_synced/_dirty` + syncQueue), `services/offlineApi.js` (serves GET
  from IndexedDB when offline, queues mutations), `services/syncService.js`
  (push queue + 30 s pull; **endpoint-map bug**, §4.2), `boot/offline.js`,
  `components/general/SyncStatusBar.vue`, `pages/system/OfflinePage.vue`.
- **Backend cross-cutting**: `App\Support\Tenant` + `CompanyScope` +
  `BelongsToCompany` + `SetTenant`; `App\Support\VoucherNumber`;
  `App\Models\ActivityLog` (`::log()` called by Item/Sale controllers — port
  it or strip calls); `App\Support\LedgerPosting` + `SaleObserver`/
  `PurchaseObserver` (sales auto-post to the GL — port the accounting layer or
  deregister the observers in `AppServiceProvider`); spatie permissions +
  `PermissionSeeder`; Sanctum token auth (`Auth\AuthController`).

### 5.2 What v2 substitutes (same-origin advantage)

Aria's own README/provenance states its component layer was **cloned from
fazil-erp**, and it shows: Aria's `frontend/src/boot/globals.js` registers the
**identical alias set** — `m-header`, `progress-btn`, `m-backgrounds`,
`m-modal`, `n-header`, `n-table`, `n-name`, `n-simple`, `n-submit`,
`n-select-add`, `export-btn`, `action-bar`, `shamsi-date`, `shortcuts-panel`
(Aria adds `stat-card`, `tab-title`, and app-specific extras) — plus the same
`$can`/`$delete`/`$fmtDate` globals, the same single Pinia `auth` store, the
same custom `$t('Key')` i18n, and byte-identical `brand.css`
(`docs/PROGRESS.md`). Consequences for the port:

1. **Fazil page templates need no alias renaming.** v2 registers the
   **Aria-styled implementations** under the same names; ported
   inventory/POS pages pick up Aria's look through the shared components
   automatically. Never register fazil's component *implementations* where an
   Aria equivalent exists — Aria wins on look (MASTER_PROMPT precedence).
2. Diff before choosing: fazil's `DataTable`/`ActionBar` may carry features
   Aria's siblings lack (or vice versa). Rule: **Aria styling + superset of
   behavior**, implemented in v2's copy.
3. `boot/axios.js`, router guard, auth store: take **Aria's** (functionally
   equivalent lineage); keep fazil's `window.__ELECTRON_API_URL__` hook —
   Aria's axios boot already understands it too (`docs/WORKSPACE_MAP.md`
   §2.1 "Electron-ready").
4. i18n: v2 starts from Aria's dictionaries (862 keys en/fa) — **fazil's
   dictionaries are ~2,200 keys** incl. the entire inventory/POS vocabulary in
   EN + Farsi. Merge the fazil keys needed by ported pages into v2's dicts
   (fazil `fa` is the ready-made Farsi for every POS/inventory string).
5. Backend: Aria and fazil share the Tenant/CompanyScope/BelongsToCompany
   pattern and spatie `entity-action` permissions — fazil controllers drop
   into a v2 Laravel app modeled on Aria with pattern-level compatibility;
   only Aria-specific layers (branch header middleware, ActivityLog signature
   `log(action, module, desc, ?projectId)` vs fazil's 3-arg calls) need
   reconciling at call sites.

---

## 6. Reusable gems beyond Products/POS

1. **Double-entry posting engine** — `fazil:backend/app/Support/LedgerPosting.php`.
   Idempotent reverse-and-repost GL core with self-healing system ledgers and
   whole-company backfill (`repostCompany`); drop-in accounting spine for v2.
2. **GL schema quartet** — `fazil:backend/database/migrations/2026_06_18_101651_create_account_groups_table.php`,
   `…101652_create_ledgers_table.php`, `2026_06_18_130002_create_vouchers_table.php`,
   `2026_06_21_000000_create_ledger_entries_table.php`. Multi-currency journal
   (`amount/rate/base_amount`) matching v2's locked-rate doctrine.
3. **Financial statements from the GL** — `fazil:backend/app/Http/Controllers/Reports/FinancialReportController.php`.
   Trial balance (with balanced flag), P&L, balance sheet via account nature.
4. **Default chart of accounts** — `fazil:backend/app/Support/DefaultAccountGroups.php`.
   18 nature-tagged Tally-style groups seeded per company.
5. **Concurrency-safe document numbering** — `fazil:backend/app/Support/VoucherNumber.php`
   + the 21-type `GET /next-number` map (`fazil:backend/routes/api.php:89-117`).
6. **Offline-first stack** — `fazil:frontend/src/services/{localDb,offlineApi,syncService}.js`
   + `boot/offline.js` + `components/general/SyncStatusBar.vue` +
   `pages/system/OfflinePage.vue`. Complete Dexie mirror + queue pattern
   (fix the `/sale`→`/sales` replay map before use).
7. **Parameterized CRUD scaffolds** — `fazil:frontend/src/pages/voucher/VoucherCrud.vue`
   (receipts/payments/contras via 13-line wrappers),
   `pages/trade/TradeDocCrud.vue` (orders/quotations),
   `pages/account/LedgerCrud.vue` (customers/suppliers/accounts). The
   page-count-cutting pattern for v2's document modules.
8. **Barcode label designer** — `fazil:frontend/src/pages/system/BarcodePage.vue`.
   Zero-dependency Code 128B canvas renderer + label layout/print manager.
9. **ShamsiDatePicker** — `fazil:frontend/src/components/general/ShamsiDatePicker.vue`
   (170 ln Jalali picker; Aria carries the same component — keep whichever is
   newer, style per Aria).
10. **Farsi/EN ERP dictionary** — `fazil:frontend/src/i18n/en/index.js` (2,230 ln)
    + `fa/index.js` (2,201 ln, near-complete Farsi) + partial Pashto `pa/`.
    Ready-made translations for the whole retail/inventory/accounting domain.
11. **FEFO batch engine** — `pharm:backend/app/Support/BatchStock.php` +
    `pharm:backend/database/migrations/2026_07_13_000001_create_stock_batches_table.php`
    + `GET /reports/expiry`. The expiry/lot layer, cleanly liftable.
12. **Retail role matrix + demo seeding** — `pharm:backend/database/seeders/PharmacyRolePermissionSeeder.php`
    (8 roles incl. POS-only Cashier) and `PharmacyShopSeeder.php` (955 ln
    realistic shop dataset) + `pharm:backend/app/Console/Commands/PharmacyInstall.php`
    one-command installer — template for v2's role setup and demo data.
13. **Electron desktop packaging + licensing kit** — `fazil:electron/php-server.js`
    (bundled PHP boots Laravel on a free port, per-user SQLite data dir,
    generated .env + php.ini, migrate+seed on first run), `fazil:electron/main.js`,
    `license.js` (HMAC-SHA256 serials `FAZIL-XXXXX-…-CHECK`, 3-day trial,
    machine-salted encrypted store), `activation.html`, `fazil:build-desktop.bat`,
    `fazil:backend/.env.desktop`. A complete "sell an offline desktop ERP"
    pipeline (see §7 hardening notes).
14. **Client-side export composable** — `fazil:frontend/src/composables/useExport.js`
    + `components/general/ExportBtn.vue` (xlsx + jspdf-autotable, no server
    dependency) and ActionBar's XLSX/CSV import.
15. **Legacy server-side Excel/PDF layer** — `fazil:_legacy/backend/app/Exports/`
    (33 Maatwebsite export classes + `LedgerPdf.php`/`InventoryPdf.php`) and
    `fazil:_legacy/backend/app/Imports/` (13 import classes). The active
    rebuild only exports client-side; mine these if v2 needs server-rendered
    reports. (Reference-mine only — Laravel ≤7-era code, port patterns not
    files.)

---

## 7. Compatibility notes

### 7.1 Version matrix

| Layer | fazil-erp (keller) | Aria (v2's technology model) | Verdict |
|---|---|---|---|
| Quasar | `^2.20.0` (`fazil:frontend/package.json`) | `2.20.3` pinned | Same minor — zero migration. |
| Vue | `^3.5.22` | `^3.5.22` (locked 3.5.39) | Identical. |
| Vue Router | `^5.0.6` | `^5.0.6` (locked 5.1) | Identical. |
| Pinia | `^3.0.4` | `^3.0.4` | Identical. |
| Build | `@quasar/app-vite ^3.0.0-rc.3` (`#q-app` imports, Vite CLI) | `@quasar/app-vite 3.0.0-rc.5` | Same RC line; `defineBoot`/`#q-app` idioms match. |
| axios / dexie / xlsx / jspdf | `^1.18.0` / `^4.4.4` / `^0.18.5` / `^4.2.1` | axios 1.18, dexie 4.4, xlsx, jspdf | Aligned. |
| Laravel | `^13.8` (`fazil:backend/composer.json`) | `^13.8` (locked **v13.19.0**) | Identical constraint — fazil controllers/migrations (anonymous classes, `casts()`, bootstrap/app.php middleware) drop straight in. |
| PHP / Sanctum / spatie | `^8.3` / `^4.0` / `^8.0` | `^8.3` / `^4.0` (v4.3.2) / `^8.0` (8.3.0) | Identical. |
| Octane | `^2.17` present | — | v2 need not adopt Octane; no code depends on it. |

**Bottom line: there is no version-upgrade work in the port.** Both codebases
are the same stack generation (unsurprising — shared lineage). The only
translation costs are stylistic (Aria design system) and functional (v2 spec
bends), not framework migrations. Two minor deltas: fazil's `vueRouterMode` is
`'hash'` with `publicPath './'` (`fazil:frontend/quasar.config.js:44,48`) —
chosen for Electron `file://`; Aria uses hash mode too, so keep it. fazil
frontend lints with oxlint/oxfmt; adopt v2's own lint config instead.

### 7.2 Electron packaging notes

fazil ships the whole ERP as a Windows desktop app — relevant if v2's POS
must run as a standalone counter app:

- Pipeline `fazil:build-desktop.bat`: composer install (no-dev) → `quasar build`
  → patch `dist/spa/index.html` asset paths relative for `file://` →
  `npx electron-builder --win` → NSIS installer + portable exe. Requires a
  PHP 8.2 TS x64 runtime dropped in `fazil:electron/resources/php/`.
- `fazil:electron/package.json`: Electron `^31.0.0`, electron-builder
  `^24.13.3`; `extraResources` bundles `frontend/dist/spa` → `app/`, the whole
  `backend/` (minus storage/.env/tests) and the PHP runtime.
- `fazil:electron/php-server.js`: free port, per-user data dir (SQLite DB,
  storage tree, generated `.env` + runtime `php.ini` via `PHPRC`),
  `artisan migrate --force` + `db:seed --class=DesktopSeeder`
  (`fazil:backend/database/seeders/DesktopSeeder.php` — "My Company",
  `admin@daftar.test/password`), then `artisan serve` on `127.0.0.1:<port>`;
  URL reaches the SPA via preload IPC → `window.__ELECTRON_API_URL__` →
  `boot/axios.js`.
- **Hardening required before any v2 reuse** (verified in the snapshot):
  `fazil:electron/main.js:82` sets `webSecurity: false`; `main.js:95` opens
  DevTools unconditionally in production; `fazil:electron/license.js:7` embeds
  the HMAC secret in plain JS (trivially extractable — treat the license
  system as a deterrent, not protection); the serial generator
  `electron/generate-serial.js` referenced by `fazil:generate-serials.bat` is
  **missing from the branch tree** — regenerate it from the HMAC recipe
  documented in `license.js` if serials are ever needed.
- Aria is already "Electron-ready" (relative publicPath,
  `__ELECTRON_API_URL__` hook, file: CSP) but ships no Electron folder — fazil's
  `electron/` directory is the complete missing piece if v2 goes desktop.
  Electron 31 is an older LTS line; bump Electron/electron-builder at adoption
  time (no fazil code depends on Electron internals beyond
  BrowserWindow/IPC/electron-store basics).

### 7.3 Porting checklist deltas (fazil → v2)

1. Register Aria-styled implementations under the shared aliases (§5.2) —
   templates untouched.
2. Bind POS to a store; add `sale_payments`; join stock into the POS feed
   (§4.3) — the three mandatory correctness fixes.
3. Fix `StockController::opening` cost column (§3.4.1); switch ItemForm to
   `GET /items/{id}`.
4. Re-map permission metas to the v2 matrix; add a POS permission.
5. Merge required i18n keys from fazil dictionaries into v2's en/fa.
6. Reconcile `ActivityLog::log()` signature with v2's (Aria adds a
   `projectId` arg; v2 will define its own final signature).
7. Decide GL adoption: porting Sale/Purchase controllers pulls in
   `LedgerPosting` + observers; either adopt the GL (recommended — gems 1-4)
   or deregister the observers in `AppServiceProvider`.
8. Hold all SPEC-PENDING items (§3.5, §4.3-4.4) for `docs/REQUIREMENTS.md`.
