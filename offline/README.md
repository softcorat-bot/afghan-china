# SoftCora POS — Offline till

The web POS stays exactly where it is. This is the second deployment mode the
shop asked for: a till that keeps selling when the internet does not.

```
Windows till (this service)              Central (unchanged)
┌──────────────────────────────┐         ┌───────────────────────────────┐
│ till screen (browser)        │         │ Laravel API + PostgreSQL      │
│ local HTTP API  :7817        │  ⇄      │ /api/v1/sync/*                │
│ SQLite + outbox (sync_queue) │         │ web POS, reports, settings    │
└──────────────────────────────┘         └───────────────────────────────┘
```

Nothing the shop does depends on the network. Sign-in, search, barcode scan,
sales, payments, receipts, returns, customers, stock movements, drawer sessions,
reports and backups all run against the local SQLite database; sync is what
*hands the work over*, never what makes it possible.

## Run it

```bash
cd offline
npm start                      # the till screen on http://localhost:7817
```

Node 22.5+ is required (the till uses Node's built-in SQLite — no native build
step, no external services).

## Set it up (once, per till)

A till that has just been started has no sign-in yet, so the screen opens on a
**setup panel** instead of a sign-in box: it creates the first staff sign-in and
offers to register the till. Both are optional to begin with — the till sells
with no server at all, and registration can be done later under
*Settings → Connect this till*.

1. **On the server** — an administrator opens *Settings → Devices*, adds the till
   and reads out the one-time activation code.
2. **On the till** — the setup panel asks for that code, the server address and
   the first sign-in. Under the hood, or from a terminal:

```bash
node src/cli.mjs init --device SC-POS-KBL-8F31A7 --server https://pos.shop.af   # optional
node src/cli.mjs register --code ABCD-1234        # exchanges the code for a device token
node src/cli.mjs staff --user cashier@shop.af --password '…' --pin 1111
npm start
```

A device id is generated the first time the database is opened, so no till can
ring a sale before it knows which till it is. `staff` caches the sign-in
credential **as a scrypt hash** — the till never stores a password or PIN in
clear text, and a wrong password is refused offline.

## What the till keeps

| | |
|---|---|
| `data/softcora-pos.sqlite` | everything: catalogue, customers, sales, drawer sessions, stock ledger, outbox |
| `data/device.json` | the device id + token (0600; the desktop shell swaps this for the OS keychain) |
| `data/backups/*.json.gz` | backups that include the unsynced queue, the device identity and the configuration |

## Sync

The **Sync Now** button (and `node src/cli.mjs sync`) runs the whole cycle:

```
push outbox → server ACK per change → pull from the stored cursor →
apply locally → ACK the cursor → Sync Status updated
```

* a record is marked `synced` **only** after the server acknowledges it;
* a re-sent record returns the server's earlier answer — no duplicate sales, even
  when a response is lost, the app restarts, or Sync Now is pressed twice;
* a failed record stays in the outbox with an exponential backoff (a manual Sync
  Now retries immediately, the automatic timer respects the backoff);
* a partial batch keeps its successes — the till retries only the remainder;
* nothing is ever deleted from the queue because syncing failed.

States shown in the header and in *Synchronization*: Offline, Online, Syncing…,
Synced, Pending, Failed, Conflict — with the last sync time, pending and failed
counts, an upload/download progress line and a result summary.

## Conflicts

The screen's *Conflicts* tab mirrors the server's Conflict Center: the entity, the
local and the server value, the differing fields, when it was detected and what an
administrator decided. A till never overwrites a financial record on its own.

## Commands

| command | what it does |
|---|---|
| `node src/cli.mjs init --device <id> --server <url>` | set this installation's identity |
| `node src/cli.mjs register --code <code>` | exchange the activation code for a device token |
| `node src/cli.mjs staff --user <email> --password\|--pin <secret>` | allow offline sign-in for a user |
| `node src/cli.mjs serve [--port 7817]` | run the till screen |
| `node src/cli.mjs sync \| status \| queue \| conflicts` | sync, inspect the outbox, look at conflicts |
| `node src/cli.mjs retry [--id <n>]` | retry failed changes (all, or one) |
| `node src/cli.mjs backup` / `restore --file <backup>` | backup / restore, queue included |
| `node src/cli.mjs verify` | refuse an update that would drop the queue, the device id or the database (JSON: the Windows installer parses it before replacing anything) |
| `npm test` | end-to-end test against a server that speaks the sync contract, plus the installer's own contracts |
| `npm run check:installer` | prove the Windows payload is ASCII, CRLF and parses however Windows reads it |
| `npm run build:windows` | build `dist/SoftCora-POS-Setup.exe`, the portable zip and the checksums |

## Tests

`test/e2e.mjs` runs the real engine against a central server implementing the
documented protocol (idempotency ledger, one row per entity uuid, cursor pull).
It covers: 20 offline sales + 5 customers + 3 returns + stock movements + two
drawer sessions; a restart; a dead server; activation; a full sync; repeated
syncing with no duplicates; a lost response; a rejected change that keeps its
place; incremental pulls; backup and restore; two tills selling at once;
channelled log files that provably contain no secrets; and receipt printing
(text + ESC/POS rendering, honest failure modes, a failed print never touching
a sale).

```
npm test                 # 18/18 engine checks, then 11/11 installer checks
npm run check:installer  # the Windows payload, on its own
```

`test/installer.mjs` covers what cannot be run here: the payload passes
`installer/check-payload.mjs`, and that check *fails* on the bug it was written
for (a typographic dash in a `.ps1`, which Windows PowerShell 5.1 misreads into
a string delimiter); what the build ships is CRLF, ASCII, marked `.ps1` and
unmarked `.cmd`; the promises `install.ps1` makes about the data folder, the
port and its exit codes are still in the script; the launchers set only
environment variables something reads; and `--cli verify` prints the JSON the
installer parses.

## Logs

Four channelled log files live next to the data directory
(`%LOCALAPPDATA%\SoftCoraPOS\logs` when installed): `application.log`,
`sync.log`, `error.log`, `security.log` — 2 MB each with three generations of
rollover, and no secrets ever written (`test/e2e.mjs` proves a known password
never appears). Signed-in staff can read tails at `GET /api/logs/{channel}`.

## Printing

Receipts print offline, two ways (*Settings → Receipt printer*):

- **Browser print dialog** (default) — a formatted 80 mm window; any printer
  Windows knows, full Unicode (Dari/Pashto receipts).
- **Silent thermal (Windows)** — ESC/POS bytes to a shared printer
  (`printer_share`, e.g. `POS80`), no dialog, optional cash-drawer kick; ASCII
  receipts. `POST /api/print/receipt` / `POST /api/print/test`.

A sale is committed before printing is ever attempted — printer faults are
screen messages, never data problems.

## Documentation

Full production docs live in the repository root `docs/`: `OFFLINE_ARCHITECTURE.md`,
`OFFLINE_DATABASE.md`, `SYNC_ENGINE.md`, `OFFLINE_SECURITY.md`,
`OFFLINE_TESTING.md`, `INSTALLATION.md`, `TROUBLESHOOTING.md`, `RELEASE.md`.

## Desktop packaging

The service is deliberately shell-agnostic: the till screen is plain HTML/JS
served locally, and every operation is an HTTP call on `127.0.0.1`. The Windows
deliverable is a **Node single-executable** (no wrapper runtime): the service
plus the embedded screen inside `SoftCora-POS.exe`. Whatever shell packages it
must obey three rules the code already enforces: never recreate the SQLite
file, never drop `sync_queue`, never change the device id.
`node src/cli.mjs verify` is the gate an installer runs before replacing files.

`installer/build-windows.sh` produces **dist/SoftCora-POS-Setup.exe** — a real
self-extracting installer (per-user, no admin, shortcuts, Apps & Features
entry, data-preserving upgrades, uninstall) plus a portable zip and checksums.
The same bytes are also written as **dist/Afghan-China-Setup.exe**, the name
the permanent download link serves:

**https://github.com/softcorat-bot/afghan-china/releases/download/latest/Afghan-China-Setup.exe**

— refreshed by every publish run (`.github/workflows/publish-release-assets.yml`
moves the `latest` tag and clobbers the assets, so the link always serves the
newest installer).

The installer scripts run only on Windows, so what breaks them is checked here
instead: `installer/check-payload.mjs` proves the payload is ASCII, CRLF, marked
the way each host expects, and that every `.ps1` still has balanced quoting when
it is read with the ANSI code page Windows PowerShell 5.1 falls back to. The
build runs it before it compiles anything and again on the payload it packs, and
`.github/workflows/offline-till.yml` has Windows PowerShell 5.1 itself parse and
run the scripts on every change. Details, including the field failure this
exists for: [docs/WINDOWS-INSTALLER.md](docs/WINDOWS-INSTALLER.md).
