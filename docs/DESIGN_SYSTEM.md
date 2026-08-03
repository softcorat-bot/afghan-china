# DESIGN_SYSTEM — Afghan China Shopping Center v2

> Phase 0 §4.4 deliverable (MASTER_PROMPT §4.4). This is the **recipe book** for
> the v2 interface. The v2 UI is an **exact clone** of the Aria Herat ERP
> interface — not "inspired by". Every page, component, and class in v2 is built
> from the recipes below. Verified against the Aria repo on 2026-07-20.
>
> **Path convention:** `aria:` = a path inside the read-only Aria repo
> (`aria:frontend/src/css/brand.css` → `Aria-Herat-Mohandes-Zada-Construction/frontend/src/css/brand.css`).
> `v2:` = a path inside this repo. Line numbers refer to the Aria file as of the
> verification date.

---

## 1. Provenance & ground rules

### 1.1 Design lineage

- The design source of truth is **Aria Herat ERP** (`aria:` repo). Aria's own
  README (`aria:README.md:8,27`) states its shared global header, table and
  button components were ported from **fazil-erp** — the "**three_d shadow
  design language**". Aria and fazil-erp share one design lineage; their
  `brand.css` files are byte-identical (verified by `cmp`, recorded in
  `docs/WORKSPACE_MAP.md` §5 and `docs/PROGRESS.md`).
- So the lineage is: **fazil-erp → Aria → v2**. v2 clones Aria; where Aria and
  fazil-erp differ, **Aria wins** (MASTER_PROMPT §5.1).

### 1.2 The copied verbatim CSS layer

Already done in Phase 0 §4.3 — three files copied **byte-identical** from
`aria:frontend/src/css/` into this repo:

| v2 file | Source | Lines | Role |
|---|---|---|---|
| `frontend/src/css/brand.css` | `aria:frontend/src/css/brand.css` | 854 | Legacy utility layer: `three_d*` shadows, `my_radio*` radii, `btn_*` colors, borders, gradients, widths, avatars, modal widths, global scrollbar |
| `frontend/src/css/app.scss` | `aria:frontend/src/css/app.scss` | 203 | Global SCSS: `afg_sans` @font-face, typography, `[dir="rtl"]` block, `--my-radius` + surface-variable theming, `body.body--dark` dark mode, theme tints, stat-card stagger, mobile trims, `app-toast` |
| `frontend/src/css/quasar.variables.scss` | `aria:frontend/src/css/quasar.variables.scss` | 27 | Quasar brand palette (`$primary` etc.) |

### 1.3 Ground rules (non-negotiable)

1. **Never edit the three copied files.** Extensions and new styles go in
   **separate files** (e.g. `frontend/src/css/v2.scss` added *after* the copied
   files in the `css:` array). Fork-and-drift is forbidden.
2. **Reuse the same class names.** A card in v2 is `my_radio_less three_d_plan`,
   a page shell is `three_d_new q-ma-sm q-pa-sm my_radio_less bg-white`, a modal
   rides `three_d_high` — exactly as in Aria, so the two systems stay 1:1
   maintainable.
3. **No ad-hoc styling.** Every page composes the shared foundation (§4/§5).
   New scoped CSS is allowed only for page-specific content, following Aria's
   conventions (§4.19), and must reuse the token palette (§2).
4. Load order must match Aria: `quasar.config.js → css: ['app.scss', 'brand.css']`
   (`aria:frontend/quasar.config.js:17`), extras
   `['mdi-v7', 'roboto-font', 'material-icons']` (lines 29–31), plugins
   `['Notify', 'Dialog', 'AppFullscreen']` (line 84).
5. Assets that must travel with the CSS: `aria:frontend/src/assets/fonts/afg_sans.woff2`
   (31 KB Farsi webfont — `app.scss` references it as `../assets/fonts/afg_sans.woff2`,
   so the relative path must be preserved) and
   `aria:frontend/src/assets/brand/logo-mark.svg` (v2 gets its own logo mark at the
   same path, same usage pattern).
6. `postcss.config.js` = autoprefixer only. `postcss-rtlcss` is **deliberately
   commented out** (`aria:frontend/postcss.config.js`) — RTL is manual (§7). Do
   not enable it.

---

## 2. Design tokens

### 2.1 Colors

**Quasar brand palette** — `aria:frontend/src/css/quasar.variables.scss:16–26`
("steel-blue primary, construction-gold secondary"; v2 keeps identical values
until the owner supplies a v2 brand):

| Variable | Value | Meaning |
|---|---|---|
| `$primary` | `#175A8C` | Steel blue — buttons, links, field focus, icon chips |
| `$secondary` | `#C8862D` | Construction gold — sidebar accent default |
| `$accent` | `#123A66` | Deep navy — topbar gradient start, hero panels |
| `$dark` / `$dark-page` | `#1d1d1d` / `#121212` | Quasar dark defaults (superseded by surface vars) |
| `$positive` | `#21ba45` | Success |
| `$negative` | `#c10015` | Danger/delete |
| `$info` | `#31ccec` | Info |
| `$warning` | `#f2c037` | Warning |

PWA/meta theme color: `#123A66` (`aria:frontend/index.html:46`).

**Runtime CSS variables** (written by the theme system, read everywhere;
defaults applied at boot by `aria:frontend/src/boot/offline.js:22–38`, changed by
`aria:frontend/src/pages/system/ThemePage.vue:119–149`):

| Variable | Default | Consumers |
|---|---|---|
| `--my-radius` | `8px` (fallback `app.scss:49–51`) | forced onto `.q-card, .q-btn, .q-field__control, .q-dialog__inner > div` (`app.scss:54–59`) |
| `--q-primary` | `#175A8C` (from SCSS) | `.text-theme`, `.bg-theme-soft(er)`, `.border-theme`, `m-header` chip, `tab-title` |
| `--topbar-from` / `--topbar-to` | `#123A66` / `#0B1626` | `.topbar-header` gradient, `n-header` gradient |
| `--sidebar-accent` | `#C8862D` | sidebar active/hover text + border, `n-header` flat border |
| `--sidebar-accent-bg` | `#FBF0DD` | sidebar active/hover bg, brand block gradient |
| root `font-size` | `14px` (small `13px` / large `16px`) | global type scale |

**Surface elevation variables** — `aria:frontend/src/css/app.scss:64–83`. All
dark-mode-aware surfaces use these instead of hard-coded white:

| Variable | Light | Dark (`body.body--dark`) |
|---|---|---|
| `--surface-page` | `#f5f8fe` | `#0e1320` |
| `--surface-card` | `#ffffff` | `#1a2132` |
| `--surface-raised` | `#ffffff` | `#222c42` |
| `--surface-input` | `#ffffff` | `#141b29` |
| `--surface-hover` | `#eef3fb` | `#28344e` |
| `--surface-border` | `#e3e8f0` | `#2c3854` |
| `--on-surface` | `#243244` | `#e6eaf2` |
| `--on-surface-dim` | `#5f7387` | `#95a2b8` |

**De-facto neutral palette** (Tailwind-slate values hard-coded across
components — reuse these exact hexes, never near-misses):

- Text: `#0F172A` (strong), `#1E293B`, `#334155`, `#475569`, `#64748B` (dim)
- Muted: `#94A3B8`, `#8A94A6`
- Borders: `#E7ECF3` (the standard soft card border), `#E2E8F0`, `#F1F5F9`
- Surfaces: `#F8FAFC`, `#F0F4F8` (dashboard/auth page bg), `#f5f8fe` (page container bg)
- Gold accents: `#E9B44C`, `#C8862D`, `#8A5A1E`
- Status: green `#22C55E` / blue `#3B82F6` / red `#EF4444`; amber alert trio `#FEF3C7` bg / `#F59E0B` border / `#92400E` text
- Legacy sky-blue accent: `#3ebaff` (brand.css border family)
- Dark-tile convention (per-component `prefers-color-scheme` blocks): bg `#1E293B`, border `#334155`, strong text `#F1F5F9`

**Per-instance component accents** are passed as inline CSS custom properties,
not classes: `stat-card` sets `--sc-color`/`--sc-tint`
(`aria:frontend/src/components/general/StatCard.vue:2`), dashboard quick actions
set `--qa`/`--qa-tint`. Tints are produced with
`color-mix(in srgb, <color> N%, #fff)` (N = 9–14 typically).

### 2.2 Fonts

| Context | Stack | Where declared |
|---|---|---|
| LTR (default) | `'Poppins', 'Roboto', -apple-system, BlinkMacSystemFont, sans-serif` on `body, .q-table th, .q-item` | `aria:frontend/src/css/app.scss:18–22` |
| RTL (fa/pa) | `'afg_sans', 'Vazirmatn', 'Tahoma', 'Arial', sans-serif` on the same selectors under `[dir="rtl"]` | `app.scss:30–33` |
| Headings/table headers | components repeat `font-family: poppins, sans-serif` inline/scoped | `AppHeader.vue:133`, `ModalHeader.vue:101`, `TabTitle.vue:58`, `DataTable.vue:39`, `MainLayout.vue:193` |

Facts that matter for cloning:

- `afg_sans` is the **only bundled webfont**: `@font-face` at `app.scss:5–11`
  (`woff2`, `font-display: swap`), file `assets/fonts/afg_sans.woff2` — bundled
  locally so it works under Electron `file://` / LAN with no CDN. **v2 must
  bundle it too** (zero-CDN rule, MASTER_PROMPT §6).
- **Poppins and Vazirmatn are referenced but NOT bundled.** Roboto comes from
  the `roboto-font` Quasar extra, so Roboto is the effective Latin font unless
  Poppins exists on the OS. Clone this as-is; if the owner later wants true
  Poppins, bundle it in a *new* file, never by editing app.scss.
- Icons: **Material Icons + MDI v7** (extras in `aria:frontend/quasar.config.js:29–31`).
  Menus and legacy buttons use `mdi-*` names — both sets are required.
- Numeric displays use `font-variant-numeric: tabular-nums`
  (`StatCard.vue:86`, dashboard `.twr__pct`).

### 2.3 Spacing conventions

- Quasar spacing utilities everywhere: `q-pa-sm`, `q-ma-sm`, `q-col-gutter-sm`
  (forms), `q-col-gutter-md` (dashboard rows), `q-gutter-xs` (button rows).
- Page shell = `three_d_new q-ma-sm q-pa-sm my_radio_less bg-white`
  (PageBackground, §4.3); the page's inner row is `row my_radio_less q-pa-sm`
  (some pages use `row q-pa-sm q-col-gutter-sm`).
- Card padding: 12–18px (`.stat-card` `16px 18px 14px`; `.m-header-bar` `8px 12px`;
  `q-pa-md` on option cards). Grid gaps 8–18px.
- Legacy percent margins exist in brand.css (`margin_1`, `margin_2`,
  `margin_left_2` …) — available but avoid in new code; prefer Quasar utilities
  like Aria's new pages do.
- **Mobile trims** (≤599px) live globally at `app.scss:170–188`: page container
  loses horizontal padding; `three_d_new.q-ma-sm` → 4px, `.q-pa-sm` combos → 3–6px;
  `q-card__section` → `10px 12px`; hero bars (`.proj-hero__bar` etc.) → `12px 13px`;
  `.dash-nav`/`.dash-pill` tightened. Note: app.scss deliberately reaches into
  *scoped page class names* here — v2 pages reusing those class names get the
  responsive behavior for free (another reason class names must match).

### 2.4 Border radius

| Scale | Value | Use |
|---|---|---|
| Global token `--my-radius` | 8px (user-selectable 2/8/16px) | forced with `!important` onto q-card/q-btn/q-field/q-dialog (`app.scss:54–59`) |
| `my_radio` | 0.9rem | legacy rounded card |
| `my_radio_high` | 5rem | pill buttons (progress-btn) |
| `my_radio_less` | 0.5rem | **the workhorse** — page shells, tables, cards |
| `my_radio_most_less` | 0.3rem | subtle |
| Modern components | 16px cards (stat-card, db-card, db-map), 14px (toasts, gsearch, hero, quick actions, auth badges), 10–12px (chips, tiles, inputs, m-header 12px), 11px (stat-card icon), 999px/`20px` pills | scoped CSS |

### 2.5 Shadows (the three_d recipes)

Legacy family (brand.css, all global; exact values in §3.1):

| Class | Recipe | Canonical use |
|---|---|---|
| `three_d` | `0 4px 8px rgba(0,0,0,.19), 0 1px 6px rgba(0,0,0,.11) !important` | default card elevation |
| `three_d_high` | `0 16px 90px rgba(49,75,146,.34), 0 16px 40px rgba(45,50,119,.3) !important` | **every modal** (MainModal) — the blue-tinted "floating" look |
| `three_d_new` | `0 14px 18px rgba(0,0,0,.1), 0 11px 80px rgba(0,0,0,.1) !important` | **every page shell & table** (PageBackground, DataTable) |
| `three_d_plan` | `rgba(17,17,26,.05) 0 1px 0, rgba(17,17,26,.1) 0 0 8px` | flat/subtle option cards (ThemePage cards) |
| `three_d_less` | `0 4px 8px rgba(0,0,0,.25), 0 1px 2px rgba(0,0,0,.1) !important` | denser small cards |
| `three_d_inner` | `inset 0px 0 35px 0 #c7c7c7` | inset glow |
| `three_d_new_{red,blue,cyan,green,yellow}` | `0 14px 30px <color .1>, 0 11px 80px <color .34> !important` | colored halo cards |
| `three_d_latest` / `three_d_nice` / `three_d_smooth` | see §3.1 | occasional soft cards |

Modern recipes (scoped, memorize as the house hover language):

- Resting card: `0 1px 2px rgba(15,23,42,.04)` + `1px solid #E7ECF3`
- Hover lift: `transform: translateY(-3px)` + border → accent + shadow
  `0 14px 28px -18px color-mix(in srgb, var(--sc-color) 55%, transparent)`
- Hero panel: `0 10px 26px -12px rgba(18,58,102,.6)`
- Floating pill nav: `0 10px 30px -14px rgba(18,58,102,.35)`
- Toast: `0 12px 34px -10px rgba(15,23,42,.45)`
- Topbar: `0 2px 12px rgba(0,0,0,.2)` + hairline `1px solid rgba(255,255,255,.15)`
- Auth right panel: `-4px 0 40px rgba(0,0,0,.08)`
- Dark-mode replacement for `three_d*`: `rgba(0,0,0,.45) 0 4px 16px !important`
  (`app.scss:95`)

### 2.6 Transitions & animations

| Timing | Use | Source |
|---|---|---|
| `.15s` | sidebar items, theme swatches (micro) | `MainLayout.vue`, `ThemePage.vue:203` |
| `.2s ease` | card lift/border/shadow, icon scale | `StatCard.vue:44` |
| `300ms` / `500ms` + `cubic-bezier(0.4,0,0.2,1)` | Tailwind-ish helper combo `transition duration-500 ease-in-out transform hover:scale-105` on buttons | `app.scss:124–132` |
| `300ms cubic-bezier(0.34, 2, 0.6, 1)` | springy overshoot (`.new_card`) | `brand.css:702` |
| Entrance `0.5s backwards` (`scin`/`qain`: fade + `translateY(14px)`) + **70ms per-sibling stagger** | stat-cards & quick actions | `StatCard.vue:48–50`, `app.scss:161–165` |
| Shine sweep `0.7s ease` (`scshine`/`qashine`: `::after` 40px white strip, `skewX(-20deg)`, `inset-inline-start: -60px → 120%`) | hover on stat-card/quick action | `StatCard.vue:56–63` |
| Icon hover `scale(1.12) rotate(-6deg)` | stat-card/quick-action icons | `StatCard.vue:64` |
| `spin 200ms linear infinite` on `:hover/:focus` | legacy `.spin` | `brand.css:392–414` |
| `spin 1.2s linear infinite` | `.sync-spin` sync indicator | `SyncStatusBar.vue:55–57` |
| Ambient loops: skyline scan 9s, `dashwave` rings 2.2s, live-dot `dblive` 2s, level laser 3s, tower rise 1.4s `cubic-bezier(0.25,0.9,0.3,1)` | dashboard/show pages | `DashboardPage.vue`, `ProjectShow.vue` |
| Dialog transitions `jump-up`/`jump-down` | every m-modal | `MainModal.vue:7–8` |
| `q-slide-transition` | advanced-search panel | `ActionBar.vue:28` |

---

## 3. Custom class catalog

### 3.1 brand.css (global legacy utilities) — complete

All in `v2:frontend/src/css/brand.css` ≡ `aria:frontend/src/css/brand.css`.
Line numbers from the file itself.

**Action-button colors** (color-only, for flat icon q-btns) — lines 2–40:
`.btn_add` `#4cc5cd` · `.btn_back` `#e07c8e` · `.btn_print` `#4a4a4a` ·
`.btn_pdf` `#c8abdb` · `.btn_excel` `#a7c67a` · `.btn_upload` `#1d9882` ·
`.btn_email` `#e06e66` · `.btn_csv` `#0cb163` · `.btn_whatsapp` `#4caf50` ·
`.btn_advance_search` `#5891aa`.

**Table header** — 42–45: `.header_color th` → `color: white !important;
font-size: 14px !important` (legacy colored-header tables).

**3D shadows** — 47–94 (exact values):

| Class | Line | box-shadow |
|---|---|---|
| `.three_d` | 47 | `0 4px 8px rgba(0,0,0,0.19), 0 1px 6px rgba(0,0,0,0.11) !important` |
| `.three_d_inner` | 51 | `inset 0px 0 35px 0 #c7c7c7` |
| `.three_d_high` | 54 | `0 16px 90px rgba(49,75,146,0.34), 0 16px 40px rgba(45,50,119,0.3) !important` |
| `.three_d_plan` | 59 | `rgba(17,17,26,0.05) 0px 1px 0px, rgba(17,17,26,0.1) 0px 0px 8px` |
| `.three_d_less` | 63 | `0 4px 8px rgba(0,0,0,0.25), 0 1px 2px rgba(0,0,0,0.1) !important` |
| `.three_d_new` | 67 | `0 14px 18px rgba(0,0,0,0.1), 0 11px 80px rgba(0,0,0,0.1) !important` |
| `.three_d_new_red` | 70 | `0 14px 30px rgba(255,0,0,0.1), 0 11px 80px rgba(255,0,0,0.34) !important` |
| `.three_d_new_blue` | 73 | `0 14px 30px rgba(0,101,255,0.1), 0 11px 80px rgba(0,114,160,0.34) !important` |
| `.three_d_new_cyan` | 76 | `0 14px 30px rgba(0,255,255,0.1), 0 11px 80px rgba(0,184,181,0.34) !important` |
| `.three_d_new_green` | 79 | `0 14px 30px rgba(0,255,95,0.1), 0 11px 80px rgba(0,255,92,0.34) !important` |
| `.three_d_new_yellow` | 82 | `0 14px 30px rgba(255,102,0,0.1), 0 11px 80px rgba(255,221,0,0.34) !important` |
| `.three_d_latest` | 86 | `0 4px 84px rgb(1 64 139 / 24%)` |
| `.three_d_nice` | 89 | `rgba(149,157,165,0.2) 0px 8px 24px` |
| `.three_d_smooth` | 92 | `rgba(0,0,0,0.1) 0px 10px 50px` |

**Cards** — 96–104, 685–703: `.customCard` (`rgb(90 114 123 / 11%) 0px 7px 30px 0px`,
radius 7px, `background: var(--surface-card)` — dark-aware); `.background_new`
`#f5f8fe`; `.my_card` (`0 4px 10px 1px rgba(99,98,98,0.19)`, radius 10px — used
by the profile menu card); `.item_scale_1:hover` (color `#145EA8`, radius 10px);
`.new_card` (radius 12px, `0 6px 10px -4px rgb(0 0 0/15%)`, white, springy
transition — see §2.6).

**Radius utilities** — 106–144: `.my_radio` 0.9rem · `.my_radio_high` 5rem ·
`.my_radio_less` 0.5rem · `.my_radio_most_less` 0.3rem · per-corner
`.my_radio_less_top_left/_top_right/_bottom_left/_bottom_right` (0.5rem each) ·
`.my_radio_less_right` (0.3rem right corners). All `!important`.

**Shape/layout** — 146–217: `.border_style` (1% side padding,
`var(--surface-card)` bg) · `.my_class` (50% radius) · `.btn-circle` (30×30,
radius 15px; `.btn-lg` 50px, `.btn-xl` 70px) · percent margins
`.my_margin_top_one`, `.margin_bottom_3`, `.margin_left_2`, `.margin_left_4`,
`.margin_1`, `.margin_2`, `.margin_bottom_10px` · `.my_header_h` (1% margin,
white text).

**Icons/typography** — 219–259: `.icon-bgcolor` (white on `#39b3d7`, border
`#269abc`) · `.form-group a i` (FontAwesome 5rem — legacy only) · `.min-container`
(centered, max 650px ≥768px) · `.icon-color-info` `#01b8ff` · `.bg-maroon`
`#dc3545 !important` · `.master-font` 20px.

**Borders** — 261–372: `.my_border_grey` (3px `#bdbdbd`) · `.my_border_dark_grey`
(3px `#383636`) · `.my_date_border` (1px `#afadad`) ·
`.my_border_grey_{right,left,top,bottom}` (1px `#afadad`) · `.my_border_grey_less`
(1px `#bdbdbd`) · `.my_border` (3px `#3ebaff`) · `.my_border_iphone` (4px `#494949`) ·
`.my_border_white` 2px / `_white_less` 1px / `_white_right` / `_white_left` ·
`.my_border_top` (3px `#3ebaff`) / `_top_less` (2px) · `.my_border_less` (2px
`#3ebaff`) · `.my_border_bottom` (3px) / `_bottom2` (2px) · `.my_border_right` /
`.my_border_left` (3px `#3ebaff`) · `.my_border_black_{left,top,bottom,right}`
(3px black; `_black_left` duplicated at 338 & 354 — harmless) ·
`.my_border_cyan_less` (2px `#00bcd4`) · `_teal_less` (2px `#009688`) ·
`_green_less` (2px `#4caf50`) · `_orange_less` (2px `#ff5722`).

**Transparency overlays** — 374–389: `.my_transparent3` rgba(0,0,0,.3) ·
`.my_transparent_white` rgba(255,255,255,.7) · `.my_transparent_zero` ·
`.my_transparent5` rgba(0,0,0,.5) · `.my_transparent_blue` rgba(0,141,210,.8).

**Spin** — 392–414: `.spin:hover`, `.spin:focus` → `@keyframes spin` 0→360deg,
200ms linear infinite.

**Control-panel/timeline widgets** — 421–546 (legacy, keep for parity):
`.doc-card-title` / `.doc-card2-title` folded-corner labels (`#3ebaff` bg,
`:after` triangle `#bebebe`) · `.activity-block` overrides · `.task-list`
vertical timeline (1px `#e1e6f1` line via `:after` at left 30px; `li` 55px
left padding; `.task-icon` absolute 17px dot) · `.my_cascade` (-35px) ·
`.blockquote` (0.15rem `#bdbdbd` left bar) · `.my_pointer` (cursor:pointer) ·
`.my_avatar_cascade` (-100px) · `.slider_fix` (100% height).

**Widths** — 555–599: `.my_width{1,3,5,9,10,20,25,30,40,50,60,70,80,90,100}`
(percent widths).

**Gradients** — 603–623 (all `!important`): `.my_peach_gradient`
`linear-gradient(40deg,#af5a21,#7e0a0a)` · `.my_aqua_gradient` `175deg,#0084ff,#ffffff` ·
`.my_blue_gradient` `40deg,#40c4ff,#1c2a48` · `.my_green_gradient`
`40deg,#bdba2f,#085829` · `.my_red_gradient` `40deg,#23178b,#dd1b1b` ·
`.my_grey_gradient` `40deg,#ffffff,#b4afaf` · `.vision_gradient`
`40deg,#00b5ff,#003772`.

**Modal width system** — 624–641 + responsive 802–839: `.info_modal_width_xs`
25vw · `_sm` 35vw · `_user` 50vw · `_md` 65vw · `_lg` 80vw · `_full` 100vw.
≤650px: all → 100vw. 650–900px: all → 80vw.

**Avatars & status** — 642–684: `.notify_color_negative` `#d4445d` · `.avatar`
(36px circle, `#8760fb`, white 600 text) · `.avatar_status` + `::after` status
dot (14px, default `#99a6b7`, ring `0 0 0 2px rgba(255,255,255,.7)`) ·
`.avatar_status.online::after` `#1bbd29` · `.offline::after` `#44484c`.

**Bootstrap-era form controls** — 708–748: `.my-form-control` (block input, 1px
`#ced4da`, .25rem radius, border/shadow transition .15s) · `.my-form-control-square`
(borderless variant) · `.vs__open-indicator { display:none !important }` ·
`.my-inverted .q-if-inner input` red · `.t_sales` (sticky table header,
`background: var(--surface-card)`).

**Global element tweaks** — 752–854: `input[type=number]` → `-moz-appearance:
textfield`; **custom scrollbar** (785–799): `*::-webkit-scrollbar` 5×5px, track
transparent, thumb `linear-gradient(289deg, rgba(33,87,244,0.87) 0%, rgb(25,199,218) 100%)`
radius 10px — the thin blue-cyan scrollbar is part of the look;
`.q-field--square.q-field--borderless { height: 2.1em }`; combo rule
`.three_d_latest.my_radio_less.from-white.to-green-200 { overflow-x: hidden }`.

### 3.2 app.scss globals

All in `v2:frontend/src/css/app.scss`:

| Block | Lines | What it does |
|---|---|---|
| `afg_sans` @font-face | 5–11 | bundled Farsi font, `font-display: swap` |
| Typography | 18–22 | Poppins/Roboto on `body, .q-table th, .q-item` |
| Page bg | 25–27 | `.q-page-container { background: #f5f8fe }` |
| RTL block | 30–46 | the entire `[dir="rtl"]` override set (§7.3) |
| Radius token | 49–59 | `:root { --my-radius: 8px }` + `!important` application to q-card/q-btn/q-field/q-dialog |
| Surface vars | 64–73 | light values (§2.1) |
| Dark theme | 74–121 | `body.body--dark`: dark surface values; remaps `.bg-white`, `.q-card/.q-menu/.q-table`, **all `three_d*` classes** to `var(--surface-card)` bg + `rgba(0,0,0,.45) 0 4px 16px` shadow; `.bg-grey-1..4` → raised; `.bg-theme-soft(er)` → `rgba(255,255,255,.05)`; table rows/headers/hover; separators; outlined inputs; `text-grey-*`/`text-blue-grey-*` fixups; sidebar family dark overrides incl. brand gradient `linear-gradient(135deg,#1d2840 0%,#161d2e 100%)` |
| Tailwind-ish helpers | 124–132 | `.transition`, `.duration-300`, `.duration-500`, `.ease-in-out` (`cubic-bezier(0.4,0,0.2,1)`), `.transform` (translateZ(0)), `.hover\:scale-105:hover`, `.hover\:translate-x-1:hover`, `.w-full`, `.full-w` |
| Theme tints | 138–157 | `.bg-theme-soft` (`color-mix(in srgb, var(--q-primary) 9%, #fff) !important`, rgba fallback), `.bg-theme-softer` (5%), `.text-theme`, `.border-theme` (30% mix); table-header text rule `.q-table .dt-head-th, .bg-theme-soft th { color: var(--on-surface-dim,#5f7387) !important; font-weight: 700 }` |
| Stat-card stagger | 161–165 | SCSS `@for` 1..8: `.row > *:nth-child(i) .stat-card { animation-delay: (i-1)*70ms }` |
| Mobile trims | 170–188 | §2.3 |
| Toasts | 191–203 | `.app-toast.q-notification`: radius 14px, shadow `0 12px 34px -10px rgba(15,23,42,.45)`, padding 10/14px, weight 600, `backdrop-filter: blur(2px)`; message 13.5px, icon 22px; ≤599px radius 12px + 8px bottom margin |

### 3.3 Component-scoped signature classes (the "modern" layer)

These live in `<style scoped>` blocks of the shared components/pages; when the
component is cloned into v2 its classes come with it. Catalog of the families:

| Family | Component | Purpose |
|---|---|---|
| `.stat-card`, `--dense`, `__accent/__head/__icon/__label/__body/__value/__suffix/__foot` | `StatCard.vue` (§4.4) | KPI tile |
| `.m-header-wrap/-bar/-chip/-chip--primary/-title/-subtitle` | `AppHeader.vue` (§4.2) | page title bar |
| `.n-header-wrap/-bar/-icon-wrap/-title/-subtitle`, `.m-header-glossy` | `ModalHeader.vue` (§4.6) | modal title bar + glass gloss `::after` |
| `.tab-title`, `__chip/__text/__name/__count/__sub` | `TabTitle.vue` (§4.13) | tab-panel section heading |
| `.gsearch`, `__input/__body/__group` | `MainLayout.vue:469–472` | command palette |
| `.topbar-header` | `MainLayout.vue:475–479` | topbar gradient |
| `.sidebar-drawer/-brand/-brand-subtitle/-item(--active/--danger/--vip)/-label/-group-header/-sub-item(--active)/-sublabel/-expand-icon`, `.branch-switcher`, `.branch-option--active`, `.mobile-hide` | `MainLayout.vue:482–631` | shell (§5) |
| `.auth-page/-split/-left/-logo*/-headline(__gold)/-sub/-badges/-badge(__ring/__t/__s)/-circle--1..3/-skyline/-right(__inner/__footer)`, plus unused `.auth-features/-feat*/-stats/-stat*/-biz*` | `AuthLayout.vue` (§6) | login shell |
| `.login-box/-brand(__name/__ver)/-title/-sub/-form/-field-label/-input/-btn/-demo(__title/__btn)/-offline` | `LoginPage.vue` (§6) | login form |
| `.db-page/-hello/-date/-live/-qa-row/-qa(__icon/__txt/__go)/-card(__title)/-map(__frame/__grad/__top/__foot/__expand)/-loc*(--on/__pin/__name/__addr)/-mapfull*/-feed*(__dot--created/--updated/--deleted)/-ent*`, `.sky(__scan/__row/__base)`, `.twr(__pct/__frame/__built(--done)/__glass/__level)` | `DashboardPage.vue:241–394` | dashboard language |
| `.proj-hero__*`, `.dash-nav`, `.dash-pill(--active/__orb/__count)`, `.kpi-tile(__icon/__val/__lbl)`, `.ov-card`, `.phase-head`, `.lift-note`, `.settle-chip`, `.meter-card` | `ProjectShow.vue:2010+` | entity-show hero + frosted sticky pill nav (`dashwave` radiating rings). Same pattern per page as `sc-hero__*`, `emp-hero__*`, `ox-hero__*`, `hx-hero__*` |
| `.attach-box__empty/__count`, `.attach-grid`, `.attach-cell(__ph/__cap/__del)` | `AttachmentBox.vue` (§4.15) | upload grid |
| `.avatar-up(__ph/__edit)` | `AvatarUpload.vue` (§4.16) | avatar upload |
| `.theme-swatch` | `ThemePage.vue:199–207` | 36px preset circles, hover `scale(1.12)` |
| `.sync-spin` | `SyncStatusBar.vue:55` | spinning sync icon |
| `.proj-map`, `.proj-pin`, `.pm-pop/-sub/-links` | `ProjectMap.vue:144–150` | Leaflet skin |
| `.shamsi-picker` | `ShamsiDatePicker.vue:169` | radius-12 calendar popup |
| `.select-add-pop` | `SelectAdd.vue:36` | quick-add popup |
| `.user-save-banner`, `.role-save-banner`, `.role-link`, `.perm-header` | `UserForm.vue`, `RoleForm.vue` | sticky save banners, permission-matrix header |

Naming convention for any **new** v2 page class: short page prefix + BEM-ish
elements (`pos-`, `inv-` …), plain CSS in `<style scoped>`, colors from §2.1,
and a small `@media (prefers-color-scheme: dark)` appendix using the
`#1E293B/#334155/#F1F5F9` dark-tile convention.

---

## 4. Component recipe book

Every shared component is **registered globally** with a short kebab alias in
`aria:frontend/src/boot/globals.js:74–93`. v2 reproduces this boot file (same
aliases, same helpers). Global helpers from the same file:

| Global | Source lines | What it is |
|---|---|---|
| `$fmtDate` / `$fmtDateTime` | globals.js:42–43 → `utils/date.js` | `'2026-06-20…'` → `"20 Jun 2026"` (en-GB, hard-coded); `'—'` for empty |
| `$axios` / `$api` | 46–47 | the axios instance |
| `$can(permission)` | 50–53 | permission gate; empty permission ⇒ `true`; delegates to auth store (§4.18) |
| `$delete(url, onDone)` | 56–72 | THE destructive confirm (§4.10) |

Alias map (all → `aria:frontend/src/components/...`):

| Alias | File | | Alias | File |
|---|---|---|---|---|
| `m-header` | `Headers/AppHeader.vue` | | `n-select-add` | `fields/SelectAdd.vue` |
| `progress-btn` | `Buttons/ProgressButton.vue` | | `export-btn` | `general/ExportBtn.vue` |
| `m-backgrounds` | `general/PageBackground.vue` | | `action-bar` | `general/ActionBar.vue` |
| `m-modal` | `general/MainModal.vue` | | `stat-card` | `general/StatCard.vue` |
| `n-header` | `general/ModalHeader.vue` | | `shamsi-date` | `general/ShamsiDatePicker.vue` |
| `n-table` | `tables/DataTable.vue` | | `shortcuts-panel` | `general/ShortcutsPanel.vue` |
| `n-name` | `fields/NameField.vue` | | `tab-title` | `TabTitle.vue` |
| `n-simple` | `fields/NameSimple.vue` | | `attach-box` | `AttachmentBox.vue` |
| `n-submit` | `fields/SubmitButtons.vue` | | `avatar-box` | `AvatarUpload.vue` |
| | | | `project-map` | `ProjectMap.vue` |

Plus `sync-status` (`general/SyncStatusBar.vue`) registered in
`aria:frontend/src/boot/offline.js:13`, and non-global shared
`general/BrandMark.vue` (imported locally where needed).

### 4.1 `n-table` — the data table

**Aria file:** `aria:frontend/src/components/tables/DataTable.vue` (q-table wrapper).

- **Props** (array-style, lines 187–191): `loading, data, pagination, filter,
  columns, rowKey ('id'), infoIcon, noInfoDialog, title, noEdit, noInfo,
  noDelete, can_edit, can_show, can_delete, can_print, visibleColumns, yesPrint`.
- **Emits** (192): `del, edit, info, print, head, request, update:filter,
  update:pagination`.
- **Slots:** `no-data` override, `vissibleCols` (sic — keep the typo for 1:1
  API compatibility), and **full q-table slot passthrough** via the dynamic
  forwarder (145–147) — pages use `#body-cell-<name>` heavily.
- **Look:** root classes `q-ma-sm my_radio_less three_d_new` (line 21); header
  row `bg-theme-soft` with `q-th.dt-head-th` and inline `font-family: poppins,
  sans-serif` (36–49); `dt-head-th` color/weight from `app.scss:150–154`;
  loading bar `color="negative"`; `title-class="desktop-only"`.
- **Behavior:** client-side. Internal pagination default
  `{ sortBy:'created_at', descending:true, page:1, rowsPerPage:10 }` (194–199),
  `rows-per-page-options=[5,10,20,50,100]`, `binary-state-sort`. `request` is
  emitted (227–230) but **no Aria page uses server mode** — pages load full
  lists and pass `:data` + `v-model:filter` (the built-in Quasar client
  filter; search box lives in the `top-right` slot: `q-input standout="bg-primary"
  dense debounce="300"` with hover-scale helper classes, 129–142).
  *(v2 note: REQUIREMENTS demands server-side pagination — implement it by
  **using** the existing `request`/`pagination` API of this same component, not
  by restyling or replacing it.)*
- **Column visibility:** `view_column` button + checkbox q-menu (108–118);
  `actions` and `created_at` always visible (254–261). Desktop only.
- **Extras:** fullscreen toggle; dense toggle labeled `$t('MSizer')` (default
  dense **on**); auto date formatting — any column passing `isDateColumn()`
  gets `format: fmtDate` (264–272); `created_at` is **excluded** because pages
  use it as the row-number column — built-in `#body-cell-created_at` renders
  `props.rowIndex + 1` (51–55).
- **Row actions** (57–99): dense `size="sm"` square buttons, all `class="q-ml-xs"`,
  permission-gated with `$can`: edit `color="blue-8" icon="edit"` (`$can(can_edit)`),
  info `color="teal-7"` icon `info`/`infoIcon` (`$can(can_show)`), print
  `color="deep-orange" icon="mdi-printer"` (shown only when `yesPrint`,
  `$can(can_print)`), delete `color="negative" icon="delete"` (`$can(can_delete)`).
- **Built-in row info modal** (150–178): unless `noInfoDialog`, the info button
  opens an `m-modal` (600px) with `n-header icon="info"`, a 4px gradient accent
  bar `linear-gradient(90deg,#0097a7,#0288d1,#5c6bc0)`, 2-column label/value
  tiles (label strip `#f0f9ff`, uppercase 10px `#0097a7`; value on
  `var(--surface-card)`), and a Close `q-btn unelevated color="blue-grey-7"`.
- **Empty/loading:** `no-data` default = `q-icon name="search" size="6em"
  color="primary"` + `$t('NoRecordFound')` centered `q-py-xl` (23–30); loading =
  `q-inner-loading showing color="primary"` (32–34); also
  `:no-data-label="$t('NoRecordFound')"` / `:no-results-label="$t('FilterNoResult')"`.
- In-component `exportCSV/exportExcel/exportPDF/copyToClipboardData` helpers
  (275–326) exist but are **not wired to UI** — real exports go through
  `export-btn` (§4.9).

**To reproduce in v2:** clone the file verbatim (only the import paths differ),
register as `n-table`. Page usage pattern:

```vue
<n-table :loading="loading" :data="rows" :columns="columns" v-model:filter="filter"
  can_edit="product-edit" can_delete="product-delete" can_show="product-list"
  @edit="openEdit" @del="remove" @info="openShow">
  <template #body-cell-status="p"><q-td :props="p"><q-chip dense size="sm" color="positive" text-color="white">…</q-chip></q-td></template>
</n-table>
```

Column conventions: first column `{ name:'created_at', label:'#', field:'id' }`
(renders row index); last `{ name:'actions', label:'Actions', field:'actions',
align:'right' }`; `label` values are **i18n keys** (header renders `$t(col.label)`).
Delete handlers call `proxy.$delete('resource/' + id, load)`.

### 4.2 `m-header` — page title bar

**Aria file:** `aria:frontend/src/components/Headers/AppHeader.vue`.

- **Props** (typed, 67–95): `icon, to, back, iconTooltip, subtitle, badge,
  badgeColor, badgeTextColor, count, countColor, countTextColor, bg (legacy),
  glossy (default true, inert on the modern look), flat, dark, to2, icon2,
  label2, btn2Tooltip, btnTextColor, buttonControlSize, controlRoomButton,
  glossyBtn, flatBtn, outlineBtn, showRefresh`.
  **Emits:** `click` (back), `click2` (control button), `refresh`.
  **Slots:** default (title), `search`, `actions`.
- **Look** (110–143): `.m-header-bar` — `var(--surface-card,#fff)` bg, `1px solid
  var(--border-soft,#E7ECF3)`, radius 12px, padding 8/12, shadow
  `0 1px 3px rgba(15,23,42,.04)`. `.m-header-chip` 34px radius-10 icon chip;
  `--primary` variant `color-mix(in srgb, var(--q-primary) 12%, #fff)` bg +
  primary icon; passing `bg="bg-*"` tints the chip with that Quasar class
  instead (computed line 107). `.m-header-title` 16px/700 poppins
  `var(--on-surface,#0F172A)`; `.m-header-subtitle` 11.5px dim. Optional back
  arrow (`back`/`to` → `goBack()` 100–104: emit `click`, then `router.push(to)`
  or `router.back()`), badge `q-badge`, count `q-chip` (18px high), refresh
  button, and a right-side round outline primary settings button shown unless
  `controlRoomButton` is truthy. Dark fallback 140–142 (`#1E293B`/`#334155`).
- **Ubiquitous usage:** `<m-header icon="groups" controlRoomButton="false"
  class="q-mt-xs">{{ $t('Employees') }}</m-header>` — note Aria passes the
  string `"false"` (truthy) to hide the control button; keep this quirk for
  template-level compatibility.

### 4.3 `m-backgrounds` — the page shell card

**Aria file:** `aria:frontend/src/components/general/PageBackground.vue` — the
whole component is:

```vue
<div class="three_d_new q-ma-sm q-pa-sm my_radio_less bg-white"><slot></slot></div>
```

This white floating card on the `#f5f8fe` page container **is** the Aria page
look. Every v2 page template is:

```vue
<q-page><m-backgrounds>
  <div class="row my_radio_less q-pa-sm">
    <m-header icon="..." controlRoomButton="false" class="q-mt-xs">{{ $t('Title') }}</m-header>
    <action-bar ... />
    <n-table ... />
  </div>
</m-backgrounds></q-page>
```

`bg-white` is remapped to `var(--surface-card)` in dark mode by `app.scss:86–92`
— never replace it with a hard-coded color.

### 4.4 `stat-card` — KPI tile

**Aria file:** `aria:frontend/src/components/general/StatCard.vue`.

- **Props** (typed, 21–31; no emits/slots): `icon` (req), `label` (req),
  `value` (req, String|Number), `suffix` (''), `sub` (''), `subIcon` (''),
  `color` (`#175A8C`), `tint` (`#E0EDF7`), `dense` (false).
- **Structure:** root `.stat-card` with inline `--sc-color`/`--sc-tint`;
  `__accent` (absolute 4px bar, `inset-inline-start: 0; top/bottom: 14px`,
  gradient `var(--sc-color)` → `color-mix(in srgb, var(--sc-color) 40%, #fff)`);
  `__head` = 38px radius-11 tinted icon chip + uppercase 11px/700 `#8A94A6`
  label (letter-spacing .06em); `__value` 24px/800 `#0F172A`, `-0.6px`,
  `tabular-nums`; `__suffix` 13px/700 `#94A3B8`; `__foot` 11.5px/600 in
  `var(--sc-color)` with optional 13px icon.
- **Chrome & motion:** white, `1px solid #E7ECF3`, radius 16px, padding
  `16px 18px 14px`, `height: 100%`, resting shadow `0 1px 2px rgba(15,23,42,.04)`;
  entrance `animation: scin 0.5s backwards` + the global 70ms stagger
  (`app.scss:161–165`); hover = `-3px` lift, border → `--sc-color`, glow
  `0 14px 28px -18px color-mix(in srgb, var(--sc-color) 55%, transparent)`,
  icon `scale(1.12) rotate(-6deg)`, shine sweep `scshine 0.7s`.
- **Dense variant** (`--dense`, 96–112): single-row flex, icon 26px, padding
  `7px 12px`, radius 11px, value 15.5px right-aligned, hover effects disabled —
  for modals/tab headers.
- **Dark:** `prefers-color-scheme` → bg `#1E293B`, border `#334155`, value `#F1F5F9`.
- **Recipe:** 3–4 per `row q-col-gutter-sm`, distinct color/tint pairs.
  Money values come pre-shaped from `useCurrency().smartMoney()` (§4.20):

```vue
<div class="col-6 col-md-3">
  <stat-card icon="payments" :label="$t('Treasury')" v-bind="smartMoney(total, byCur)"
    color="#16A34A" tint="#DCFCE7" />
</div>
```

House color/tint pairs seen in Aria: `#175A8C/#E0EDF7` (blue), `#16A34A/#DCFCE7`
(green), `#7C3AED/#EDE9FE` (violet), `#D97706/#FEF3C7` (amber),
`#0D9488/#CCFBF1` (teal).

### 4.5 `m-modal` — dialog shell

**Aria file:** `aria:frontend/src/components/general/MainModal.vue` (20 lines).

- **Props** (array-style): `showCM` (visibility), `position` (default
  `'standard'`), `card_style` (string, default `'width: 500px'` — **the** size
  mechanism; observed sizes 440/500/560/600/640/700px). **Emits:**
  `update:showCM`. **Slot:** default.
- It is a `q-dialog` that is **`persistent` AND `seamless`**, with
  `transition-show="jump-up" transition-hide="jump-down"`, wrapping a plain
  `div` whose style is `card_style + ';max-width: 90vw; max-height: 90vh;
  display: flex; flex-direction: column;'` and whose class is **`three_d_high`**
  — the giant blue-tinted shadow (brand.css:54) is what makes Aria modals float.
- **Standard CRUD modal skeleton** (canonical example
  `aria:frontend/src/pages/hr/DepartmentsPage.vue:47–62`):

```vue
<m-modal :showCM="dialog" @update:showCM="dialog = $event" card_style="width: 440px">
  <q-card class="bg-white">
    <n-header icon="apartment">{{ form.id ? $t('Edit') : $t('AddNew') }} — {{ $t('Department') }}</n-header>
    <q-separator />
    <q-form @submit="save">
      <q-card-section class="row q-col-gutter-sm"> …fields… </q-card-section>
      <q-separator />
      <n-submit :submitting="saving" :label="$t('Save')" />
    </q-form>
  </q-card>
</m-modal>
```

Read-only detail modals end with `<q-card-actions align="right"><q-btn flat
:label="$t('Close')" color="grey-7" @click="dialog=false" /></q-card-actions>`.

### 4.6 `n-header` — modal title bar

**Aria file:** `aria:frontend/src/components/general/ModalHeader.vue`.

- **Props** (typed, 45–56): `icon` (renders `icon || 'info'`), `iconSize`
  (default 20px), `subtitle`, `badge`, `badgeColor` (default white),
  `badgeTextColor` (default primary), `bg` (Quasar bg class override),
  `glossy` (adds `.m-header-glossy`), `flat` (white bg + `2px solid
  var(--sidebar-accent, #00acc1)` bottom border; icon chip
  `var(--sidebar-accent-bg, #e0f7fa)`), `accent` (reserved). **Slots:** default
  (title), `actions`. Includes built-in white round `close` button with
  `v-close-popup` + tooltip.
- **Default look** (58–74, 77–109): gradient bar
  `linear-gradient(135deg, var(--topbar-from, #0097a7) 0%, var(--topbar-to, #006064) 100%)`
  — i.e. it follows the theme's topbar colors, teal fallback — min-height 48px,
  radius 0, padding 8/12; icon inside a `rgba(255,255,255,.18)` radius-8 chip;
  title white 14px/700 poppins; subtitle `rgba(255,255,255,.75)` 11px.
  `.m-header-glossy::after` = glass gloss overlay
  `linear-gradient(to bottom, rgba(255,255,255,.22), rgba(255,255,255,0) 50%, rgba(0,0,0,.08) 51%, rgba(0,0,0,.03))`.

### 4.7 Buttons

**`n-submit` — form submit row.** `aria:frontend/src/components/fields/SubmitButtons.vue`.
Props: `submitting, type ('submit'), label, disable`. Renders a right-aligned
`q-card-actions.full-width.q-px-md.q-py-sm` with:
(a) Reset — `q-btn type="reset" unelevated text-color="grey-8" icon="refresh"`
class `transition duration-500 ease-in-out transform hover:scale-105 bg-grey-3
my_radio_less q-px-md`;
(b) Save — `q-btn unelevated color="primary" icon="save" :loading="submitting"`
class `transition duration-500 ease-in-out transform hover:scale-105
my_radio_less q-ml-sm q-px-md`, with **loading slot `<q-spinner-facebook />`**
— the signature Aria "saving" spinner. Always the last child of `q-form`, after
a `q-separator`.

**`progress-btn` — THE progress/loading pill button.**
`aria:frontend/src/components/Buttons/ProgressButton.vue`. Props (array-style):
`name` (knob value 0–100; `null` shows 67), `label_class`, `indeterminate`
(spinning ring while busy), `icon` ('home'), `to`, `size` ('29px'), `thickness`
(0.2), `color`. Emits `click`. Slot: default = label. **Implementation:** TWO
q-btns —
1. desktop: `q-btn dense flat :to :color` class `desktop-only my_radio_high
   transition duration-500 ease-in-out transform hover:scale-105` containing a
   `q-knob show-value :indeterminate :model-value="name ?? 67" class="shadow-9"
   style="border-radius: 5rem !important" track-color="grey-4"` with the icon
   *inside* the knob (`<q-icon :name="icon" size="xs" />`) and a
   `text-weight-bolder` label to the right;
2. mobile: same q-btn but `mobile-only` and icon-only.

Loading behavior: the caller flips `:indeterminate` true during async work and
the ring spins around the icon (see `export-btn`). Standard toolbar colorway:
Add `color="teal" icon="add"` · Import `pink-6` `mdi-database-import` · Advance
Search `blue-grey-9` `mdi-tune` · PDF `red-7` `mdi-file-pdf-box` · Excel
`green-8` `mdi-microsoft-excel`.
Recipe: `<progress-btn color="teal" icon="add" @click="openCreate">{{ $t('AddNew') }}</progress-btn>`.

**Other button conventions:**
- Table row icon buttons: dense `size="sm"` square, colors per §4.1.
- Secondary/cancel: `q-btn flat color="grey-7" :label="$t('Cancel')"`.
- Danger: `color="negative"`; confirm-dialog OK is
  `{ label: 'Delete', color: 'negative', unelevated: true }`.
- Legacy color-tint classes `.btn_add/.btn_back/...` (§3.1) for flat icon buttons.
- Primary CTA gradient (auth): `linear-gradient(135deg, #123A66, #175A8C)` (§6).

### 4.8 Form inputs

**`n-name` — required text input.** `aria:frontend/src/components/fields/NameField.vue`.
Props: `name` (value — bind `:name` + `@update:name`), `label, refName,
autofocus, icon ('account_circle'), bgColor, inputClass ('q-mt-sm
text-capitalize'), type ('text'), color ('primary'), readonly, disable, rules`.
Emits `update:name, enter, blur`. A `q-input outlined dense hide-bottom-space`
with primary prepend icon, select-all-on-focus (`@focus="$event.target.select()"`),
and **default required rule** `[val => !!val || $t('FieldIsRequired')]` (line 20).
Pass `:rules="[]"` to make optional.

**`n-simple` — validation-agnostic input.** `aria:frontend/src/components/fields/NameSimple.vue`.
Same look; default icon `info`; `step="any"` for numbers; extra props
`error, errorMessage` for **server-side error display**; emits `update:name,
input, keyup, onenter, blur, change`.

**House style for raw fields:** any plain Quasar field is `outlined dense
color="primary"` (+ `emit-value map-options clearable` on q-select), usually
with a `#prepend` icon. Validation display: fields are `dense hide-bottom-space`
with inline `:rules`; API errors surface as a negative toast
(`e?.response?.data?.message || 'Save failed'`), not per-field, except where
`n-simple`'s error props are used.

**`n-select-add` — select with inline quick-add.**
`aria:frontend/src/components/fields/SelectAdd.vue`. Props (typed, 69–80):
`modelValue` (v-model), `endpoint` (req, e.g. `'/departments'`), `label`,
`icon ('list')`, `color ('primary')`, `optionLabel ('name')`, `params`
(GET query), `createPayload` (fixed POST fields), `fields` (quick-add form
spec; default `[{ key:'name', labelKey:'Name', icon:'label' }]`). Emits
`update:modelValue, created, loaded`. Self-loads options; filterable
(`use-input input-debounce="0"`); `#no-option` shows `$t('NoRecordFound')`;
`#after` round `add` button opens an anchored `q-menu` (class
`select-add-pop`, min-width 280px) with mini inputs + Cancel/Save (Save
`unelevated dense icon="save" :loading="saving"`); on create POSTs
`{...createPayload, ...draft}`, reloads, auto-selects the new id, toasts
positive `Added` with `cloud_done`.

**Date — Gregorian (two patterns, no date library anywhere):**
1. Plain `q-input outlined dense type="date"` (ActionBar advanced search).
2. Readonly `q-input` + `#append` `q-icon name="event"` + `q-popup-proxy cover`
   + `q-date mask="YYYY-MM-DD"` + flat Close (canonical:
   `aria:frontend/src/pages/projects/ProjectForm.vue:71–79`).
Dates are plain `YYYY-MM-DD` strings; display via `$fmtDate` → `"20 Jun 2026"`.

**Date — Jalali/Shamsi — `shamsi-date`.**
`aria:frontend/src/components/general/ShamsiDatePicker.vue` (171 lines,
self-contained, zero-dependency). Props: `modelValue` (Gregorian `YYYY-MM-DD`
string — **the model stays Gregorian ALWAYS**), `label ('Date')`,
`color ('primary')`; emits `update:modelValue`; `v-bind="$attrs"` passes extra
q-input attrs through. Hand-rolled converters `toShamsi()` (51–82) /
`shamsiToGregorian()` (84–95) using Julian-day arithmetic (epoch offset
1948320, year offset 1348); Persian months `فروردین…اسفند` (97), RTL day
initials `ش ی د س چ پ ج` (98), month lengths 31/30/29. UI: `q-input` with
`swap_horiz` toggle (Shamsi ⇄ Gregorian); Shamsi mode is readonly with a
`q-popup-proxy` month-grid calendar (`.shamsi-picker`, white, `shadow-5`,
radius 12px; selected day `bg-primary text-white`, today `text-primary
text-weight-bold`, Gregorian equivalent shown at the bottom). Default mode
reads `localStorage.getItem('calendar_type') === 'fa'` (48). Registered
globally; Aria pages mostly still use the q-date pattern — **v2 uses
`shamsi-date` wherever the spec demands dual calendars**, keeping the
Gregorian-model rule.

**`attach-box` — file/receipt/photo upload grid.**
`aria:frontend/src/components/AttachmentBox.vue`. Props (61–70): `type` (req,
whitelist alias), `id`, `kind` (`'file'|'receipt'|'photo'|'avatar'`), `label`,
`icon ('attach_file')`, `accept ('image/*,application/pdf')`, `readonly`,
`max (0 = unlimited)`; emits `count`; exposes `reload()` (line 154). Hidden
native multi `<input type="file">`; client-side compression before multipart
POST `/attachments` (`compressImage` from `aria:frontend/src/utils/image.js`
— max 1280px JPEG q0.7, skips ≤300KB); thumbnail grid `.attach-grid`
(auto-fill minmax(96px,1fr)), `.attach-cell` (12px radius, hover lift +
primary border), dashed empty state `.attach-box__empty` (`1px dashed #E2E8F0`,
radius 12, bg `#FAFCFE`, text `#94A3B8`, `image_not_supported` icon), count
pill, per-item delete, built-in preview `q-dialog` (max 92vw/92vh) with
download. Toasts on upload/remove.

**`avatar-box` — circular photo upload.**
`aria:frontend/src/components/AvatarUpload.vue`. Props: `type` (req), `id`,
`name` (initials), `size (96)`, `readonly`. Circular photo, 3px white border +
shadow; gradient `#175A8C→#1E6BA8` initials placeholder; floating primary
camera chip (`.avatar-up__edit`) showing a white 16px `q-spinner` while
uploading; compresses to 640px q0.72; stored as attachment kind `avatar`.

### 4.9 `action-bar` + `export-btn` + `useExport`

**`action-bar`** — `aria:frontend/src/components/general/ActionBar.vue`. The
standard list-page toolbar. Props (110–122): `rows, columns, filename
('export'), createPerm, importPerm, importUrl, addColor ('teal'), hasDate,
dateField ('date'), addLabel`; emits `add, update:filtered, imported`; slot
`extra-buttons`. Renders:
- Left: permission-gated Add `progress-btn` (label auto-derived from
  `createPerm` slug — `project-create` → "Add Project", overrides map at
  127–140) + Import `progress-btn` (pink-6, `mdi-database-import`).
- Right (only when `rows.length > 0`): Advance Search toggle (blue-grey-9,
  `mdi-tune`) + `export-btn`.
- Advanced search: `q-slide-transition` panel — `row q-col-gutter-sm q-pa-sm
  bg-blue-grey-1` with `border-radius:10px`; text search + optional
  `type="date"` from/to; emits `update:filtered` with client-filtered rows
  (JSON-stringify substring + date range, 155–168).
- Import: `m-modal` (700px) with pink `n-header`, `q-file outlined dense
  color="pink-6"`, SheetJS parse, 5-row preview `q-markup-table` (head
  `bg-pink-1`), POST `{rows}` to `importUrl` (or emit `imported` if no URL).

**`export-btn`** — `aria:frontend/src/components/general/ExportBtn.vue`. Props:
`data, columns, filename`. Exactly two `progress-btn`s — PDF (red-7) and Excel
(green-8) — whose `:indeterminate="busy === 'pdf'|'excel'"`; a 150ms
`setTimeout` lets the knob start animating before the export runs. This is the
canonical progress-button-in-action pattern.

**`useExport`** — `aria:frontend/src/composables/useExport.js`. Branded export
engine: `exportPdf` builds an HTML report (company header gradient
`#123A66→#1c5a9e`, logo, RTL-aware — reads `document.documentElement.dir`,
zebra rows `#f4f8fd`), rasterizes with `html2canvas` (awaits
`document.fonts.ready` so Persian shaping is correct) and slices into
landscape A4 `jsPDF` pages; `exportExcel` via SheetJS with bold header; plus
`exportWord` (.doc blob) and `whatsappShare`. Skips `actions`/`created_at`
columns and auto-formats date columns. Clone wholesale; only the branding
strings change.

### 4.10 Toasts & confirm dialogs

- **Notify defaults** (`aria:frontend/src/boot/globals.js:33–39`):
  `position: 'bottom'`, `timeout: 2600`, `progress: true`,
  `classes: 'app-toast'`, actions `[{ icon:'close', color:'white', round:true,
  dense:true }]`. Styling from `app.scss:191–203` (§3.2).
- **Conventions:** success `Notify.create({ type:'positive', position:'bottom',
  icon:'cloud_done', message:'Saved' })` (icon `cloud_done` for save/upload,
  `waving_hand` for login, `delete` occasionally); error
  `Notify.create({ type:'negative', message: e?.response?.data?.message || 'Save failed' })`.
- **`$delete(url, onDone)`** (globals.js:56–72) — the ONLY destructive-confirm
  pattern; clone verbatim: `Dialog.create({ title:'Delete', message:'Are you
  sure you want to delete this record?', cancel:true, persistent:true, ok:{
  label:'Delete', color:'negative', unelevated:true } })`; on OK
  `api.delete('/'+url)` → positive toast `Deleted successfully` (icon
  `cloud_done`) or the API error message → callback. v2's ConfirmDialog
  requirement (MASTER_PROMPT §2.6) is satisfied by this helper + `Dialog.create`
  with the same option shape for non-delete confirmations.

### 4.11 Empty states, skeletons, spinners

- Table empty: big `search` icon 6em primary + `$t('NoRecordFound')` (§4.1).
- Markup-table empty/loading rows: `<td colspan=N class="text-center
  text-grey-5 q-py-md">{{ $t('NoRecordFound') }}</td>` / a row with
  `<q-spinner color="primary" size="2em" />`.
- Table loading: `q-inner-loading showing color="primary"`.
- Attachment empty: dashed `.attach-box__empty` (§4.8).
- Select no-option: `#no-option` q-item, `text-grey`, `$t('NoRecordFound')`.
- Skeletons are sparse in Aria (`q-skeleton type="text"` for pending counts on
  TrashPage/NotificationPage) — **spinners dominate**; follow the same balance.
- Button loading: `q-spinner-facebook` (n-submit), `:loading` prop elsewhere,
  `progress-btn` indeterminate knob for long jobs, white 16px `q-spinner` in
  the avatar camera chip.
- Dashboard "loading" is the 1200ms ease-out-cubic count-up animation, not a
  spinner (`aria:frontend/src/pages/DashboardPage.vue:207–219`).
- Online/offline indicator: `sync-status` (§4.17).

### 4.12 `shortcuts-panel`

`aria:frontend/src/components/general/ShortcutsPanel.vue`. `defineModel`
visibility; maximized `q-dialog` (slide-up/down) with `bg-cyan-7` header;
grouped shortcut lists rendered as `<kbd>` chips. Global keydown listener:
`?` toggles the panel (ignored while typing in inputs), `Alt+T` toggles dark
mode and persists `theme_dark`. Registered globally; not rendered by any Aria
template yet — v2 may mount it in MainLayout.

### 4.13 `tab-title`

`aria:frontend/src/components/TabTitle.vue`. Props: `title, icon
('chevron_right'), subtitle, count, color`; slot `actions`. Section heading for
tab panels (mobile tab strips are icon-only, so this restates the section):
36px radius-10 chip tinted `color-mix(in srgb, <color || var(--q-primary)> 13%, #fff)`
(computed inline style), name 17px/800 poppins `-0.2px`, count pill
(`color-mix(... 14%, #fff)` bg, primary text, radius 20), subtitle 12px dim,
bottom border `1px solid var(--border-soft, #E7ECF3)`; dark appendix
(`#E2E8F0` name, `#334155` border).

### 4.14 `project-map`

`aria:frontend/src/components/ProjectMap.vue`. Leaflet 1.9.4 (the only map/viz
library). Props: `projects` (display mode), `height ('100%')`,
`interactive (true)`, `pickable` (click/drag picks `{lat,lng}` v-model),
`modelValue`; emits `select, update:modelValue`; exposes `invalidate()` (call
~200ms after showing inside a dialog). Custom SVG teardrop `divIcon` pins
colored by status map (`active #16A34A, near_completion #0D9488, planning
#64748B, awaiting_funding/on_hold #D97706, completed #175A8C, handover
#7C3AED, cancelled #DC2626`, default `#175A8C`); Esri satellite+labels /
Esri streets / OSM / Carto layers with a tile-error fallback chain; root
`.proj-map` radius 12px. v2 reuses it for any geo feature (branch/shop map).

### 4.15 `sync-status` (SyncStatusBar)

`aria:frontend/src/components/general/SyncStatusBar.vue`, registered globally
in `offline.js:13`. Offline-first indicator button: red `wifi_off` + pending
badge (offline) / amber `sync` spinning via `.sync-spin` (syncing) / green
`cloud_done` (synced); rich tooltip with pending count + last sync/error;
click = manual sync. v2's POS online/offline indicator clones this component.

### 4.16 `BrandMark`

`aria:frontend/src/components/general/BrandMark.vue` — an `<img>` of
`assets/brand/logo-mark.svg` with a single `size` prop (default 36). Not
global; imported by MainLayout/AuthLayout/LoginPage. v2 supplies its own
`logo-mark.svg` at the same path.

### 4.17 Date/number utilities

`aria:frontend/src/utils/date.js`: `fmtDate` / `fmtDateTime` (hard-coded
`en-GB` → `"20 Jun 2026"`, `"20 Jun 2026  14:35"`, `'—'` for empty);
`isDateColumn(name)` = named set (`date, join_date, date_of_birth, dob,
start_date, end_date, due_date, expiry_date, delivery_date, issue_date,
payment_date, updated_at`) or suffix `/_date$|_at$/`, **excluding
`created_at`**. `aria:frontend/src/utils/image.js`: `compressImage` used by the
upload components.

### 4.18 Permission gating (the "PermissionGate")

Aria has **no `v-can` directive and no PermissionGate component** — the
mechanism, cloned exactly, is four layers driven by one flat permission-name
array (`entity-action`, e.g. `user-list`):

1. **Store getter** — `aria:frontend/src/stores/auth.js:16`:
   `can: (state) => (perm) => state.permissions.includes(perm) ||
   state.roles.includes('Super Admin')`.
2. **`$can` global** — `globals.js:50–53`: `if (!permission) return true;
   return auth.can(permission)`. Used with plain `v-if` in templates
   (DataTable row buttons, ActionBar Add/Import, header widgets). In
   `<script setup>`: `getCurrentInstance().proxy.$can`.
3. **Router guard** — `aria:frontend/src/router/index.js:19–36`:
   `meta.permission && !auth.can(...)` → silent bounce to dashboard; also
   `requiresAuth`, `guest`, and `platform` (owner-only) checks; lazy
   `fetchUser()` latch on first navigation.
4. **Sidebar filtering** — §5.2.

Permissions/roles arrive from the API on login and `GET /user`
(`permissions: getAllPermissions().pluck('name')`, `roles`,
`is_platform_owner`) and live in Pinia. **UX-only** — v2's server still
enforces authorization per its own CLAUDE.md invariant; do not mistake Aria's
client-side-only entity-action gating for a security model.

### 4.19 Scoped-CSS conventions (for any new component)

Verified across Aria's 54 styled .vue files: 51 use `<style scoped>` (plain
CSS), exactly one global block (`MainLayout.vue` — deliberately unscoped to
theme Quasar drawer internals), zero others. Conventions: BEM-ish names with a
short page prefix; Quasar internals pierced with `:deep()`; dynamic theming
via inline CSS custom properties (`--sc-color`, `--qa`) and computed
`color-mix` styles; legacy utilities composed in templates rather than
restyled (`my_radio_less three_d_plan q-pa-md` is the standard option card);
per-component dark support via small `prefers-color-scheme` appendices;
cross-cutting responsive/stagger rules live in app.scss and reach into the
scoped class names.

### 4.20 `useCurrency` (stat-card interplay)

`aria:frontend/src/composables/useCurrency.js` — module-level shared
`base ('AFN')`/`rates` refs (v2 changes the base currency constant per its own
spec, not the API shape): `loadRates()` (`GET /exchange-rates/current`),
`rateFor(cur)`, `toBase(amount, cur, rate)`, `fmtAmount(v, cur)` →
`Number(v).toLocaleString('en-US') + ' CUR'`, `breakdown(map)` →
`"1,200,000 AFN · 50,000 USD"`, and three functions that return
`{ value, suffix, sub }` objects **shaped exactly for `v-bind` onto
`stat-card`**: `smartMoney(baseTotal, currencyMap, fallbackSub)`,
`ledgerTotals(rows)` (confirmed-only per-currency + base sums), and
`netMoney(netBase, netMap, creditLabel, debitLabel)` (signed splits). All
formatting is hard-coded `en-US` Latin digits regardless of locale (§7.6).

---

## 5. App shell recipe

**Aria files:** `aria:frontend/src/layouts/MainLayout.vue` (633 lines —
template + script + the single global `<style lang="scss">` block),
`aria:frontend/src/layouts/menus.js` (menu data). `App.vue` is just
`<router-view />`.

### 5.1 Layout composition

- `<q-layout view="hHh LpR fFf">` — fixed header, left drawer below it.
- `<q-header class="topbar-header">` with one
  `<q-toolbar class="q-px-md" style="min-height:48px">`.
- `<q-drawer v-model="leftDrawerOpen" show-if-above :width="262"
  :breakpoint="600" class="sidebar-drawer">` — **no mini mode**; ≥600px it's
  part of the layout, <600px it's Quasar's mobile overlay. `leftDrawerOpen =
  ref(false)`, toggled by the hamburger.
- `<q-page-container><router-view /></q-page-container>` on the `#f5f8fe` bg.
- Layout-level timers: 1s clock; 60s notification polling; both cleared in
  `onBeforeUnmount`.
- Known Aria quirk (do not "fix" silently): ThemePage's Mini/Normal/Wide
  sidebar option only sets `body.sidebar-mini` (offline.js:28) — **no CSS
  consumes it**; the drawer has no `mini` prop. Clone as-is.

### 5.2 Sidebar

**Menu data structure** (`menus.js`): exported `menus` array; each entry
`{ room, permission, icon, name, status, color, url, is_sub[], [platform],
[add_url] }`. `url: null` ⇒ group with children in `is_sub`; `url` set ⇒
top-level link. Sub-items carry their own `permission, icon, url` and optional
`add_url` (renders a small round `add` quick-create button on the row).
`name` is an **i18n key** (`$t(m.name)`). Section order is workflow order:
Dashboard → (platform item) → domain groups → Reports → Administration →
System → a final pseudo-item `{ name: 'Logout', url: '' }` that the template
special-cases into a red action row. Icons are mixed Material (majority) +
MDI v7 (`mdi-monitor-dashboard`, `mdi-shield-crown`, `mdi-logout`).
v2 keeps the exact shape and rendering; only the entries change to v2's modules.

**Rendering & permission filtering** (`MainLayout.vue:330–362` + template):
`showMenus` computed deep-clones `menus`, then: `platform` items get
`status = auth.isPlatformOwner` (never role-based); groups mark each sub
`status=false` when `!$can(sub.permission)` and hide the group when no visible
subs remain; plain links hidden when `!$can(e.permission)`; then the drawer
search box filters case-insensitively on group/sub `name` (empty groups
collapse). The template double-checks per node (`v-if` with `$can`).

**Drawer contents in order:**
1. `.sidebar-brand` block: `<brand-mark size="30">` + company name (14px bold)
   + subtitle "Construction ERP" in `.sidebar-brand-subtitle` (accent color) —
   v2 subtitle text changes, classes don't.
2. Menu filter: `q-input outlined dense clearable debounce="200"
   color="cyan-7" bg-color="grey-1"` with `search` prepend, radius 8px.
3. `q-separator`, then `q-scroll-area style="height: calc(100vh - 155px)"`
   wrapping the `q-list` (`font-family: poppins, sans-serif`).

**Groups:** `<q-expansion-item group="sidebarGroup" expand-separator
:icon :label header-class="sidebar-group-header q-mb-xs"
expand-icon-class="sidebar-expand-icon">` — the shared group name makes an
**accordion** (opening one closes others). Sub-list is `q-list.q-pl-sm` of
`q-item clickable dense v-ripple :to` rows.

**Active state:** exact path compare — `m.url === $route.path` adds
`sidebar-item--active` (subs: `sidebar-sub-item--active`). Style recipe
(MainLayout style block): active = `var(--sidebar-accent-bg, #FBF0DD)` bg,
`var(--sidebar-accent, #C8862D)` text, weight 600, `border-left: 3px solid
var(--sidebar-accent)`, `padding-left: 7px` (subs 5px); hover = same accent
tint; base item = radius 8px !important, min-height 40px (subs: radius 6px,
34px), color `#546e7a` (subs `#607d8b`); labels 13px/500 (sublabels 12.5px);
group headers 13px/600 `#37474f`, hover `#eceff1`, expanded → accent bg/text
via `.q-expansion-item--expanded > .q-expansion-item__container >
.sidebar-group-header`; `--danger:hover` = `#ffebee` (Logout);
`--vip` item shows an `amber-6` icon. Note: Aria passes
`:color="'var(--sidebar-accent, #C8862D)'"` to `q-icon` — invalid as a Quasar
palette name; the gold actually comes from the parent CSS rule. Clone as-is.

### 5.3 Header (topbar) — element by element, left → right

1. Hamburger `q-btn flat dense round icon="menu" color="white"`.
2. Company block: white 28px `q-avatar` with the first letter of
   `company.abbreviation || companyName`; company name (bold 15px,
   `mobile-hide`); `/`-separated route label — `routeLabel` computed =
   `route.meta.title || title-cased route name` (no Aria route sets
   `meta.title`, so it's always the humanized route name).
3. `q-space`, then global-search button (`icon="search"`) → command palette:
   `q-dialog position="top"` with `.gsearch` card (640px, radius 14px,
   `margin-top: 8vh`), borderless autofocus input, 250ms debounced
   `GET /search`, grouped results with uppercase 11px `#94A3B8` group labels,
   navigate on click.
4. Fullscreen toggle (`mobile-hide`) via the `AppFullscreen` plugin.
5. Notifications bell — `v-if="$can('notification-list')"`; floating red
   `q-badge` with unread count; 340px `q-menu` containing a
   `my_radio_less` card with `q-bar.bg-cyan-7` header + `MarkAllRead` button
   (`POST /notifications/mark-read`), 400px `q-scroll-area` list (unread rows
   `bg-cyan-1`, "New" `q-badge color="cyan-6"`), type→icon/color maps; loaded
   from `GET /notifications`, polled every 60s.
6. Language dropdown — `q-btn-dropdown icon="language" :label="currentLang"`
   (`EN / فا / پښ`), gated `$can('language-list')` with each language item
   gated `lang-en-list`/`lang-fa-list`/`lang-pa-list`; `setLang()` per §7.2.
7. Profile menu — `q-btn flat round icon="account_circle"` → 300px `q-card`
   containing `div.bg-white.my_card` with: (a) company avatar
   (`cyan-6`) + name row + inline logout icon button; (b) the **branch
   switcher** (deliberately lives ONLY here): caption + `q-list dense` of
   options — "AllBranches" row only when `sees_all_branches`, one row per
   branch, active row `active-class="branch-option--active"` (gold tint),
   icons `public`/`place` in `cyan-7` when active; `selectBranch()` sets the
   `X-Branch-Id` axios header (`api.setBranch`, persisted `active_branch`),
   POSTs `/me/branch`, then **`window.location.reload()`**; (c) red LogOut row.
8. Live clock — `toLocaleTimeString()` every second, `mobile-hide`,
   `opacity:.75; min-width:72px`.

`.topbar-header` = `linear-gradient(135deg, var(--topbar-from, #123A66) 0%,
var(--topbar-to, #0B1626) 100%)` + `box-shadow: 0 2px 12px rgba(0,0,0,.2)` +
`border-bottom: 1px solid rgba(255,255,255,.15)`.
`.mobile-hide` hides brand text/fullscreen/clock under 600px.

### 5.4 Theme system

**No theme toggle in the header** — everything lives on a dedicated Theme page
(route `/theme`, permission `theme-list`), cloned from
`aria:frontend/src/pages/system/ThemePage.vue`:

- **Dark mode:** `q-btn-toggle` → `$q.dark.set(val)` + localStorage
  `theme_dark` (`'1'`/`'0'`). All dark styling hangs off `body.body--dark`
  (§3.2) — components never hard-code dark variants beyond the small
  `prefers-color-scheme` appendices.
- **Header presets** — 10 construction palettes, each
  `{ name, from, to, accent, accentBg }` (ThemePage.vue:106–117):

| Preset | from | to | accent | accentBg |
|---|---|---|---|---|
| Steel Blue (default) | `#123A66` | `#0B1626` | `#175A8C` | `#EAF3FB` |
| Amber Gold | `#8A5A1E` | `#5C3A10` | `#C8862D` | `#FBF0DD` |
| Slate | `#334155` | `#0F172A` | `#475569` | `#F1F5F9` |
| Forest Green | `#1E4620` | `#0D2A0E` | `#2E7D32` | `#E8F5E9` |
| Brick Red | `#7A2E22` | `#4A1912` | `#B23A2A` | `#FCE9E6` |
| Concrete Grey | `#4B5259` | `#282C30` | `#6B7280` | `#F3F4F6` |
| Safety Orange | `#8A4A0E` | `#5C3009` | `#E07A1F` | `#FDF0E1` |
| Charcoal | `#1F2937` | `#0B0F16` | `#374151` | `#F1F2F4` |
| Copper | `#7A4B2E` | `#4A2C1A` | `#B87333` | `#FBEEE1` |
| Navy | `#0A1628` | `#050B14` | `#123A66` | `#E7EEF6` |

  Applying a preset writes `--topbar-from/to`, `--sidebar-accent(-bg)`, **and
  `--q-primary` = accent** onto `documentElement`, plus the five localStorage
  keys, and toasts confirmation. Rendered as `.theme-swatch` gradient circles.
- **Font size:** small/normal/large → root `font-size` 13/14/16px
  (`theme_font`).
- **Border radius:** Sharp/Normal/Round → `--my-radius` 2/8/16px
  (`theme_radius`).
- **Reset:** clears all keys, restores defaults.
- **Boot re-application** — `aria:frontend/src/boot/offline.js:16–38` re-applies
  every persisted value on startup (dark, `--q-primary`, font size,
  `--my-radius`, topbar/sidebar vars). Persistence keys: `theme_dark,
  theme_primary, theme_font, theme_radius, theme_sidebar, theme_topbar_from,
  theme_topbar_to, theme_sidebar_accent, theme_sidebar_accent_bg`.
- Cards on the page are the standard option-card recipe:
  `q-card class="my_radio_less three_d_plan q-pa-md"` with a bold subtitle row.

### 5.5 Routing shell

`aria:frontend/src/router/routes.js`: `/login` → AuthLayout child (name
`login`, `meta: { guest: true }`); `/` → MainLayout (`meta: { requiresAuth:
true }`) with lazy children each carrying `meta.permission` matching the
sidebar keys; catch-all `/:catchAll(.*)*` → `ErrorNotFound.vue` **outside any
layout** (fullscreen `bg-blue` 404 with "Go Home"). Hash history +
`publicPath: './'` (Electron/LAN-friendly). Guard per §4.18.3. Aria quirk to
avoid repeating in v2: its `/user`, `/role`, `/branch` sidebar aliases lack
`meta.permission` — v2 should give aliases the same `meta.permission` as their
canonical routes (behavior fix, zero visual impact).

### 5.6 Page title pattern

No `document.title` management and no breadcrumb component — the only
breadcrumb is the topbar `companyName / routeLabel` pair. The de-facto
PageShell is composed per page: `m-backgrounds` → `m-header` (+ `action-bar`,
`n-table`, `stat-card` row) → `tab-title` inside tab panels. Clone this
composition; do not invent a new PageShell abstraction.

---

## 6. Auth screens recipe

### 6.1 AuthLayout (split screen)

**Aria file:** `aria:frontend/src/layouts/AuthLayout.vue`.
`q-layout view="hHh lpR fFf"` → `q-page.auth-page` (100vh, `#F0F4F8`) →
`.auth-split` CSS grid `grid-template-columns: 1fr 420px; height: 100vh;
overflow: hidden`; **<900px: single column, left panel hidden**.

**Left panel `.auth-left`** — dark branding panel:
- Background `linear-gradient(145deg, #0A1628 0%, #0D2B4E 40%, #123A66 70%,
  #8A5A1E 100%)` (navy → gold), flex-centered, padding 40px; inner content
  max-width 520px, z-index 2.
- `.auth-logo`: 52×52 frosted square (`rgba(255,255,255,.12)`, radius 14px,
  `1px solid rgba(255,255,255,.2)`) with `<brand-mark size="30" />`; beside it
  `__name` 22px/800 white and `__tagline` 12px 50%-white.
- `.auth-headline` 38px/800 white, `-1px` letter-spacing, 3 lines; the last
  line wrapped in `.auth-headline__gold` — gradient-clipped text
  `linear-gradient(90deg, #E9B44C, #C8862D)` + `background-clip: text;
  color: transparent`.
- `.auth-sub` 15px, 65%-white marketing line.
- `.auth-badges` — three discipline chips `.auth-badge`: frosted
  `rgba(255,255,255,0.07)`, gold border `rgba(233,180,76,0.35)`, radius 14px,
  `backdrop-filter: blur(6px)`; each with a 40px gold ring icon
  (`__ring`: `#E9B44C` icon, `2px solid rgba(233,180,76,0.65)`, bg
  `rgba(233,180,76,0.12)`) and **bilingual labels** (`__t` 12.5px/800 white,
  `__s` 10.5px 65%-white Farsi). v2 swaps the three texts/icons for
  shopping-center disciplines, same classes.
- Decorative: `.auth-circle--1` (400px, top-right) and `--2` (280px,
  bottom-left) translucent circles; `.auth-skyline` — a giant **inline
  data-URI SVG** (bottom 58% of the panel; wireframe buildings, gold crane,
  scaffolding, dashed gold road, vehicles, birds — zero external assets).
  v2 replaces the SVG artwork with shopping-center-themed line art of the same
  style (faint white strokes + `#E9B44C` gold accents), same class and
  positioning.
- Unused-but-present style blocks (`.auth-features`, `.auth-stats`,
  `.auth-biz*`, `.auth-circle--3`) ship with the file; keep them (verbatim
  clone) — they are part of the file's identity.

**Right panel `.auth-right`** — white column: flex, centered, padding
`40px 32px`, shadow `-4px 0 40px rgba(0,0,0,.08)`; `__inner` caps content at
360px; `__footer` copyright 11px `#94A3B8`.

### 6.2 LoginPage

**Aria file:** `aria:frontend/src/pages/auth/LoginPage.vue`. Structure:
`.login-box` → `.login-brand` (brand-mark 36 + `__name` 16px/700 `#0F172A` +
`__ver` 11px `#94A3B8`) → `.login-title` "Welcome back" (26px/800 `#0F172A`,
`-0.5px`) → `.login-sub` (14px `#64748B`) → `q-form.login-form`:

- Field labels: `.login-field-label` 12px/600 `#475569` above each input
  (labels above, not floating).
- Inputs: `q-input outlined dense` class `login-input` (radius override
  `.login-input :deep(.q-field__control) { border-radius: 10px !important }`);
  email `type="email" autofocus` with `alternate_email` prepend (18px,
  grey-5); password with `lock_outline` prepend and appended eye toggle
  (`visibility`/`visibility_off`, `showPwd` ref); both rule
  `[v => !!v || 'Required']`.
- Row: `q-toggle dense size="sm" color="cyan-7"` "Remember me" + "Forgot
  password?" caption (`text-cyan-7 cursor-pointer`).
- Submit: `q-btn type="submit" unelevated class="full-width login-btn"
  :loading="loading" :disable="loading"` — "Sign In" + `arrow_forward` icon;
  `.login-btn` = `linear-gradient(135deg, #123A66, #175A8C) !important`,
  white, 44px, radius 10px, 15px/600. Quasar's built-in spinner shows during
  loading.
- Demo block `.login-demo` (top border `#F1F5F9`): uppercase 11px `#94A3B8`
  title + small outline grey demo button(s) that pre-fill credentials.
- `.login-offline` amber bar style (`#FEF3C7`/`#92400E`, radius 8) exists but
  is unused in Aria's template — available for v2's offline notice.
- The login screen is **hardcoded English** (no `$t`); Farsi appears only in
  the AuthLayout badges. RTL users still get a flipped layout via the `dir`
  attribute. Clone this behavior.

### 6.3 Auth flow (frontend wiring that the screens depend on)

- Pinia store `aria:frontend/src/stores/auth.js`: `login()` POSTs `/login`,
  stores the token via `api.setToken` when present, `setSession` stores
  `user/permissions/roles/is_platform_owner`; `fetchUser()` hydrates on first
  navigation and sets the `ready` latch; `logout()` POSTs `/logout` (errors
  swallowed), clears token + state.
- `onSubmit` pattern: `loading=true` → `auth.login(form)` → positive notify
  `{ icon:'waving_hand', message:'Welcome back!' }` → `router.push({ name:
  'dashboard' })`; catch → negative notify with API message or 'Login failed';
  finally `loading=false`.
- **Logout flow:** triggered from the profile menu (two places) and the
  sidebar's red Logout pseudo-item → `await auth.logout();
  router.push('/login')`. No confirm dialog on logout.
- Token model (axios boot `aria:frontend/src/boot/axios.js`): Bearer token in
  localStorage `auth_token`, restored into the default Authorization header at
  boot; `withCredentials`; `X-Branch-Id` header from `active_branch`;
  DELETE/PUT/PATCH tunneled over POST with `X-HTTP-Method-Override`; **no
  response interceptor / no global 401 handler** — recovery is the router
  guard's `fetchUser()` failure path. (v2 may harden this server-side per its
  own rules; the visible behavior stays the same.)

---

## 7. RTL & i18n rules

### 7.1 The custom `$t`

`aria:frontend/src/boot/i18n.js` (41 lines — the whole mechanism; **no
vue-i18n**). A `reactive({ locale: localStorage.getItem('locale') || 'en' })`
singleton; `translate(key, fallback)` looks up
`messages[state.locale] || messages.en`; missing keys return the `fallback`
arg if given, else the **humanized key** (`WorkBreakdown` → "Work Breakdown"
via camelCase/underscore/hyphen splitting) — labels never show raw keys. No
interpolation support (and none used). Exported `i18n` object with a
locale getter/setter (setter persists to localStorage). Boot installs
`$t`/`$i18n` globals and applies `dir=rtl` at startup for `fa`/`pa`. Boot
order (`quasar.config.js:14`): `['pinia', 'axios', 'i18n', 'globals',
'offline', 'pwa']` — i18n before globals so `$t` exists for components.

**Dictionary layout:** `aria:frontend/src/i18n/index.js` aggregates
`{ en, fa, pa }`. Each dictionary is a flat ES-module object of
`PascalCaseKey: 'Translation'` pairs — no namespacing, no dots — organized by
comment section headers. Aria: en/fa each 820 entries (816 unique; 4 benign
duplicate keys), **exact key parity**; `pa/index.js` is a 5-line re-export of
`en` (Phase-2 placeholder). Farsi values use proper ZWNJ (`نقش‌ها`) and Afghan
vocabulary. **v2 rule:** same mechanism and key style; port the legacy
dictionaries verbatim (MASTER_PROMPT §6) into the same file layout; keep
en/fa parity enforced; missing-key humanization stays.

### 7.2 Language switching

Header dropdown (§5.3.6) — permission-gated per language. `setLang(lang)`
(`MainLayout.vue:365–371`): `i18n.locale = lang` (reactive → every `$t`
binding re-renders live, no reload), `dir = fa|pa ? 'rtl' : 'ltr'` on both
`documentElement` and `body`, plus `documentElement.lang = lang`. MainLayout's
`onMounted` re-applies dir/lang from localStorage. The **Quasar language pack
is never switched** (`framework.lang` commented out; `$q.lang.rtl` never
true) — Quasar-internal strings stay English and Quasar's RTL machinery is
bypassed deliberately. Clone this exactly.

### 7.3 How RTL flips

Purely **attribute-driven + one CSS block** — no rtlcss, no Quasar RTL:

`[dir="rtl"]` block, `aria:frontend/src/css/app.scss:30–46` (already in v2's
copied file):
- Font swap to `'afg_sans', 'Vazirmatn', 'Tahoma', 'Arial', sans-serif` on
  `body, .q-table th, .q-item`.
- Drawer repositioning: `.q-drawer--left { right: auto !important }` /
  `.q-drawer--right { left: auto !important }`.
- `.q-item__section--avatar` margin mirror; `.q-field__prepend` padding/order
  flip; `.q-field__control { flex-direction: row-reverse }`.
- Tables: `th, td { text-align: right }`, last-child (actions) forced left.
- `.q-card { direction: rtl }`; `.q-bar { flex-direction: row-reverse }`;
  `.row { flex-direction: row }` deliberately kept LTR.
- Icon mirroring: only the expansion chevron —
  `.q-expansion-item__toggle-icon { transform: rotate(180deg) }` (270deg when
  expanded). There is no general icon-mirroring system.

### 7.4 Direction-aware CSS conventions for new code

Modern components are RTL-safe via **CSS logical properties** — use
`inset-inline-start`/`inset-inline`/`margin-inline-start` for anything
directional (StatCard accent bar + shine sweep, dashboard scan beam, auth
skyline all do this), so effects mirror automatically under `dir="rtl"`
without extra rules. Never use `left`/`right` for new directional chrome
unless the legacy class already does.

### 7.5 Fonts per locale

§2.2. Summary: LTR Poppins→Roboto; RTL `afg_sans` (bundled) → Tahoma/Arial in
practice (Vazirmatn is an unbundled fallback name). Exports use
`'afg_sans','Vazirmatn','Poppins','Segoe UI',Tahoma,Arial` and await
`document.fonts.ready` before rasterizing so Persian glyph shaping is correct
(`useExport.js`).

### 7.6 Date/number formatting (useCurrency interplay)

- **Display dates are always Gregorian en-GB** (`"20 Jun 2026"`) regardless of
  locale — `$fmtDate`/`$fmtDateTime` (§4.17). Jalali exists only as an *entry*
  aid (`shamsi-date`, §4.8) whose model stays Gregorian `YYYY-MM-DD`.
- **Numbers/money are always `toLocaleString('en-US')` Latin digits** with the
  ISO currency code as a suffix (`"1,200,000 AFN"`) — `useCurrency` (§4.20).
  No Arabic-Indic digit rendering anywhere (conventional for Afghan accounting
  UIs). Currency codes are never translated.
- Money → stat-card: `smartMoney`/`netMoney`/`ledgerTotals` return
  `{ value, suffix, sub }` for direct `v-bind`; single-currency values keep
  their own currency front-and-center, mixed values show the base total with
  the per-currency split in `sub`.
- Exports are direction-aware at runtime: `useExport.js` reads
  `document.documentElement.dir` and mirrors alignment/labels accordingly.
- The only locale-floating formatter is the header clock
  (`toLocaleTimeString()` with browser default) — clone as-is.

---

## 8. Do-not-drift rules (developer checklist)

Before merging any v2 UI work, verify:

1. **The copied CSS files are untouched.** `frontend/src/css/{app.scss,
   brand.css, quasar.variables.scss}` remain byte-identical to Aria
   (`cmp` against `aria:frontend/src/css/*`). All extensions live in separate
   files loaded after them.
2. **Same class names, same combos.** Page shell =
   `three_d_new q-ma-sm q-pa-sm my_radio_less bg-white` (via `m-backgrounds`
   only — never hand-rolled); tables = `q-ma-sm my_radio_less three_d_new`;
   modals = `three_d_high` (via `m-modal` only); option cards =
   `my_radio_less three_d_plan q-pa-md`; pill buttons = `my_radio_high`.
3. **No ad-hoc styling.** Every page is composed from the global components
   (§4 alias table) + Quasar utilities + the copied CSS classes. New scoped
   CSS only for genuinely page-specific content, following §4.19 conventions
   and the §2.1 palette (exact hexes — `#E7ECF3` borders, `#0F172A` strong
   text, `#64748B` dim, etc.).
4. **No new one-off components** where a shared one exists: tables through
   `n-table`, KPIs through `stat-card`, page titles through `m-header`,
   section headings through `tab-title`, submits through `n-submit`, toolbar
   actions through `action-bar`/`progress-btn`/`export-btn`, selects with
   quick-add through `n-select-add`, uploads through `attach-box`/`avatar-box`.
5. **Feedback patterns are fixed:** bottom `app-toast` notifies with
   `cloud_done` on success; `$delete` for every destructive action;
   `q-spinner-facebook` for form saves; indeterminate `progress-btn` for long
   jobs; `NoRecordFound` empty states.
6. **Theming only through the variables.** Colors come from `$primary`/
   `--q-primary`, the surface vars, `--sidebar-accent(-bg)`,
   `--topbar-from/to`, `--my-radius`, and `color-mix` tints — never new
   hard-coded brand colors. Dark mode = `body.body--dark` surface remaps plus
   (optionally) a 3-line `prefers-color-scheme` appendix per component
   (`#1E293B`/`#334155`/`#F1F5F9`). If it looks wrong in dark mode, the fix is
   surface variables, not per-component colors.
7. **RTL:** never enable `postcss-rtlcss` or Quasar RTL; direction is the
   `dir` attribute + the existing `[dir="rtl"]` block; new directional CSS
   uses logical properties (§7.4).
8. **i18n:** all user-facing strings via `$t('PascalCaseKey')`; keys added to
   `en` and `fa` together (and `pa` stays a re-export until translated);
   table column `label`s are keys, not strings. Login page stays English.
9. **Permission gating** exactly per §4.18: `$can` + `v-if` in templates,
   `meta.permission` on routes, per-entry `permission` in the menu data —
   plus real server-side enforcement per v2's CLAUDE.md (client gating is
   UX-only).
10. **Registration parity:** every shared component registered globally in
    `boot/globals.js` under the Aria alias (including the `vissibleCols` slot
    typo and other API quirks) so templates remain portable 1:1 between the
    two systems.
11. **Quasar config parity:** `css: ['app.scss', 'brand.css']` order; extras
    `mdi-v7 + roboto-font + material-icons`; plugins `Notify, Dialog,
    AppFullscreen`; Notify defaults from §4.10; boot order
    `pinia, axios, i18n, globals, offline(-equivalent), …`.
12. **Zero CDN**: fonts, icons, map tiles config, and all libs bundled/local
    (LAN-first, matching Aria's Electron-safe choices).
13. When Aria's implementation has a quirk (drawer mini no-op, `q-icon` CSS-var
    color props, `controlRoomButton="false"` string, unused style blocks),
    **clone the quirk** unless it is a functional bug that REQUIREMENTS
    overrides (e.g. alias-route permissions, §5.5) — and log any such
    deliberate divergence in `docs/PROGRESS.md`.
