# Afghan China Shopping Center — MIS v2

POS-focused retail management system for **Afghan China Shopping Center** (Kabul).
Laravel API + Quasar 2 / Vue 3 SPA. **4 Languages**: English, Farsi (RTL), Pashto, Chinese — 
RTL mode for Farsi with mirrored layout (money stays left-to-right). Base currency
**AFN** (؋), trading in **USD ($)** and **CNY (¥)** with daily locked rates.

---

## Modules

| Area | What it does |
|---|---|
| **Point of Sale** | Barcode scan, multi-tab bills, discounts, multi-tender checkout, 80 mm thermal receipts, kiosk fullscreen (hides the app chrome) |
| **Cash Register** | Drawer shifts opened **on a counter seat** — float, cash in/out, X/Z reports with over/short variance, thermal printing |
| **Counters** | Physical register seats; per-seat and per-worker **Speed Score** (orders/hr, revenue/hr, items/order vs company benchmark → A–D grades), any-date-range analytics |
| **Sales & Customers** | Sales ledger with line-level refunds; customer book with **loyalty badges** (Bronze at 50K AFN → Platinum) and a per-customer dashboard: spend by month, favourite goods, full purchase and item ledgers |
| **Wholesale (B2B)** | Business customers with credit limits/terms, wholesale pricing (retail fallback), negotiated invoices, quotations → conversion, payment recording, receivables dashboard |
| **Catalog** | Products (photos, dual pricing, wholesale price), categories, CODE128 label printing, CSV import **with photo URLs**, per-product dashboard with **five full history ledgers** |
| **Inventory Control** | Warehouse ⇄ shop transfers (scan-driven), **inbound receiving** from a person/company/country, location-aware stock adjustments, full document history |
| **Purchasing** | Purchase orders, receiving into stock, supplier returns, payables |
| **Expenses** | The shop's running costs — rent, power, transport, wages, repairs — kept separate from stock purchases, with a dashboard feed and monthly total |
| **Reports** | Sales/inventory/supplier analytics, one shared **profit by period & person** report (normal cost for managers, Main Price for the owner), daily **Counter Reports** (tender split, drawer variance, hourly curves, leaderboard) |
| **HR & Payroll** | Daily attendance (default-present toggle sheet) feeding monthly payroll runs with automatic absence deductions (basic/30·day), salary book, mark-as-paid |
| **Main Cost** *(VIP)* | The confidential Financial Mirror: real buy price per product (`main ?? operational` resolver applied to every owner-level figure), hidden-margin reports by period and person, private change journal |
| **Platform** | Multi-tenant companies/branches, VIP Control Center (Platform Owner), **Receipt Designer** (live 80 mm preview), backups, activity log, Trash (Super Admin vault) |
| **Dashboard** | Customisable cards: **Sales over time** (day/week/month/year), low-stock alerts, top sellers, recent expenses, and **category share of the store** (by items, value or sales) that drills through to the filtered catalog |

## Roles & demo logins (password: `password`)

| Login | Role | Sees |
|---|---|---|
| `support@briskcodes.com` | **Platform Owner** | Everything + VIP Control Center + Main Cost |
| `vip@afghanchina.af` | **VIP** | Main Cost (view+edit), HR & Payroll, wholesale, reports, receipt layout |
| `admin@afghanchina.af` | Super Admin | Everything except Main Cost; Trash vault |
| `finance@afghanchina.af` | Finance Manager | Finance surface and reports at normal prices |
| `accountant@afghanchina.af` | Accountant | Reports at normal prices |
| `counter1..3@afghanchina.af` | Counter | POS, own shifts, sales, customers — **PIN 1111 / 2222 / 3333** at `/pin` |

## Counter PIN terminal

`/pin` is a full-screen POS-hardware sign-in for the register: the cashier taps their
tile and types a 4–6 digit PIN (physical numpads work too). PINs are **hashed**, the
routes are **rate-limited**, and only active staff who hold a PIN *and* can sell at the
POS are ever listed. Set a PIN in Administration → Users, or close the door entirely
with `POS_PIN_LOGIN=false` in `.env`.

## Run it

```bash
# Backend (Laravel, SQLite WAL)
cd backend
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed        # full demo: products+photos, 14 days of sales, wholesale, roles
php artisan storage:link                # optional — a fallback route serves /storage without it
php artisan serve                       # http://127.0.0.1:8000

# Frontend (Quasar 2)
cd frontend
pnpm install
VITE_API_URL=http://127.0.0.1:8000 quasar dev   # http://localhost:9000
```

If sign-in says **"Cannot reach the server"**, the browser never got an answer from
the API: check that `php artisan serve` is running and that the address in the
message is right. The API accepts the dashboard from `localhost`/`127.0.0.1` on any
port (so Quasar falling back to `:9001` is fine) and from LAN addresses; for any
other address add it to `FRONTEND_URL` in `backend/.env` and run
`php artisan config:clear`.

**Updating an existing machine:** run **`UPDATE.bat`** in the project root — it pulls,
migrates, reseeds permissions/images and clears caches in one click.

## Product photos

All seed photos live in `backend/database/seed-images/` **inside the repo** (named by
barcode) and are attached by `ProductImageSeeder`, which also regenerates anything whose
file is missing on disk. Drop real photos there (`{barcode}.jpg`, `{sku}.png`, `{id}.webp`)
and re-run the seeder.

**Every image ends up under 1 MB**, and the budget is guaranteed rather than attempted:
`src/utils/image.js` shrinks it in the browser *before* the upload (PHP ships a 2 MB
`upload_max_filesize`, so a raw phone photo would otherwise be refused outright), and
`App\Support\ImageOptimizer` re-encodes it again on arrival — a quality ladder at
1280 px, and if that still overshoots the image is shrunk and the ladder runs again.
A 22 MB photo lands as ~450 KB. The product importer's `image_url` column goes through
the same path.

## Health check

`php artisan acsc:doctor` names exactly what is wrong with an install: pending
migrations, missing tables or columns, the storage link, product photos with no file
behind them, whether GD is present, and whether the php.ini upload limits are big
enough — each with the command that fixes it. It is step 6 of `UPDATE.bat`.

## Offline Mode (`.exe` for the shop floor)

The same application runs **offline on a Windows shop PC**: the same Laravel
backend (`OFFLINE_MODE=true`) with its own local SQLite database, the same
Quasar dashboard, and bidirectional sync with Central (outbox + idempotent
push, cursor pull, conflict center). `Afghan-China-Offline-Setup.exe` needs no
WAMP/PHP/Node on the till PC — the installer carries its own runtime.

- [`docs/OFFLINE-MODE.md`](docs/OFFLINE-MODE.md) — install, register, seed, Sync Now, backups, test matrix
- [`docs/OFFLINE-UNIFICATION-AUDIT.md`](docs/OFFLINE-UNIFICATION-AUDIT.md) — the technical audit behind the unification
- [`installer/windows/WINDOWS-INSTALLER.md`](installer/windows/WINDOWS-INSTALLER.md) — building the Setup.exe
- The fleet admin lives at **System → POS Devices / Synchronization /
  Sync Conflicts**; the PC's own agent lives at **System → Sync Center**.
- The first-generation standalone Node till is superseded but kept for
  reference: [`offline/SUPERSEDED.md`](offline/SUPERSEDED.md).

## Documentation

- [`docs/PROGRESS.md`](docs/PROGRESS.md) — full delivery log, feature by feature, with verification notes
- [`docs/DESIGN_SYSTEM.md`](docs/DESIGN_SYSTEM.md) — tokens, shared components, do-not-drift rules
- [`docs/WORKSPACE_MAP.md`](docs/WORKSPACE_MAP.md) — repo roles and provenance
- `MASTER_PROMPT.md` — the owner's original rulebook

Development branch: `main`.
"# afghan-china" 
