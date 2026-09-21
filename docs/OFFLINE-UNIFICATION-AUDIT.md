# Afghan China — Offline Unification: Technical Audit

**Date:** 2026-09-21 · **Branch:** `arena/01a0c5b3-afghan-china` · **Base:** `97cee84` (main)

This is the audit required by §60 of the unification brief. It was produced by
inspecting the repository — nothing here is guessed. The implementation that
follows this audit is described in `docs/OFFLINE-MODE.md`.

---

## 1. Current technology stack

| Layer | Technology (verified in repo) |
|---|---|
| Backend | **Laravel 13** (`laravel/framework ^13.8`), PHP `^8.3`, REST API under `/api` |
| Auth | **Laravel Sanctum** (token + session), **spatie/laravel-permission** roles/permissions, PIN terminal (`PinController`, hashed PINs, rate-limited) |
| Frontend | **Quasar 2 (Vue 3) SPA**, hash router, axios, Pinia, vue-i18n (en/fa/pa/zh), built with Vite |
| Package managers | Composer (backend), pnpm (frontend), npm (`offline/`) |
| API conventions | `/api/<resource>`, Sanctum guard, `tenant`/`branch` context middleware, `sync_admin:<perm>` permission middleware |
| Background work | Laravel scheduler (`acsc:backup` daily), no required queue for sync |
| CI | GitHub Actions (`offline-till.yml`, `publish-release-assets.yml`) |
| Offline (current) | **Separate Node.js 22 app** in `offline/` (own HTTP server, own SQLite schema, own vanilla-JS UI, own sync client, Node SEA + 7-Zip SFX Windows installer) |

## 2. Existing Online database

- Default connection is **`sqlite` with WAL** (`config/database.php`: `journal_mode=wal`,
  `busy_timeout=5000`, `synchronous=normal`, `transaction_mode=IMMEDIATE`).
- `.env.example` documents the hosted-production variant: **MySQL** (cPanel-oriented).
  `pgsql` / `sqlsrv` connections are also configured.
- So the Online database is **the existing Laravel default connection — SQLite on the
  LAN server today, MySQL when hosted**. The unification keeps it exactly as is:
  no engine change, no migration of production data (§4 of the brief is satisfied
  by doing nothing to the Online DB).

## 3. Existing schema

49 migrations. Business entities (verified):

- Tenancy/org: `companies`, `company_user`, `branches`, `users` (+roles/permissions via Spatie), `counters`
- Catalog: `product_categories`, `products` (dual pricing, wholesale, expiry, scan codes, images)
- Sales: `sales`, `sale_items`, `sale_payments`, `refunds`, `refund_items`, `customers`
- Cash: `shifts`, `cash_movements`, `counter_end_of_day`
- Inventory: `stock_adjustments`, `stock_transfers` (+items), `inventory_expiry_batches`, `purchases` (+items), `purchase_returns` (+items), `suppliers`
- Finance/HR: `expenses`, `currencies`, `exchange_rates`, `attendance_*`, payroll tables
- Platform: `activity_logs`, `notifications`, `attachments`, `backups`/`backup_logs`, `hardware_devices`
- **Sync (already exists server-side):** `sync_sequences` (global monotonic counter),
  `sync_inbox` (idempotency ledger), `sync_batches` (push/pull/ack audit),
  `sync_conflicts` (persisted conflicts), `pos_devices` (fleet registry + activation codes),
  plus `uuid / revision / sync_seq / origin / device_id / synced_at / captured_at /
  device_invoice_no` identity columns on every synchronizable table
  (`2026_09_19_000003_add_sync_identity_columns.php`).

## 4. Current Offline implementation

`offline/` is a **second, standalone POS application**:

- `src/server.mjs` — own HTTP API on port 7817 (not the Laravel API);
- `src/db.mjs` — own SQLite schema (small subset: users/categories/products/customers/sales/…);
- `src/pos.mjs` — own sales/inventory business logic (a re-implementation, not a reuse);
- `public/` — own vanilla-JS UI (851 lines of `app.js`), not the Quasar frontend;
- `src/sync/` — own sync client speaking the server's `/api/v1/sync/*` protocol;
- `installer/` — Node SEA + 7-Zip SFX builder producing `SoftCora-POS-Setup.exe`.

Honest assessment: the sync *protocol* work is good and the server side of it
(`SyncPusher`, `SyncPuller`, `IdempotencyLedger`, `ConflictResolver`, ten
entity handlers, device auth, conflict center UI) is production-grade. But the
*till itself* violates the brief's core rule.

## 5. Problems with the current Offline architecture

1. **Second application, second dashboard.** The till has its own UI and only a
   subset of features (no wholesale, purchasing, HR, Main Cost, reports, receipt
   designer, …). Every future feature must be built twice.
2. **Duplicated business logic.** Pricing, stock movement, invoice numbering and
   permissions are re-implemented in `pos.mjs`; they will drift from the Laravel rules.
3. **Subset schema.** The till schema covers ~10 entities; the real schema has 40+.
4. **Two auth systems.** Laravel Sanctum centrally, scrypt file locally — different
   users, roles and password policies on each side.
5. **Two installers / runtimes.** PHP+Laravel centrally, Node SEA on the till.
6. **What is worth keeping:** the server sync protocol (`/api/v1/sync/*`, idempotency
   ledger, cursor pull, conflict model) and the Windows-installer *lessons* (ASCII/CRLF
   payload checks, version-pinned runtimes, install-then-verify on a real Windows runner).

**Decision:** keep the server sync engine untouched; **replace the Node till with
Offline Mode of the one Laravel + Quasar application**; keep `offline/` sources in
the repo marked superseded (see `offline/SUPERSEDED.md`) so nothing is lost.

## 6. SQLite architecture (Offline Mode)

- Offline Mode = **the same Laravel codebase** (`OFFLINE_MODE=true`) running on the
  Windows PC with its **own local SQLite file**. Same migrations → identical logical
  schema; same Eloquent models → identical business rules; same Quasar build →
  identical UI. Zero Internet required.
- SQLite pragmas reuse the existing, already-tuned config: WAL, `busy_timeout`,
  `foreign_key_constraints`, `synchronous=normal`, `IMMEDIATE` transactions.
- Location: `%ProgramData%\AfghanChina\data\offline.sqlite` (writable app-data dir,
  never Program Files). The installer creates it; updates never replace it.
- Seed strategy: a fresh Offline install pulls a **baseline snapshot from Central**
  (`offline:seed`, cursor 0 → N) with **server ids preserved**, so foreign keys stay
  valid. Offline-created rows get local autoincrement ids (continuing past the seeded
  max) **plus global UUIDs** (stamped by the existing `SyncServiceProvider`); sync
  identity is the UUID, never the integer.

## 7. Sync architecture

The existing server protocol is reused unchanged; the new code is the **client half**,
implemented as a Laravel module (`App\Services\Offline\*`):

```
OFFLINE PC (same Laravel app, OFFLINE_MODE=true)        CENTRAL (unchanged)
┌──────────────────────────────────────────┐            ┌───────────────────┐
│  Quasar SPA ──▶ local /api ──▶ SQLite    │            │ Laravel + existing│
│        │              │                   │            │ DB (SQLite/MySQL) │
│        │        model observers           │   push     │        ▲          │
│        │              ▼                   │ ──────────▶│  POST /v1/sync/*  │
│        │        offline_outbox ──SyncRunner│ ◀──────────│  (SyncPusher,     │
│        │              ▲                   │    pull    │   SyncPuller,     │
│        └──── Sync Center UI ──────────────┘            │   ledger, handlers)│
└──────────────────────────────────────────┘            └───────────────────┘
```

- **Push:** outbox rows → `POST push` (dependency order, ≤500/batch) → per-change
  results → `synced` / `failed` / `conflict`.
- **Pull:** `GET pull?since_seq=N` loop while `has_more` → transactional apply →
  `POST ack` → cursor advances only after commit.
- **Never** whole-database copy in either direction (§45–46).

## 8. Sync queue / outbox design

New table `offline_outbox` (local only; harmless if migrated centrally):

```
id · change_uuid (unique, = idempotency key) · entity_type · entity_uuid
· operation (create/update/delete) · payload (json) · base_revision
· status (pending/processing/synced/failed/conflict) · attempts
· last_error · server_id · server_uuid · captured_at · processed_at · timestamps
```

- Written by Eloquent observers **in the same request lifecycle** as the business
  write (sale + lines + payments + stock + outbox = one transaction wherever the
  controller already uses one; the observer fires inside it).
- `offline_meta` KV holds `sync.cursor`, `sync.last_*`, device binding.
- Failed rows keep their error and backoff; `Sync Now` retries immediately.

## 9. Sync API design

- **Server (existing, untouched):** `POST /api/v1/sync/register`, `status`,
  `heartbeat`, `push`, `pull`, `ack`, `conflicts`, `token/rotate` — device-token
  auth (`Bearer` + `X-Device-Id`), tenant/branch scoping, per-change results.
- **Local (new, Offline Mode only, Sanctum + `sync-now` permission):**
  `GET /api/offline/status`, `POST /api/offline/sync`, `POST /api/offline/retry`,
  `GET /api/offline/outbox`, `GET /api/offline/conflicts`, `POST /api/offline/backup`,
  `POST /api/offline/restore`. Gated by `offline.mode` middleware (404 when Online).

## 10. Push strategy

- Claim `pending` rows ordered by dependency
  (`customer → product → cash_session → sale → refund → cash_movement →
  stock_movement → expense → counter_end_of_day`), then by id.
- Sale/refund/shift travel as **atomic units** (lines + payments inside the parent
  payload) matching the server handlers' contract.
- Per-change results applied individually: partial success is kept, only the
  remainder is retried. `duplicate` → `synced` (server replayed the ledger).
- `base_revision` accompanies master-data edits for server-side conflict detection.

## 11. Pull strategy

- Incremental cursor pull (`since_seq`), paginated (`has_more` loop), tables limited
  to the server's `pull_tables` + transaction tables the device is allowed to see.
- Apply order is parents-before-children; inserts preserve server ids; updates match
  by `uuid`; soft-deleted rows arrive as tombstones and are soft-deleted locally.
- One transaction per page: apply + validate + advance cursor + commit; on failure
  rollback and cursor stays — resume-safe.
- Rows with a pending local outbox change are **skipped and recorded as local
  conflicts** (in the local `sync_conflicts` table, so the existing Conflict Center
  UI works unchanged); the pending push will surface the authoritative decision.

## 12. Conflict strategy

- Server remains the conflict authority (existing `ConflictResolver` + policies in
  `config/sync.php`: `append_only` for financials, `field_merge` for product/customer,
  `server_wins` for the rest). The Offline agent never auto-resolves financials.
- Pull-time collisions become **local `sync_conflicts` rows** (status `pending`,
  both payloads, differing fields) visible in Sync Center and the existing
  `/sync-conflicts` page; resolution actions reuse the existing resolver options.

## 13. ID strategy

- **UUID = global identity** (already stamped on every syncable row by
  `SyncServiceProvider`; server handlers resolve all relations — `product_uuid`,
  `customer_uuid`, `user_uuid`, `shift_uuid`, `counter_uuid`, `sale_uuid`,
  `sale_item_uuid` — by UUID).
- Integer ids stay local/legacy; inserts from pull preserve server ids so the
  Offline database is FK-compatible with Central from the seed onward.
- `change_uuid` (outbox row UUID) is the idempotency key for every push; the
  server's `sync_inbox` ledger replays retries without re-applying.
- No schema change to existing integer PKs; least-disruptive, as required.

## 14. Inventory synchronization strategy

- Stock is **never** synced as `quantity = N` (the server explicitly rejects that:
  `stock_qty` is in `server_owned_fields`).
- Offline sales decrement local stock through the **same Laravel sale logic** used
  online; the push carries the sale (event), and the server re-applies the movement
  centrally (`SaleHandler::moveStock`) and writes its own `StockAdjustment` audit rows.
- Offline adjustments push as `stock_movement` events; opening stock on a new
  offline product becomes an `opening_stock` movement server-side (existing
  `ProductHandler` behavior). Stock converges because both sides apply the same
  event stream, not because numbers are overwritten.

## 15. Financial transaction synchronization strategy

- Sales, refunds, payments, cash sessions/movements, expenses: **append-only,
  immutable after push**. Server verifies replays by (total, line count, captured
  instant); same-UUID-different-money is a **critical conflict for a human**
  (`raiseFinancial`), never an overwrite — existing, tested server behavior.
- Local updates to a pushed financial row do **not** create `update` outbox rows
  (only `customer`/`product`/`cash_session` updates are captured); post-sync
  corrections flow through business documents (refunds, adjustments), not edits.

## 16. Backup strategy

- `VACUUM INTO` snapshot (SQLite-native, WAL-safe — no raw file copy while the DB
  is live) to the data dir, plus the `device.json` identity alongside; prune to the
  newest N; runnable from Sync Center, CLI (`offline:backup`), and the existing
  daily scheduler.
- Restore validates the SQLite header, purges connections, replaces the file,
  removes `-wal`/`-shm`, re-opens with WAL. Every installer upgrade takes a backup
  **before** migrating.

## 17. Migration strategy

- Offline uses the **same migration chain** as Central (identical schema by
  construction). `offline.sqlite` is created by `migrate --force` at install;
  upgrades run `backup → migrate → doctor-check`.
- The two new tables (`offline_outbox`, `offline_meta`) migrate everywhere
  harmlessly; observers/route/controller are inert unless `OFFLINE_MODE=true`.

## 18. EXE installer strategy

- Target: `Afghan-China-Offline-Setup.exe` built with **Inno Setup** on a Windows
  CI runner (`.github/workflows/offline-installer.yml`), compiled from
  `installer/windows/AfghanChinaOffline.iss`.
- Payload (assembled by `installer/windows/build-payload.ps1`): pinned **PHP 8.3
  embed** zip + required extensions, Composer-installed backend (`--no-dev`),
  Quasar production build served from `public/app` with `config.js` pointing at the
  local API, `.env.offline` template, launcher (`AfghanChina.cmd`), verification
  script. No XAMPP/WAMP/admin DB setup; per-machine data under `%ProgramData%`.
- Install flow: files → data dir + ACLs → `migrate --force` → device id → shortcuts
  (Start Menu + Startup) → launch + health check. Uninstall preserves data unless
  the user opts to delete it. Upgrades reuse the same payload with backup-first.
- The Node-SEA lessons from `offline/installer` (payload lint, pinned runtime,
  install-and-run verification on the Windows runner) are carried over.

## 19. Testing strategy

- **New backend tests** (`tests/Feature/Offline/`): outbox capture on sale CRUD,
  change-payload shape per entity, idempotent pull apply (replay safety), push
  result mapping incl. partial failure, tombstone handling. Server push/pull already
  covered by the protocol's ledger design; retry tests use `Http::fake` sequences
  (drop → success) asserting single application.
- **Scenario matrix** (§52 A–H) documented in `docs/OFFLINE-MODE.md` with exact
  steps; conflict UI covered by existing `/sync-conflicts` + new Sync Center tests.
- **Installer:** payload lint (ASCII/CRLF/quoting) + full install-and-run on the
  Windows CI runner: HTTP health, login, offline sale, seed/sync dry-run, uninstall
  data-preservation check.
- Note: this sandbox has no PHP runtime (Debian mirrors unreachable), so backend
  tests are delivered ready-to-run (`composer test`) and CI-gated rather than
  executed here; every new PHP file follows existing repo patterns and was
  cross-checked against the handler contracts it speaks to.

---

## What was built from this audit

| Deliverable | Location |
|---|---|
| Offline agent (outbox, client, runner, pull applier, backup) | `backend/app/Services/Offline/*`, `backend/app/Models/Offline*.php` |
| Sync Center API + CLI | `backend/app/Http/Controllers/Offline/*`, `backend/app/Console/Commands/Offline*`, `routes/api.php` |
| Sync Center UI (same dashboard, new page) | `frontend/src/pages/system/SyncCenterPage.vue`, route `/sync-center`, System menu |
| Windows installer source | `installer/windows/*`, `.github/workflows/offline-installer.yml` |
| Runbook | `docs/OFFLINE-MODE.md` |
| Legacy till notice | `offline/SUPERSEDED.md` |
