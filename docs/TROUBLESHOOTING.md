# Troubleshooting — SoftCora POS till

Logs are the first stop. On the till PC: `%LOCALAPPDATA%\SoftCoraPOS\logs\`

| file | what's in it |
|---|---|
| `application.log` | start/stop, settings changes, backups, receipts printed |
| `sync.log` | every sync cycle: uploaded/downloaded/duplicates/conflicts/failed, batch transport failures |
| `error.log` | unhandled request failures, print failures |
| `security.log` | sign-ins (accepted/refused), sign-outs, registration, staff changes, server-side device blocks |
| `till.log` | service stdout (launcher output) |

In the till screen: *Settings → Who can sign in* aside, signed-in staff can read
the tails at `GET /api/logs/{channel}` (also `SoftCora-POS.exe --cli …`).

---

## "The till will not start"

1. Open `logs\application.log`: is there a `till started` line? If none, run
   `app\start-till.cmd` by hand and read the console.
2. **Port busy (EADDRINUSE on 7817):** another copy is running — check the
   notification area/Task Manager for `SoftCora-POS.exe`. Only one till per PC.
3. **Database locked:** a crashed previous process can hold the WAL for a few
   seconds; retry after 30 s. If it persists, reboot — never delete the
   `*.sqlite-wal`/`-shm` files by hand.
4. Antivirus quarantined the exe → restore it from quarantine and whitelist the
   `SoftCoraPOS` folder (unsigned-build reality, see RELEASE.md).

## "Nobody can sign in"

- New install? The first-run setup panel creates the first sign-in.
- Wrong-credentials loop: a refused attempt is in `security.log` with the
  identifier used — check for typos before assuming the worst.
- Hashed cache is intact — do **not** delete `data\users` rows by hand.
  Re-provision the user: sign in as a cached manager → *Settings → Who can sign
  in* → re-add them (new password/PIN), or have them sign in once while online.
- Forgotten *everyone*: restore the site admin from the **server** side
  (they re-sign online and are re-cached), or as last resort restore a backup
  (`--cli restore --file …`) — never edit hashes directly.

## "Stuck on Offline / changes not syncing"

Read the state badge — it is honest:

- **Offline (grey)** — the server probe failed. `sync.log` + connectivity detail:
  - *No internet connection* → shop network/VPN is actually down; keep selling,
    it recovers by itself.
  - *Could not reach the server* → check `server_url` in Settings (typo,
    moved domain, server down, firewall).
  - *Not authorized any more (401)* → **device was revoked/disabled** (or token
    rotated elsewhere): web admin → *Settings → Devices* → Enable or
    Re-authorize, then Sync Now on the till.
  - *Device blocked (403)* → administrator action required; the till keeps
    selling and keeps everything queued.
- **Pending (amber)** — normal: there is work queued, it is waiting for its
  next cycle (backoff after failures; *Sync Now* forces an immediate run).
- **Failed (red)** — open *Synchronization → Queue*: read the server's reason
  per row. Fix the cause (e.g. unknown customer reference → create/pull the
  customer) then **Retry**. Failed rows are never discarded silently.
- **Conflict (orange)** — the server disagreed with a change. Resolution
  happens in the **web Conflict Center** (*Settings → Sync Conflicts*); the
  till adopts the decision on its next sync. Cashiers can't resolve conflicts.

## "Sync runs but numbers look wrong"

- `device_invoice_no` vs the server's invoice: they are different namespaces by
  design (device-scoped first; the server assigns its own on acceptance — both
  are stored and searchable).
- Stock: the till's `stock_qty` is a cache of the movement ledger; the server's
  ledger wins after sync. A discrepancy almost always means an unsynced
  movement (*Synchronization → Queue* filters `stock_movement`).

## "The printer doesn't print"

- *Dialog mode*: the print window must be allowed — Chrome/Edge **pop-up
  blocker** is the #1 cause; allow pop-ups for `127.0.0.1:7817`. Choose the
  right printer in the dialog; paper size 80 mm.
- *Raw mode*: Settings shows the exact failure.
  - `No printer share name configured` → set it (Windows printer → Sharing).
  - `The printer did not take the receipt` → share name wrong, printer
    offline/out of paper, or the spooler service down (`services.msc → Print
    Spooler`). Try `copy /b test.txt \\%COMPUTERNAME%\POS80` in cmd yourself —
    if that fails, Windows printing (not the till) is the problem.
  - *Silent printing needs Windows* → you're on a non-Windows host; use dialog
    mode.
  - Dari/Pashto names show as `????` in raw mode — expected (ESC/POS ASCII);
    use dialog mode for Dari receipts.
- Receipts print but the **drawer doesn't open**: enable *open the cash drawer*
  in Settings and confirm the drawer is wired to the printer (RJ-11), not USB.

## "After an update, my sales seem missing"

They aren't — the installer never touches `data\`. The classic cause is the
shortcut starting with a different data directory (portable zip next to a
different folder, or `SOFTCORA_DATA` set to an old path).
`app\verify.ps1` confirms where the live database is and that the queue/device
identity survived. If genuinely empty: stop the till, find `softcora-pos.sqlite`
under `%LOCALAPPDATA%\SoftCoraPOS\data`, and point `start-till.cmd`'s
`SOFTCORA_DATA` at it.

## "I need to move a till to another PC"

1. On the old PC: sync (all green), then *Backup*.
2. Copy the newest `data\backups\*.json.gz` to the new PC.
3. Install the till; do **not** register with a new identity — restore:
   `SoftCora-POS.exe --cli restore --file <backup.json.gz>` (identity and
   pending queue travel inside).
4. Web admin → Devices: the old device_id shows on the new PC after first sync.
   Retire the old PC (Disable) when confirmed.

## Unzipping a backup for support

```powershell
tar -xzf softcora-pos-2026-09-20-…-manual.json.gz   # Windows 10+ ships tar
```

You'll get one JSON document: tables, pending queue, device identity, manifest.
Hand it to support; don't hand around freely (it contains customer data).

## Getting support

Collect: `logs\` (all files), the device id, the last good sync time, what the
screen badge says, and the server admin's view (*Settings → Synchronization*).
That package answers 95% of cases without a site visit.
