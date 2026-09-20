# Offline Testing — what is covered, how to run it, what needs hardware

## 1. Automated: the end-to-end suite (18 checks)

```bash
cd offline
npm test        # node test/e2e.mjs — needs Node 22.5+, nothing else
```

The suite runs the **real engine** against a protocol-conformant central-surface
test double (idempotency ledger, one row per entity uuid, cursor pull — the same
rules `SYNC_ENGINE.md` documents for Laravel, not a mock of the data):

| # | check |
|---|---|
| 1 | till starts with an identity and no data |
| 2 | selling never waits for a drawer session; offline login by hash; wrong password refused; scrypt-only storage |
| 3 | 20 sales + 5 customers + 3 returns + 10 stock movements + 2 cash sessions — all with no server |
| 4 | restarting the till loses nothing |
| 5 | server down → sales continue, nothing lost |
| 6 | device activates with the administrator's code |
| 7 | Sync Now uploads everything, exactly once |
| 8 | repeated Sync Now creates no duplicates |
| 9 | lost response → retry without double-charging |
| 10 | rejected change keeps its place; the rest of the batch goes through |
| 11 | central changes flow down incrementally (cursor) |
| 12 | backup carries the unsynced queue; restore keeps it |
| 13 | two tills never collide (device-scoped invoice numbers) |
| 14 | the till reports honest states (offline/online/syncing/pending/failed/conflict) |
| 15 | first-run setup without a terminal: provision, first sale, dead-server register fails honestly, sale is pending not lost |
| 16 | logs land in files (application/sync/security), refused + accepted sign-ins recorded, **known password provably absent**, log tail API requires sign-in |
| 17 | receipts render to text + ESC/POS (init/cut bytes, drawer kick, ASCII-safe Dari), raw mode honesty (409 no-share / 501 non-Windows), failed print never touches the sale, printer settings round-trip |
| 18 | the till screen only references DOM ids that exist |

**Result on the reference run (2026-09-20, this branch): 18/18 ✓**

Additionally, the installer build self-tests the *actual packaged blob* — with a
**fetched runtime of the version the till ships**, never the build host's own
Node, which is caught at the top of `installer/build-windows.sh` step 1. Under
that runtime the packaged form has to boot, serve the embedded screen, create the
database and run the CLI (step 5).

Nothing here can prove that a *Windows* `.exe` starts, and an installer once
shipped that aborted on every Windows PC for exactly that reason: the blob was
written by the build host's Node 22 and read by the shipped Node 26, and every
check in this section passed. So the built installer is now unpacked, run,
installed and health-checked on `windows-latest` as well —
`installer/verify-windows.ps1`, run by the `windows-installer` job in
`.github/workflows/offline-till.yml`, and by `publish-release-assets.yml` before
it uploads anything (`docs/RELEASE.md` §3 step 3).

The web frontend production build (`npx quasar build` in `frontend/`) compiles
with the new fleet pages (`PosDevicesPage`, `SyncMonitorPage`, `SyncConflictsPage`
verified in `dist/spa/assets/`).

## 2. Manual matrix — run before every release

### Offline operation (internet cable OUT / Wi-Fi OFF)

- [ ] Cold-start the till → last screen state returns, date/clock correct
- [ ] Sign in with a cached user; wrong PIN refused three times
- [ ] Barcode search; product search incl. Dari names
- [ ] New customer; sale: 3 lines + bill discount + cash overpay → change shown
- [ ] Receipt prints (both `dialog` and — on Windows — `raw` mode)
- [ ] Open/close drawer session; cash-in, cash-out with reasons; Z totals sane
- [ ] Line-level refund of a synced and an unsynced sale
- [ ] Local reports for today
- [ ] Kill the exe mid-sale (Task Manager) → restart: no half-sale, totals exact
- [ ] Backup created; restore on a scratch folder keeps pending queue

### Sync recovery (cable back at different points)

- [ ] 20 offline sales → online → auto-sync within the interval; `pending → synced`
- [ ] Pull plug *during* push (watch sync.log) → next cycle completes, no duplicates
- [ ] Repeated Sync Now rapidly → server shows one batch, idempotency replays
- [ ] Server 500s → backoff visible in queue (`next_attempt_at`), succeeds later
- [ ] Validation rejection (e.g. unknown customer uuid) → row stays `failed`
      with reason; other rows pass
- [ ] Disable device on web admin mid-queue → till flags *device blocked*, keeps data
- [ ] Conflict: edit a product price centrally + sell it offline → conflict
      appears in web Conflict Center; resolve *accept server* → till releases its
      queue row on next sync

### Upgrade

- [ ] Install v1, sell 3 sales offline, install v2 over it → data, queue,
      device id intact; `verify.ps1` clean; sync resumes

### Frontend (web admin) — needs the Laravel backend running

- [ ] *Settings → Devices*: register a till → activation code shown once →
      copy works; enable/disable/revoke/reauthorize; stale badge after 3 days
- [ ] *Settings → Synchronization*: totals cards, device attention rows, batch
      filters, prune action (super admin/manage-devices)
- [ ] *Settings → Conflicts*: pending default, detail diff, resolve with note;
      financial conflict only offers *accept server*
- [ ] Permissions: a manager without `manage-devices` sees the pages only with
      `device-list`, and all admin writes are refused by `sync_admin`

### Windows hardware verification (cannot run in CI/Linux)

- [ ] `SoftCora-POS-Setup.exe` SmartScreen flow on a clean PC (documented in
      WINDOWS-INSTALLER.md); install without admin rights
- [ ] Desktop + Start Menu shortcuts, Apps & Features entry, uninstall
- [ ] Raw thermal print on the actual 80 mm printer (share → `POS80`, set mode
      `raw`, test receipt, drawer kick if cabled)
- [ ] Power cut mid-sale → next boot intact (repeat 3×)
- [ ] First-run over shop LAN: `SOFTCORA_HOST=0.0.0.0` display on a second device

## 3. Things deliberately not asserted

- SmartDraw/AV heuristics on unsigned SFX (varies by machine) — documented.
- PHP-side PHPUnit runs — the sandbox has no PHP; the contract is instead
  asserted device-side. On a PHP machine: `cd backend && composer test`.
