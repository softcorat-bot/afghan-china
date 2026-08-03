# MASTER_PROMPT.md — Afghan China Shopping Center v2 (Merged Multi-Repo Workspace)

> **READ THIS FILE FIRST, BEFORE ANYTHING ELSE.**
> All source repositories for this project have been **merged into this single
> workspace** by the owner (Brisk Codes). Nothing here is accidental. Before you
> write, scaffold, or modify a single line of code, you must understand what
> each repository is, what role it plays, and which ONE you are allowed to
> build in. This file is the map. Misidentifying a repo's role is the single
> biggest way to ruin this project.

This file works together with two existing documents and does not replace them:

- `docs/REQUIREMENTS.md` — the full functional spec ([BASELINE] / [ADVANCED] tags)
- `CLAUDE.md` — engineering rulebook, stack, and non-negotiable business invariants

**Precedence when documents overlap:**

| Question type | Winner |
|---|---|
| What does it LOOK like? (UI, layout, CSS, buttons, pages' visual design) | **This file → Aria repo** |
| What does it DO? (features, fields, business rules, formulas) | **REQUIREMENTS.md** (and legacy code for [BASELINE]) |
| HOW is it engineered? (invariants, transactions, authz, testing, phases) | **CLAUDE.md** |
| Anything still conflicting | **STOP and ask the user** — never silently pick |

---

## 1. The repositories in this workspace and their roles

Four codebases are merged here. Identify each one's actual folder in Phase 0
(§4) — do not assume paths.

| Repository | Role | What you may do with it |
|---|---|---|
| **afghan-china** (the v2 main project) | **THE project we are building.** The only deliverable. | The ONLY codebase you create and modify. |
| **Aria-Herat-Mohandes-Zada-Construction** ([repo](https://github.com/BriskCode123/Aria-Herat-Mohandes-Zada-Construction)) | **DESIGN SOURCE OF TRUTH** + technology model. | Read-only. Clone its UI, CSS, and component patterns INTO the main project. |
| **afg-china-olddddd** ([repo](https://github.com/BriskCode123/afg-china-olddddd)) | **Legacy functionality reference** (= the `docs/legacy/` behavioral reference in REQUIREMENTS.md). | Read-only. Match its behavior/fields/flows exactly for [BASELINE] features. Never copy its design or its technology. |
| **fazil-erp** | **Backup library** of ready pages/components/functionality, and the **implementation base for Products/Inventory and POS**. | Read-only. Port/adapt code from it into the main project when specified here or by the user. |

⚠️ **Naming trap:** `afghan-china` (main project — build here) and
`afg-china-olddddd` (old reference — never touch) have confusingly similar
names. Verify which is which in Phase 0 before every early operation.

### 1.1 Aria-Herat-Mohandes-Zada-Construction — the design bible

This is a completed construction-company system whose interface the client
loves. The v2 MIS must look and feel **exactly** like it. Clone the interface
entirely — not "inspired by", an exact clone:

- **App shell:** the sidebar (structure, grouping, icons, active states,
  collapse/expand behavior) and the header/top bar (and everything in it —
  profile menu, logout, any switchers/indicators).
- **Auth screens:** login page (pixel-faithful) and the logout flow/behavior.
- **Dashboard:** the layout, cards/widgets styling, charts styling.
- **Users page, Roles & Permissions page:** clone their UI as the pattern for
  v2's user management and the action-level permission matrix from
  REQUIREMENTS §4.
- **Every button style** — primary/secondary/danger, icon buttons, and
  especially the **progress/loading buttons**. Buttons in v2 must be visually
  identical to Aria's.
- **Custom CSS classes** — including the **3D-effect classes** and any other
  bespoke utility/effect classes. Reuse the SAME class names in v2 so the two
  systems stay 1:1 maintainable.
- **Custom CSS files** — copy them **verbatim** into the main project as the
  base stylesheet layer, then extend (never fork-and-drift; extensions go in
  separate files).
- Tables, forms/inputs, dialogs/modals, toasts/notifications, empty states,
  spacing, colors, typography, shadows, transitions — all per Aria.
- RTL: whatever Aria does for RTL, v2 must do at least as well (v2 requires
  full fa/ps RTL per REQUIREMENTS §14).

**Technologies:** per the client's directive, the main project uses the **same
technologies as the Aria repo**. See §3 for the mandatory reconciliation step
against the stack declared in `CLAUDE.md` — do not scaffold before completing it.

### 1.2 afg-china-olddddd — behavior, not looks

The previous system. Technically bad (no backend, no database, vanilla JS +
localStorage) — its technology is **banned** and must not leak into v2 in any
form. But the client wants **exactly its functionality**: every page's purpose,
every input field, every component's behavior, every workflow, every formula.

- This repo IS the "legacy reference" that REQUIREMENTS.md and CLAUDE.md talk
  about. Use the **legacy behavior lookup table** in REQUIREMENTS.md to find
  the exact file that defines each [BASELINE] rule, and match it including
  edge cases. Legacy CODE wins over legacy docs.
- One-line rule: **olddddd defines WHAT exists and HOW it behaves; Aria
  defines HOW it looks; REQUIREMENTS/CLAUDE define how it's built.**
- The user will additionally provide notes on the old system's data/
  functionality over time — fold those in as they arrive (§7).

### 1.3 fazil-erp — the parts bin

A separate ERP kept in the workspace as a **backup source** of pages,
components, and functionality for reuse whenever it saves work.

- **Mandated use now:** the **Products/Inventory module and the POS module**
  in v2 start from fazil-erp's implementations. Port them into the main
  project, then (a) **restyle completely per Aria** and (b) **bend their
  behavior** to match REQUIREMENTS.md §6–§8 and the legacy [BASELINE] rules
  (shop-inventory deduction rule, multi-tab POS, barcode ×3, etc.). The
  fazil-erp code is a head start, never an excuse to deviate from the spec.
- **Everything else:** only pull from fazil-erp when the user asks, or when
  you find a component there that clearly fits — and say so in your progress
  notes when you do.

---

## 2. The shared foundation — "general functionalities" every module needs

An MIS needs a common layer BEFORE any module is built. Build this once,
cloned from Aria, and compose every single page from it — no ad-hoc styling
anywhere:

1. **AppLayout** — Aria's sidebar + header + content area, permission-aware
   navigation, branch switcher (REQUIREMENTS §3), language switcher (en/fa/ps,
   RTL), theme toggle, profile/logout.
2. **PageShell** — standard page header (title, breadcrumb, primary actions)
   used by every module page.
3. **DataTable wrapper** — server-side pagination/sort/column-search,
   column chooser, export hooks; styled like Aria's tables.
4. **Form kit** — text/number/select/date (dual Gregorian + Afghan Solar
   Hijri where spec'd)/file/barcode inputs, validation display — Aria-styled.
5. **Buttons** — the full Aria button set incl. **ProgressButton**
   (loading state), used app-wide.
6. **Dialogs** — ConfirmDialog (every destructive action), form dialogs,
   the manager-approval/PIN dialog (POS discounts).
7. **Feedback** — toasts/notifications, empty states, skeleton loaders,
   online/offline indicator (POS).
8. **PermissionGate** — hides/disables UI the user lacks (UX only; server
   still enforces per CLAUDE.md invariant #10).

Deliver this foundation inside Phase 1 of REQUIREMENTS §19, immediately after
the Phase 0 discovery below.

---

## 3. Technology reconciliation (mandatory, before scaffolding)

Two directives coexist and you must reconcile them **explicitly, in Phase 0**:

- `CLAUDE.md` declares: Laravel 11+/PHP 8.3+/Sanctum · Quasar 2/Vue 3/Pinia ·
  SQLite (WAL), "do not substitute".
- The client directs: use the **same technologies as the Aria repo**.

Procedure:

1. Inspect the Aria repo locally and determine its actual stack (backend
   framework, frontend framework, CSS approach, build tooling).
2. **If Aria's stack matches CLAUDE.md's** → no conflict; proceed.
3. **If Aria is frontend/template-only** (e.g. Blade views or static
   HTML/CSS with no separate SPA) → adopt Aria's frontend approach and CSS
   wholesale; backend still follows CLAUDE.md. Confirm this interpretation
   with the user in your Phase 0 report before scaffolding.
4. **If Aria's stack genuinely differs from CLAUDE.md's** → STOP. Present a
   short comparison (what differs, impact on REQUIREMENTS features like
   websockets, offline POS, SQLite backups) and ask the user which stack
   wins. When the user decides, update `CLAUDE.md` to match and only then
   scaffold. **Never silently substitute in either direction.**

---

## 4. Phase 0 — mandatory discovery (do this first, produce a report)

Before any application code:

1. **Map the workspace.** List top-level folders, identify each of the four
   repos, and write `docs/WORKSPACE_MAP.md` (repo → path → role → key
   subfolders). Explicitly confirm which folder is the main project and which
   is `olddddd`.
2. **Inventory Aria.** Stack (§3), entry points, layout files, every custom
   CSS file (list them), the custom classes (3D classes etc.), the components
   for sidebar/header/login/dashboard/users/roles/buttons/tables/forms/dialogs.
3. **Copy Aria's custom CSS files verbatim** into the main project's assets
   and record exactly which files were copied and where.
4. **Write `docs/DESIGN_SYSTEM.md`.** Colors, fonts, spacing, shadows, the
   custom class catalog, and a recipe per core component ("to make an
   Aria-style progress button, use classes X Y Z / component at path P").
5. **Inventory fazil-erp's Products/Inventory + POS.** Routes, pages,
   components, state/stores, what is portable vs. what must be rewritten to
   satisfy REQUIREMENTS §6–§8; write the notes into the workspace map.
6. **Verify the legacy mapping.** Confirm `afg-china-olddddd` content is
   reachable at the paths REQUIREMENTS.md's lookup table expects
   (`docs/legacy/js/core/app.js`, etc.); if paths differ, record the correct
   mapping in `docs/WORKSPACE_MAP.md` and use it everywhere.
7. **Run the §3 stack reconciliation** and include the outcome.
8. **Report back** (workspace map + design-system summary + stack decision
   + fazil-erp reuse plan) and get a go-ahead, then start REQUIREMENTS §19
   Phase 1 with the §2 shared foundation and the cloned login page, app
   shell, logout, dashboard skeleton, users page, and roles/permissions page.

---

## 5. Sourcing rule for any feature (decision order)

1. **Look/design** → Aria. Always. Over legacy's look, over fazil-erp's look,
   over any framework default styling.
2. **Behavior, [BASELINE]** → the exact legacy file named in REQUIREMENTS.md's
   lookup table (in `afg-china-olddddd`).
3. **Behavior, [ADVANCED]** → REQUIREMENTS.md only.
4. **Implementation starting point** → fazil-erp for Products/Inventory and
   POS (and elsewhere when it clearly fits); otherwise build fresh in the
   main project on the §2 foundation.
5. **Disagreement about behavior** → REQUIREMENTS.md wins. **About look** →
   Aria wins. **Unresolvable** → ask the user.

---

## 6. Hard rules (restated — all still in force)

- **Never modify** Aria, afg-china-olddddd, fazil-erp, or anything under
  `docs/legacy/`. Reference repos are read-only, forever.
- Every invariant in `CLAUDE.md` applies unchanged: branch-scoped stock;
  POS deducts shop_inventory (never branch stock); wholesale deducts
  warehouse; EOD never touches stock; refunds restore to shop_inventory;
  derived quantities; **sacred payroll formulas**; legacy attendance rules;
  transactional stock mutations + optimistic locking; server-side
  authorization on every endpoint; `cost.view` gating; full audit trail.
- REQUIREMENTS §19's 8 build phases still govern order; a phase is done only
  when its tests are green; acceptance is REQUIREMENTS §18 A + B.
- i18n: port legacy dictionaries verbatim (en/fa/ps, RTL) per REQUIREMENTS
  §14; new keys translated consistently.
- Zero runtime CDN dependencies; everything bundled locally (LAN-first).
- Update `docs/PROGRESS.md` after each feature, including which source repo
  each piece came from (Aria / olddddd / fazil-erp / new).

---

## 7. Incoming inputs from the user (expect these)

The user (Brisk Codes) will progressively provide:

- Full functionality notes of the **old system's database/data model** — to
  copy those functionalities into v2 (v2's own schema still follows
  REQUIREMENTS §15; old `database_schema_v7.sql` stays reference-only).
- Further module-by-module instructions ("what I need in the future").

When new instructions arrive they take precedence over this file **for that
scope**; log every such delta in `docs/PROGRESS.md`. When an instruction is
ambiguous or contradicts an invariant, ask — one precise question — instead
of guessing.

---

## 8. First-actions checklist

- [ ] Read this file, `docs/REQUIREMENTS.md`, `CLAUDE.md` fully
- [ ] Phase 0 §4.1–§4.7 complete; `docs/WORKSPACE_MAP.md` + `docs/DESIGN_SYSTEM.md` written
- [ ] Aria custom CSS copied verbatim into the main project
- [ ] Stack reconciliation (§3) resolved and, if needed, `CLAUDE.md` updated
- [ ] Phase 0 report delivered; go-ahead received
- [ ] REQUIREMENTS §19 Phase 1 started with the §2 shared foundation and the
      Aria-cloned login, shell, dashboard, users, and roles/permissions pages

---

*Maintained by Brisk Codes — support@briskcodes.com*
