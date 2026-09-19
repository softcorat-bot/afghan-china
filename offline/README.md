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
| `node src/cli.mjs verify` | refuse an update that would drop the queue, the device id or the database |
| `npm test` | end-to-end test against a server that speaks the sync contract |

## Tests

`test/e2e.mjs` runs the real engine against a central server implementing the
documented protocol (idempotency ledger, one row per entity uuid, cursor pull).
It covers: 20 offline sales + 5 customers + 3 returns + stock movements + two
drawer sessions; a restart; a dead server; activation; a full sync; repeated
syncing with no duplicates; a lost response; a rejected change that keeps its
place; incremental pulls; backup and restore; and two tills selling at once.

```
npm test     # 14/14 checks
```

## Desktop packaging

The service is deliberately shell-agnostic: the till screen is plain HTML/JS
served locally, and every operation is an HTTP call on `127.0.0.1`. The Windows
build (Tauri + Vue 3 + Quasar + TypeScript, or any wrapper) supplies the window,
the printer and the updater, and must obey three rules the code already enforces:
never recreate the SQLite file, never drop `sync_queue`, never change the device
id. `node src/cli.mjs verify` is the gate an installer runs before replacing
files.
