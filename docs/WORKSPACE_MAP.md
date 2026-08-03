# WORKSPACE_MAP — Afghan China Shopping Center v2

> Phase 0 §4.1 deliverable. Maps every repository merged into this workspace,
> its role per `MASTER_PROMPT.md`, and its **actual observed state** on disk.
> Last verified: 2026-07-20.

## 1. Repository → path → role

| Repo (GitHub) | Local path | Role | State observed |
|---|---|---|---|
| `FazilNusrat/afghan-china` | `/home/user/afghan-china` | **THE v2 main project — the only writable codebase** | ⚠️ **Empty repository.** No commits, no remote refs, no files. Everything is built from scratch here on branch `claude/afghan-china-v2-setup-u3v99o`. |
| `BriskCode123/Aria-Herat-Mohandes-Zada-Construction` | `/home/user/Aria-Herat-Mohandes-Zada-Construction` | **Design source of truth** + technology model. Read-only. | ✅ Full content: `backend/` (Laravel), `frontend/` (Quasar 2 + Vue 3), `docs/`, `CLAUDE.md`. Single branch `claude/aria-herat-erp-analysis-5nnh55`. |
| `BriskCode123/afg-china-olddddd` | `/home/user/afg-china-olddddd` | **Legacy behavioral reference** for [BASELINE] features. Read-only. | 🛑 **Empty repository.** Zero refs on the remote, zero files locally (only `.git`). The legacy reference **does not exist in this workspace** — see §4. |
| `BriskCode123/fazil-erp` | `/home/user/fazil-erp` | **Parts bin**; implementation base for Products/Inventory + POS. Read-only. | ⚠️ `main` and the checked-out branch contain only `.gitignore`. **The real content lives on side branches** — see §3. |

### Naming-trap check (MASTER_PROMPT §1)
Confirmed explicitly: the **main project** is `/home/user/afghan-china`
(remote `FazilNusrat/afghan-china`); the **old reference** is
`/home/user/afg-china-olddddd` (remote `BriskCode123/afg-china-olddddd`).
They were distinguished by `git remote -v`, not by folder name.

## 2. Aria — key subfolders (design bible)

| Path | Contents |
|---|---|
| `frontend/src/css/` | The custom stylesheet layer: `app.scss`, `quasar.variables.scss`, `brand.css` — copied verbatim into v2 (see §5). |
| `frontend/src/layouts/` | App shell (sidebar + header). |
| `frontend/src/components/` | Shared components (tables, modals, stat-cards, buttons…). |
| `frontend/src/boot/` | Boot files incl. `globals.js` (global component registration) and i18n. |
| `frontend/src/i18n/{en,fa}/` | Custom `$t('Key')` dictionaries (fa = Farsi, RTL). |
| `frontend/src/{pages,router,stores,composables,services,utils}/` | Feature pages and infrastructure. |
| `backend/` | Laravel API (Sanctum, spatie/laravel-permission, multi-tenant `CompanyScope`). |
| `docs/` | `PROJECT_STATUS_AND_USAGE.md`, `supervisor-module-spec.md`, `POSTGRES_AND_SYNC.md`, tutorials (EN/FA). |

### 2.1 Aria's actual stack (verified against lockfiles, 2026-07-20)

| Layer | Aria (observed) |
|---|---|
| Backend | Laravel **v13.19.0** (constraint `^13.8`), PHP `^8.3`, Sanctum v4.3.2 (token-only auth), spatie/laravel-permission 8.3.0. No FormRequests/API Resources — inline `$request->validate()` + hand-built JSON. |
| Database | `DB_CONNECTION` default **sqlite** (dev; WAL **not** enabled — journal_mode left null), **PostgreSQL 16 in production** per `docs/POSTGRES_AND_SYNC.md`; driver-aware backups (pg_dump / file copy, keep 14, daily 01:00). |
| Frontend | Quasar **2.20.3** (pinned), Vue **3.5.39**, Pinia 3.0.4, Vue Router 5.1 (hash mode), axios 1.18, Dexie 4.4 (offline IndexedDB replica + uuid/revision sync engine), leaflet, jspdf+html2canvas+xlsx (client-side Persian-safe exports). Build: @quasar/app-vite 3.0.0-rc.5 / Vite 7.3.6, pnpm. |
| i18n | **Hand-rolled** `$t('Key')` (not vue-i18n), dicts `src/i18n/{en,fa,pa}` (en/fa 862 lines each; `pa` re-exports en), RTL via `document.documentElement.dir` for fa/pa. |
| Charts | **No chart library** — dashboard "charts" are custom CSS idioms (arc gauges, KPI cards). |
| Packaging | Hand-written PWA (`public/sw.js` + manifest); **Electron-ready** (relative `publicPath`, `window.__ELECTRON_API_URL__`, `file:` CSP) but no Electron mode installed in-repo. |
| Multi-tenancy | `company_id` + `CompanyScope` + `BelongsToCompany` + static `App\Support\Tenant`; parallel branch layer (`X-Branch-Id` header, `SetBranch`, `all-branches` perm); per-project access middleware; email-bound Platform Owner. |
| Audit/money | `ActivityLog::log(action, module, desc, ?projectId)` — 161 call sites; locked-rate-at-entry money trio (`amount`, `rate`, `amount_base`) everywhere; `TreasuryTransaction` ledger. |

Full component/design detail: `docs/DESIGN_SYSTEM.md`. Provenance note: Aria's own
README states its header/table/button components, layout, RBAC, offline sync and
theming were **cloned from fazil-erp** ("three_d shadow design language") — the two
reference systems share one design lineage, which is why their `brand.css` is identical.

### 2.2 Stack reconciliation (MASTER_PROMPT §3) — outcome

MASTER_PROMPT reports the (missing) v2 `CLAUDE.md` as declaring:
*Laravel 11+ / PHP 8.3+ / Sanctum · Quasar 2 / Vue 3 / Pinia · SQLite (WAL)*.

**Verdict: §3 case 2 — Aria's stack matches the declared v2 stack at every
decision level** (Laravel 13 satisfies "11+", PHP 8.3 ✓, Sanctum ✓, Quasar 2 /
Vue 3 / Pinia ✓). No STOP condition. Two caveats surfaced for the Phase 0
report rather than silently resolved:

1. **DB detail:** v2 rulebook says *SQLite (WAL)*; Aria ships SQLite-without-WAL
   in dev and PostgreSQL in production. Recommendation: v2 uses **SQLite +
   enable WAL** per its rulebook (LAN-first single server), while keeping
   Aria's driver-aware patterns so Postgres stays available later. Needs owner
   confirmation only because the rulebook file itself is absent (§4).
2. **Charts:** Aria has no chart library; "clone the charts styling" therefore
   means cloning Aria's custom gauge/KPI CSS idioms, not adopting a chart lib.

## 3. fazil-erp — branch archaeology (parts bin)

The designated/`main` branches are empty; content was located by fetching all refs:

| Branch | Last commit | Files | What it is |
|---|---|---|---|
| `claude/keller-double-entry-gl` | 2026-06-25 | 1,269 | **The authoritative snapshot.** Superset of `kind-keller` (ancestor-verified). Quasar 2 + Vue 3 frontend, Laravel backend, Electron desktop packaging, Chart of Accounts + Users/Roles + **POS multi-tender**, and a `_legacy/` folder (fazil-erp's own prior Laravel ERP — exports/reports). |
| `claude/kind-keller-v59qef` | 2026-06-22 | 1,252 | Older ancestor of the above — ignore. |
| `claude/pharmacy-shop-setup-d79367` | 2026-07-13 | 20 | Small newer pharmacy scaffold (expiry report etc.) — secondary reference. |
| `claude/practical-pascal-y8b97l` | 2026-06-25 | 39 | Early scaffold: multi-tab/multi-order POS foundation. |
| `claude/practical-ritchie-8b4j40` | 2026-06-25 | 54 | Early accounting core scaffold. |

**Rule:** all porting of Products/Inventory + POS starts from
`origin/claude/keller-double-entry-gl`, read via `git show`/`git archive`
(the repo working tree is never switched — reference repos stay untouched).
Note: `_legacy/` inside fazil-erp is fazil-erp's own old ERP. It is **not**
the afghan-china legacy system and must not be confused with
`afg-china-olddddd` / `docs/legacy/`.

## 4. Legacy mapping (MASTER_PROMPT §4.6) — ⛔ BLOCKED

`REQUIREMENTS.md`'s lookup table expects legacy content at paths like
`docs/legacy/js/core/app.js`. Observed reality:

- `afg-china-olddddd` is an **empty repo** (zero refs, zero files).
- No `docs/legacy/` exists anywhere in the workspace.
- `docs/REQUIREMENTS.md` itself is **absent** (the main repo is empty), and so
  is the v2 `CLAUDE.md` the master prompt references. The only CLAUDE.md in
  the workspace is Aria's own (`Aria-Herat-Mohandes-Zada-Construction/CLAUDE.md`),
  which governs Aria, not v2.

**Consequence:** [BASELINE] behavior cannot be legacy-verified until the owner
pushes the old system's code (or supplies it another way). Every [BASELINE]
feature built before then must be flagged `LEGACY-UNVERIFIED` in
`docs/PROGRESS.md`. This deviation is recorded here per §4.6.

## 5. Aria custom CSS — verbatim copy record (MASTER_PROMPT §4.3)

| Source (Aria) | Destination (v2) | Copied |
|---|---|---|
| `frontend/src/css/app.scss` | `frontend/src/css/app.scss` | ✅ 2026-07-20, byte-verified (`cmp`) |
| `frontend/src/css/quasar.variables.scss` | `frontend/src/css/quasar.variables.scss` | ✅ 2026-07-20, byte-verified (`cmp`) |
| `frontend/src/css/brand.css` | `frontend/src/css/brand.css` | ✅ 2026-07-20, byte-verified (`cmp`) |

**`brand.css` provenance (owner instruction, 2026-07-20):** the owner directed
"use the brand.css from fazil-erp". Verified that fazil-erp's
`frontend/src/css/brand.css` (branch `claude/keller-double-entry-gl`), its
`_legacy/frontend/src/assets/brand.css`, and Aria's
`frontend/src/css/brand.css` are all **byte-identical** (854 lines) — one
shared brand layer across the owner's projects. The copied file therefore
satisfies both the owner's instruction and MASTER_PROMPT §4.3 simultaneously.

Extensions go in separate files (e.g. `app.ext.scss`) — the copied files are
never edited (no fork-and-drift). If the inventory in progress surfaces further
style files belonging to the custom layer, they will be appended here.

## 6. fazil-erp reuse notes (MASTER_PROMPT §4.5) — summary

Full catalog and port plans: [`docs/FAZIL_ERP_REUSE.md`](FAZIL_ERP_REUSE.md).
Headline: all porting starts from branch `claude/keller-double-entry-gl`
(read-only via `git show`/`git archive`); the Products/Inventory and POS
modules there are the mandated v2 starting points, to be restyled per Aria
(§ same design lineage) and bent to the v2 functional spec once
`REQUIREMENTS.md` arrives ([BASELINE] rules are SPEC-PENDING until then).

## 7. Related docs

- `docs/DESIGN_SYSTEM.md` — Aria design tokens + component recipes (Phase 0 §4.4).
- `docs/FAZIL_ERP_REUSE.md` — fazil-erp parts-bin catalog + Products/POS port plans (Phase 0 §4.5).
- `docs/PROGRESS.md` — running log incl. source repo of every ported piece.
- `MASTER_PROMPT.md` (workspace instructions) — repo roles and precedence rules.
