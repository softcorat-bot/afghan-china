# Troubleshooting — SoftCora POS till

Logs are the first stop. On the till PC: `%LOCALAPPDATA%\SoftCoraPOS\logs\`

| file | what's in it |
|---|---|
| `application.log` | start/stop, settings changes, backups, receipts printed |
| `sync.log` | every sync cycle: uploaded/downloaded/duplicates/conflicts/failed, batch transport failures |
| `error.log` | unhandled request failures, print failures |
| `security.log` | sign-ins (accepted/refused), sign-outs, registration, staff changes, server-side device blocks |
| `till.log` | service stdout (launcher output) |
| `install.log` | every install, update and uninstall: what it checked, what it copied, why it stopped |

In the till screen: *Settings → Who can sign in* aside, signed-in staff can read
the tails at `GET /api/logs/{channel}` (also `SoftCora-POS.exe --cli …`).

---

## "Setup did not finish" (the installer window shows an error)

The installer window and `%LOCALAPPDATA%\SoftCoraPOS\logs\install.log` say the
same thing — the log is what to send to support. A stopped install deletes
nothing and never touches `data\`, so a failed setup cannot lose a sale.

| What it says | Why | What to do |
|---|---|---|
| `Unexpected token ')' in expression or statement.` or `The string is missing the terminator: "` with `install.ps1:NNN char:NNN` | The installer scripts were misread, not misspelled. Windows PowerShell 5.1 reads a `.ps1` that has no byte-order mark with the machine's **ANSI code page**; in UTF-8 a typographic dash (`—`) or arrow (`→`) is three bytes whose cp1252 reading ends in a character PowerShell accepts as a *string delimiter*, so a string closes mid-line and the rest of the file is parsed as code. The line PowerShell reports is never the cause. | Use a build made after the payload check was added (`npm run check:installer` fails such a payload before it is packed, and the shipped `.ps1` files carry a UTF-8 mark). If you edit payload scripts yourself: keep them pure ASCII — write `-` and `->`, never `—` or `→`. |
| `The installer payload is incomplete: there is no SoftCora-POS.exe` | A truncated download, or antivirus removed the program from the folder the setup unpacked into | Verify the file (`certutil -hashfile SoftCora-POS-Setup.exe SHA256` against the release's `sha256.txt`), download it again — the newest build is always at `https://github.com/softcorat-bot/afghan-china/releases/download/latest/Afghan-China-Setup.exe` — and whitelist `%LOCALAPPDATA%\SoftCoraPOS` |
| `SoftCora-POS.exe did not run on this PC (exit N)` | The packaged program was blocked or quarantined, or the PC is not Windows 10/11 x64 | Restore it from quarantine and whitelist the folder; `install.log` carries the program's own message. If the message mentions an assertion (next row), it is not this PC |
| `SoftCora-POS.exe did not run on this PC (exit unknown)` **without** a program message, on a build older than 2026-09-20 | Windows PowerShell 5.1 leaves `ExitCode` empty on the process object `Start-Process -PassThru` returns, and that build read "no exit code" as "the program failed" — so it refused a program that had just answered with its own status. `install.log` shows the program's healthy status right above the error. | This is build 3 of 1.0.0: a packaging fault, not a fault of your PC. Download the setup again from the permanent link and check it against the release's `sha256.txt`. Current builds read the code out of a .NET process object, and cannot refuse a program that answered |
| `SoftCora-POS.exe did not run on this PC (exit A).` with `SeaResource … SeaDeserializer::Read(void) at src\node_sea.cc:172` and `Assertion failed: (format_value) <= (static_cast<uint8_t>(ModuleFormat::kModule))` | The program is a Node **single-executable**: the whole till is embedded in it as a *blob*, and a blob is only readable by the exact Node version that wrote it. This installer's blob was written by a different version than the runtime it was injected into, so the program aborts at startup, before it reads anything. It fails on **every** PC, and nothing was installed. This is a packaging fault, not a fault of your machine — 1.0.0 build 1 was made this way. | Do not retry it here. Download the setup again from the permanent link (`https://github.com/softcorat-bot/afghan-china/releases/download/latest/Afghan-China-Setup.exe`) and check it against that release's `sha256.txt`; a build made after this fault cannot produce it. `install.log` names the runtime the file carries (`payload runtime: node …`), which is the version named in its `RUNTIME.txt`. If you build installers yourself: never let the build host's own Node write the blob — `installer/build-windows.sh` pins one version for both halves, and `installer/verify-windows.ps1` runs the result on Windows before it ships |
| `Drive C has only NNN MB free` | Less than about 300 MB free where the till installs | Free space, run the setup again |
| `port 7817 is already used by another program` (a warning) | Something else on the PC listens on the till's port | The install still finishes. Unzip `SoftCoraPOS-portable-win64.zip` and run `install.cmd -Port 7820` from that folder to move the till |
| `The running till could not be stopped` | A stuck process holds the program files | End `SoftCora-POS.exe` in Task Manager, run the setup again |

## "The Windows verification job failed" (CI, not a shop)

`windows-installer` in `offline-till.yml` takes the installer that run built and
runs it on a Windows runner. A red run is read from the **annotations** on the
run and from the `windows-verification` artifact — not from the job log, which is
not always downloadable. `installer/verify-windows.ps1` annotates every failed
check with what it expected and what it saw, writes the same report as the step
summary, and keeps it as a file (`-Report`) whichever way the run goes; if the
script itself throws, that is reported as a failed check too, with the line it
came from. To reproduce by hand, run the same script on a Windows PC with
`-Installer <setup.exe> -Install` — the report names the check, so the failing
step does not have to be guessed.

Exit codes, if a script drives the setup: `0` installed, `3` installed but not
launched (`-NoLaunch`), `1` failed. `install.cmd` treats anything else as a
failure and pauses with the log's location on screen.

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
