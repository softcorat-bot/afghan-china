# Afghan China — Offline Mode runbook

One application, two modes. **Online** is the existing Central server, unchanged.
**Offline Mode** is the same Laravel backend plus the same Quasar dashboard running
on the shop's own PC against its own local SQLite database. This document is the
operator's guide: install, register, seed, sync, back up, and test.

Technical background: `docs/OFFLINE-UNIFICATION-AUDIT.md`.
Installer source: `installer/windows/`. Legacy Node till notice: `offline/SUPERSEDED.md`.

---

## 1. Concepts in one minute

- Every offline write (sale, refund, customer, product, shift, expense, …) is saved
  locally **and** captured into `offline_outbox` with a global UUID and an
  idempotency key (`change_uuid`).
- **Sync Now** pushes the outbox to Central (`POST /api/v1/sync/push`), then pulls
  Central's changes since the stored cursor (`GET /api/v1/sync/pull?since_seq=N`),
  applies them transactionally, and acknowledges the new cursor.
- Retries are safe: Central's `sync_inbox` ledger replays an already-seen
  `change_uuid` instead of applying it twice. A lost response can never duplicate
  a sale.
- Financial documents are append-only. A conflict is recorded for a human — never
  silently overwritten.
- Stock is an event stream (sales, refunds, adjustments), never a synced number.

## 2. Installing a shop PC

1. Run **`Afghan-China-Offline-Setup.exe`** (built by CI from `installer/windows/`).
   It asks for the Central URL, installs the program, creates
   `C:\ProgramData\AfghanChina\data\`, migrates the local database and adds
   Start Menu / Startup / Desktop shortcuts.
2. Start **Afghan China** from the Start Menu. The dashboard opens at
   `http://127.0.0.1:8080/app/`.

No XAMPP, no database server, no developer tools. The installer carries its own
PHP runtime.

## 3. First-time setup (needs internet once)

On **Central**, an administrator opens Settings → Devices → *Register a till* and
reads out the one-time activation code. Then, in the app folder on the **shop PC**:

```bat
php\php.exe backend\artisan offline:register --code ABCD-1234
php\php.exe backend\artisan offline:seed
```

`offline:seed` downloads the company, roles, staff (so the usual passwords work
offline), branches, counters, catalogue, customers and suppliers, preserving
Central's ids. Afterwards staff sign in normally and the till sells offline.

(Registration is also available in System → Sync Center for re-registration, but
the very first registration must be the CLI: there are no local users yet to
sign in with.)

## 4. Daily operation

- **Sell normally.** Internet or not, everything works: POS, shifts, refunds,
  customers, inventory, expenses, reports, printing, PIN terminal.
- **When internet returns:** System → **Sync Center** → **Sync Now**.
- The page shows connection, last sync, pending / synced / failed / conflict
  counts, the sync position (cursor), the outbox table with per-row errors and
  retry, conflicts with local-vs-server values, and local backups.

Optional automatic sync: set `OFFLINE_AUTO_SYNC_MINUTES=15` in `backend\.env` and
run the scheduler (e.g. a Windows scheduled task every 5 minutes executing
`php\php.exe backend\artisan schedule:run`). Manual Sync Now always stays available.

## 5. Backups

- **Automatic:** every installer upgrade takes a `pre-upgrade` snapshot first.
- **Manual:** Sync Center → *Back up now*, or `php artisan offline:backup`.
  Snapshots are SQLite-native (`VACUUM INTO`) in
  `C:\ProgramData\AfghanChina\data\backups\`, newest 14 kept, device identity
  stored alongside.
- **Restore:** Sync Center → *Restore* on a backup row (a `pre-restore` safety
  snapshot is taken first), or `php artisan offline:backup --restore=<file>`.

## 6. Troubleshooting

| Symptom | What to do |
|---|---|
| Sync Center says *Central unreachable* | Check internet / `CENTRAL_URL` in `backend\.env`. Work stays queued; nothing is lost. |
| *Device rejected / revoked / disabled* | The till was cut off centrally. Re-authorize in Central → Settings → Devices. |
| Rows stuck in *Failed* | Open the outbox row: the exact server message is shown. Fix the data (or requeue after a server-side fix) and press Sync Now. |
| *Conflicts* | Open the conflict: local vs server values and differing fields are shown. Financial conflicts need an authorized resolver centrally. |
| App won't start | Read `backend\storage\logs\offline.log` (sync) and `laravel.log` (app). |
| Need to move PCs | Install on the new PC, copy the newest backup + identity over, restore, register the new device id centrally, sync. |

## 7. Test matrix (§52 scenarios)

| # | Scenario | Steps | Expected |
|---|---|---|---|
| A | Simple offline sale | Disconnect → sell → reconnect → Sync Now | Sale appears centrally (with a Central `INV-` number, device number preserved); outbox row `synced` |
| B | Drop mid-sync | Sync with the cable pulled halfway, then sync again | No duplicate sale (ledger replay shows `duplicate` → `synced`) |
| C | 100 offline sales | Ring 100 sales offline, sync | All 100 applied exactly once; batches paginated |
| D | Price changed both sides | Edit a product offline + centrally, sync | Conflict recorded with both values; policy `field_merge` per `config/sync.php` |
| E | Stock both sides | Sell offline + purchase centrally, sync | Both movements applied; quantities converge — no overwrite |
| F | Offline customer | Create customer offline, sync | Customer created centrally by UUID; later sales resolve to it |
| G | Delete offline | Delete a customer offline, sync | Tombstone applied centrally; other devices pull the deletion |
| H | Partial failure | One invalid change among valid ones | Valid → `synced`, invalid → `failed` with message; retry possible |

Automated coverage: `backend/tests/Feature/Offline/*`
(`composer test` / CI `offline-installer` job runs the offline suite).

## 8. Known limitations (honest)

- **Product images** are files, not rows: pulled products reference Central's image
  paths, which 404 until the PC is online. Offline-created product photos stay on
  the PC until image sync ships (tracked follow-up).
- **Wholesale quotations, purchases, HR/payroll, Main Cost** are fully usable
  offline (same app, same logic) but are not pushed yet — the server accepts the
  nine entities in `config/sync.push_entities`. Extending push to a new entity =
  one server handler + one `ChangeBuilder` method + `offline.observed` entry.
- The local web server (`artisan serve`) is sized for one shop PC, not a LAN of
  tills — one Offline installation per PC, each with its own device id.
