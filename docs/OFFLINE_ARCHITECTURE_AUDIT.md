# SoftCora POS — Offline-First Architecture Audit

> Produced by inspecting the actual repository (`backend/`, `frontend/`, `offline/`)
> before implementing anything. Every claim below is verifiable in the tree.
> Date: 2026-09-20 · Branch: `arena/01a0bd66-afghan-china`

---

## 1. Existing architecture summary

### Frontend (`frontend/`)

- **Quasar 2.20.3 + Vue 3.5 + Vite** SPA, Pinia 3 stores (`stores/auth.js`, `stores/ui.js`),
  vue-router 5, axios instance in `boot/axios.js` (`baseURL: ${API_URL}/api`, Sanctum
  bearer token + `X-Branch-Id` header).
- **4 languages** (`src/i18n/{en,fa,pa,zh}`), RTL for Farsi/Pashto.
- Full module surface: POS register, cash register/shifts, counter end-of-day, sales,
  wholesale (B2B), customers, catalog, labels, inventory/transfers/expiry, purchasing,
  expenses, currencies/exchange-rates, reports (5 report pages), HR/payroll,
  users/roles/branches, platform + main-cost (VIP), backups, activity log, trash,
  receipt designer, hardware (attendance) devices.
- Printing today: browser `print-js` + html2canvas/JsBarcode/jsPDF for receipts and labels.
- Local persistence on web: `localStorage` for token/branch only; `dexie` is a declared
  dependency but the SPA is **not** offline-capable (verified: no service-worker cache of
  APIs, no IndexedDB data layer in use).

### Backend (`backend/`)

- **Laravel 13.8, PHP ^8.3**, Sanctum (token auth), spatie/laravel-permission v8 RBAC.
- PostgreSQL in production. Controllers organized by domain
  (`POS`, `Sales`, `Catalog`, `Inventory`, `Wholesale`, `Counter`, `HR`, `Report`,
  `Finance`, `Purchasing`, `Settings`, `Platform`, `Owner`, `Sync`, …).
- Transactional POS logic in services; server is authoritative for the web POS.

### Offline / desktop layer (`offline/`)

- **Pure-Node service (Node ≥ 22.5, zero npm runtime dependencies)** using the built-in
  `node:sqlite` driver: no native build step, no PHP, no WAMP, no external DB.
- Local HTTP API on `127.0.0.1:7817` (`src/server.mjs`) serving an embedded till screen
  (`offline/public/index.html + app.js + styles.css`) — deliberately shell-agnostic
  plain HTML/JS so any browser/kiosk can be the display.
- Business logic `src/pos.mjs` (sales, refunds, products, customers, cash sessions/movements,
  reports) with the same maths as the web POS; every local write enqueues an outbox row
  **in the same SQLite transaction** (`src/queue.mjs`).
- Sync engine `src/sync/{engine,client,apply}.mjs`: push → per-change ACK → cursor pull →
  apply → ack; exponential backoff; idempotent re-send handling; conflict mirroring.
- Offline auth `src/auth.mjs`: scrypt-hashed password/PIN cache, timing-safe compare,
  12-hour sessions; device token stored `0600` in `device.json`.
- Backups `src/backup.mjs`: gzip JSON incl. **unsynced queue, device identity, config**;
  `assertUpgradeSafe()` gate for installers.
- Windows packaging `installer/build-windows.sh`: esbuild bundle → **Node SEA** blob →
  postject into `node-win-x64` → self-test the same blob on Linux → 7-Zip SFX
  **dist/SoftCora-POS-Setup.exe** + portable zip + sha256 + release notes.
  Per-user install (`install.ps1`) into `%LOCALAPPDATA%\SoftCoraPOS`, shortcuts,
  Apps & Features registration, **data dir never overwritten**, uninstall support.

### Sync contract (server side)

- API `POST /api/v1/sync/{register,status,heartbeat,push,pull,ack,conflicts,token/rotate}`
  (`routes/api.php` lines ~388–409; legacy `/api/sync/*` aliases kept).
- `pos_devices` table (device lifecycle: pending → active → disabled/revoked,
  activation codes, tokens, heartbeats), `sync_inbox` (idempotency ledger, unique
  `change_uuid`), `sync_batches` (audit), `sync_conflicts` (both payload versions),
  `sync_seq` global cursor column + identity columns (`uuid`, `revision`, `origin`,
  `device_id`, `synced_at`) on synchronizable tables.
- Admin APIs exist: `GET/POST /api/v1/admin/devices…`, `sync-conflicts…`, `sync/overview`,
  `sync/batches`, `sync/prune` guarded by `sync_admin` middleware + seeded permissions
  (`device-list`, `manage-devices`, `sync-conflict-*`, `resolve-sync-conflicts`,
  `sync-log-list`, `sync-now`, `override-financial-sync-conflicts`).

---

## 2. Existing functionality (verified working)

| Area | State | Evidence |
|---|---|---|
| Web POS (whole MIS) | Present | `frontend/src/pages/**`, `backend/app/**` |
| Offline till: sign-in, catalogue, customers, sales, refunds, stock ledger, drawer sessions, reports, backups | **Working** | `offline/test/e2e.mjs` |
| Offline e2e suite: 20 sales + 5 customers + 3 returns + 10 stock movements + 2 sessions offline; restart; dead server; activation; sync; repeated sync no duplicates; lost response; rejected change; incremental pull; backup/restore with queue; two tills | **16/16 checks pass** | ran `npm test` in `offline/` 2026-09-20 |
| Sync engine: outbox, per-change ACK, idempotency, backoff, conflict center, cursor pull, connectivity probe (server health, not `navigator.onLine`) | Implemented both ends | `offline/src/sync/*`, `backend/app/Services/Sync/*`, `routes/api.php` |
| Sync UI on the till: states Online/Offline/Syncing/Synced/Pending/Failed/Conflict, pending/failed counts, SSE live progress, retry | Present | `offline/public/app.js`, `GET /api/status`, `/api/sync/stream` |
| Device identity: stable generated `device_id`, activation codes, token rotation | Present | `offline/src/ids.mjs`, `DeviceRegistrar` |
| Offline auth: scrypt hashes only, sessions, roles cached | Present | `offline/src/auth.mjs` |
| Crash safety: `begin immediate … commit/rollback` around every multi-row write | Present | `db.mjs transaction()` used by `pos.mjs` |
| Windows installer build pipeline with self-test, per-user install, upgrade-preserves-data, uninstall | Present | `installer/build-windows.sh`, `installer/payload/*` |
| Central documentation of sync engine | Present | `docs/SYNC_ENGINE.md` |

## 3. Current offline capabilities

The till continues fully offline: login (cached, hashed), product/barcode search,
customer create/edit/search, sale create/pay/complete/receipt, line-level refunds,
cash drawer sessions (open, in/out, X/Z), local stock deduction via ledger,
daily/product/customer/payment/cash summary reports, backups, and surviving restart,
crash and connectivity loss. Sync is automatic on a timer + manual, strictly
acknowledged, duplicate-safe, conflict-visible.

## 4. Missing offline capability / gaps (what this audit found)

1. **No web admin UI for the offline fleet.** `Settings → Devices`, the Conflict Center
   and Sync overview exist as APIs + permissions, but **no Quasar page consumes them**:
   `frontend/src/router/routes.js` and `layouts/menus.js` contain nothing for
   `devices`, `sync-conflicts`, `sync overview`. An admin cannot issue an activation
   code, monitor tills, or resolve conflicts without hand-built HTTP calls. **P1.**
2. **No structured file logging in the till.** Logs are `console.*` only
   (`start-till.cmd` redirects stdout to `logs\till.log`). The master spec requires
   separated `application.log / sync.log / error.log / security.log` with redaction. **P2.**
3. **Printing is browser-dependent on the till.** `printReceipt()` = `window.print()`
   of a text block. There is no native/ESC-POS path, no printer configuration, and the
   receipt lacks a thermal-safe 80 mm layout. **P3.**
4. **Documentation set incomplete.** Present: `docs/SYNC_ENGINE.md`, `offline/docs/*`.
   Missing: `OFFLINE_ARCHITECTURE.md`, `OFFLINE_DATABASE.md`, `OFFLINE_SECURITY.md`,
   `OFFLINE_TESTING.md`, `INSTALLATION.md`, `TROUBLESHOOTING.md`, `RELEASE.md` (this
   audit + gap items add them). **P4.**
5. **No PHP runtime in this CI/sandbox** → backend feature tests cannot be executed
   here; the sync contract is instead covered by `offline/test/e2e.mjs` against a
   protocol-conformant central server. Risk noted; backend code paths are unchanged
   by this work. (Mitigation attempted where possible.)
6. Minor: till screen settings have no printer section; `receipt` printing settings
   aren't linked to sync-pulled company receipt settings (out of scope unless required).

## 5. Gap analysis

| Feature | Current status | Offline ready? | Required changes | Risk | Priority |
|---|---|---|---|---|---|
| Sales / payments / receipts offline | Working, tested | ✅ | none | low | P0 ✓ |
| Inventory deduction via ledger | Working | ✅ | none | low | P0 ✓ |
| Customers offline | Working, tested | ✅ | none | low | P0 ✓ |
| Local reports | Working | ✅ | none | low | P0 ✓ |
| Offline auth (hash-only) | Working | ✅ | none | low | P0 ✓ |
| Sync engine + idempotency | Working, tested | ✅ | none | low | P1 ✓ |
| Conflict center server-side | Working | ✅ | none | low | P1 ✓ |
| **Web UI: devices fleet mgmt** | API only | ➖ n/a (server-side gap) | new Quasar page + menu + routes + i18n | medium (was a dead-end for admins) | **P1** |
| **Web UI: sync monitor & conflicts** | API only | ➖ | 2 new Quasar pages + menu + i18n | medium | **P1** |
| **Till file logging** | stdout only | ⚠ partial | `log.mjs` + wiring | low | **P2** |
| **Offline printing** | `window.print()` | ⚠ partial | ESC/POS builder + Windows raw print + settings + thermal CSS; keep browser fallback | medium (no Windows machine here → defensive code + unit tests) | **P3** |
| Windows installer | Working pipeline (7-Zip SFX + SEA) | ✅ (needs verification build) | run clean build, verify artifacts | low | **P4** |
| Docs set | 3/10 present | ⚠ | write 7 docs | low | **P4** |
| Upgrade safety | `verify.ps1` + `assertUpgradeSafe()` | ✅ | none | low | ✓ |
| Backup/restore | JSON.gz incl. queue + identity | ✅ | none | low | ✓ |

## 6. Recommended architecture (minimum changes)

**Keep the architecture that exists — it is the right one.** The web POS stays a pure
online SPA; the desktop deliverable is the hardened till service + embedded screen +
SQLite outbox, packaged as `SoftCora-POS-Setup.exe`. Rewiring the 60-page Quasar SPA
to run offline would violate "do not rewrite working modules" and buy nothing the till
does not already provide. The work that remains is the glue around it:

```
                 ┌──────────────────────┐
                 │ Central Server       │  web POS (admin: NEW Devices / Sync / Conflicts pages)
                 │ Laravel 13 + PG      │  /api/v1/sync/*  (unchanged)
                 └──────────┬───────────┘
                            │ push/pull/ack, cursor, idempotent
                 ┌──────────▼───────────┐
                 │ Desktop POS (.exe)   │  Node SEA service :7817
                 │ SQLite + outbox      │  + file logs (NEW)
                 │ sync engine          │  + ESC/POS native printing (NEW)
                 │ embedded till screen │  (existing, tested)
                 └──────────────────────┘
```

## 7. Exact implementation plan

| # | Item | Priority |
|---|---|---|
| 1 | Frontend: `PosDevicesPage.vue` (fleet list, register+activation code dialog, enable/disable/revoke/reauthorize/delete, detail drawer with batches+conflicts) | P1 |
| 2 | Frontend: `SyncMonitorPage.vue` (overview cards, batch feed, prune) | P1 |
| 3 | Frontend: `SyncConflictsPage.vue` (pending/all, detail diff, resolve dialog) | P1 |
| 4 | Frontend: routes + System menu entries + en/fa/pa/zh keys; `quasar build` verification | P1 |
| 5 | Till: `offline/src/log.mjs` — leveled file logs (application/sync/error/security), size-capped rotation, secret redaction; wire into server/engine/auth | P2 |
| 6 | Till: `offline/src/print.mjs` — ESC/POS receipt builder + Windows raw delivery (shared-printer `copy /b`), settings (`printer_mode`, `printer_share`), `POST /api/print/receipt`, 80 mm print CSS; browser fallback preserved | P3 |
| 7 | Tests: extend `offline/test/e2e.mjs` with logging + ESC/POS checks; keep 16/16 green | P2/P3 |
| 8 | Docs: `OFFLINE_ARCHITECTURE.md`, `OFFLINE_DATABASE.md`, `OFFLINE_SECURITY.md`, `OFFLINE_TESTING.md`, `INSTALLATION.md`, `TROUBLESHOOTING.md`, `RELEASE.md` (root `docs/SYNC_ENGINE.md` already covers #10 of the required set) | P4 |
| 9 | Clean installer build: `installer/build-windows.sh` → verify `dist/SoftCora-POS-Setup.exe`, portable zip, checksums, release notes | P4 |

## 8. Files that will be modified / created

**Frontend (new):** `src/pages/system/PosDevicesPage.vue`, `SyncMonitorPage.vue`,
`SyncConflictsPage.vue`; **edited:** `src/router/routes.js`, `src/layouts/menus.js`,
`src/i18n/{en,fa,pa,zh}/index.js`.
**Offline (new):** `src/log.mjs`, `src/print.mjs`; **edited:** `src/server.mjs`
(logging, print endpoint, printer settings), `src/sync/engine.mjs` + `src/queue.mjs`
(log wiring), `src/auth.mjs` (security log), `offline/public/app.js` (printer settings
UI + native-print button, thermal CSS), `offline/test/e2e.mjs` (new checks).
**Docs (new):** as listed above.
**Backend:** no changes required (APIs already complete). **Build:** none to scripts;
artifacts land in git-ignored `offline/dist/`.

## 9. Risks

- **Native Windows printing cannot be executed in this Linux sandbox** → mitigated by
  a pure ESC/POS builder (unit-tested), a clearly-flagged Windows delivery layer, and
  preserved browser-print fallback; verification on real hardware documented in
  `OFFLINE_TESTING.md`.
- **No PHP here** → backend untouched; contract coverage via e2e stands in.
- Frontend i18n has 4 locales for two humans' worth of keys: new keys are added to all
  four locales at once to avoid mixed-language menus.
- SFX installer antivirus reputation (unsigned) — documented in
  `offline/docs/WINDOWS-INSTALLER.md`; code-signing hook is the documented next step.

## 10. Bottom line

The offline-first core **already exists and is tested end-to-end** (16/16).
This work completes the production picture around it: the missing admin surface,
observability, native printing, documentation, and a verified installer build —
without replacing any working module.

---

## Outcome (updated after implementation, 2026-09-20)

Every planned item landed and is verified:

| # | Item | Result |
|---|---|---|
| 1–3 | Web pages: `PosDevicesPage`, `SyncMonitorPage`, `SyncConflictsPage` | ✅ built, in production bundle (`dist/spa/assets/*PosDevicesPage*…`), oxlint clean |
| 4 | Routes + System menu + 66 keys × 4 locales (en/fa/pa/zh) | ✅ locale files parse, `quasar build` clean |
| 5 | Till file logs (`log.mjs`: application/sync/error/security, 2 MB×3, redaction) | ✅ wired into server/engine/auth; e2e step 16 |
| 6 | Offline printing (`print.mjs`: ESC/POS builder, Windows raw share delivery, browser-dialog fallback, 80 mm layout, printer settings + test receipt, reprint from receipt lookup) | ✅ e2e step 17 (found & fixed a real ESC/POS bug: receipts previously only went to `console.log`) |
| 7 | Tests extended 16 → **18 checks, 18/18 pass** | ✅ |
| 8 | Docs: ARCHITECTURE, DATABASE, SECURITY, TESTING, INSTALLATION, TROUBLESHOOTING, RELEASE | ✅ (SYNC_ENGINE.md pre-existed) |
| 9 | Installer build | ✅ `dist/SoftCora-POS-Setup.exe` (23.1 MiB, PE32+ x64, SFX) + portable zip + sha256 + release notes; packaged blob self-test passed |

Findings corrected during implementation (logged here because the audit flagged
them): till receipts were never actually printed (only `console.log`); log dir
was resolved at import time (now per-write); an argument-spread bug zeroed
ESC/POS command bytes (caught by the new e2e check).

Open items that genuinely require a Windows shop-floor machine (documented in
`OFFLINE_TESTING.md` §2): raw thermal print on real hardware, SmartScreen flow
on a clean PC, power-cut drills, and the gh-release step at ship time.
