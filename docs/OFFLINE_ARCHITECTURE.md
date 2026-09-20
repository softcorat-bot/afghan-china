# Offline-First Architecture — SoftCora POS (as implemented)

> This document describes the system **as it exists in this repository**. The
> central Laravel side of the same story is covered in [`SYNC_ENGINE.md`](./SYNC_ENGINE.md);
> the audit that produced this architecture is in
> [`OFFLINE_ARCHITECTURE_AUDIT.md`](./OFFLINE_ARCHITECTURE_AUDIT.md).

## 1. The two deployment surfaces

```
                      ┌────────────────────────────────┐
                      │ Central (server)               │
                      │ Laravel 13 API + PostgreSQL     │
                      │ Quasar 2 SPA (online backoffice)│
                      │                                │
                      │ /api/v1/sync/*                  │
                      │ pos_devices · sync_inbox        │
                      │ sync_batches · sync_conflicts   │
                      └───────────────┬────────────────┘
                                      │  push → ACK → pull(cursor) → ack
                            HTTPS • device token
                      ┌───────────────▼────────────────┐
                      │ Windows till (SoftCora-POS.exe) │
                      │ Node single-executable, no deps │
                      │                                │
                      │ till screen (embedded HTML/JS)  │
                      │ local HTTP API  127.0.0.1:7817  │
                      │ SQLite (WAL) + outbox queue     │
                      │ sync engine · backups · logs    │
                      │ receipt printer (dialog/ESC-POS)│
                      └────────────────────────────────┘
```

**The web POS is unchanged.** Backoffice (reports, admin, devices fleet, conflicts)
runs in the browser against Laravel, as before. The desktop `.exe` is the **till**:
a hardened selling terminal that keeps working with the internet off and hands its
work over when connectivity returns. That split is deliberate — the ~60-page SPA was
not rewritten to be offline; a small, fully-tested till surface was built for the
work that must never stop (selling), while everything else stays server-central.

## 2. What the till can do with no network at all

Sign-in (locally hashed credentials), product & barcode search, customer
create/search, **complete sales with multi-tender payment**, device-scoped receipt
numbers, line-level refunds, cash-drawer sessions (open / cash-in-out / X / Z),
local stock deduction through the movement ledger, local reports (summary by
period), backups, and of course switching itself off and on again in between.

Every local write lands in SQLite **in one transaction together with its outbox
row** (`sync_queue`). "It is saved" and "it will reach the server" are the same
statement — there is no state in which a sale exists but the queue does not know.

## 3. Synchronization (device side)

```
Sync Now / auto timer (5 min, configurable)
       │
   1. push   outbox rows in batches of 100 → /api/v1/sync/push
       │     per-change answer: applied | duplicate | conflict | rejected | error
       │     applied/duplicate → queue row becomes `synced` (never retried again
       │                       because re-sending always replays the stored answer)
       │     conflict          → queue row `conflict`, admin decides centrally
       │     rejected          → `failed`, stays visible with the server's reason
       │     transport error   → `failed` + exponential backoff (5s, 20s, 80s … ≤15 min)
       │     successes in the same batch are never rolled back
   2. pull   GET /api/v1/sync/pull?since_seq=<cursor>
       │     rows upserted by uuid; a row with a pending local change is deferred,
       │     never silently overwritten; cursor stored only after rows are applied
   3. ack    cursor reported back for the server's per-device view
   4. conflicts refreshed so admin decisions release local queue rows
```

Connectivity is **measured, not guessed**: the engine probes the server
(`sync/status`) and distinguishes *network down*, *server unreachable*,
*token invalid*, *device blocked* — shown as different till states and logged.

Idempotency is end-to-end: every change carries `change_uuid`; the server's
`sync_inbox` replays the recorded answer for a change it already saw, so a lost
response, a double-clicked Sync Now or a restart mid-sync can never duplicate a sale.

## 4. Reference data vs transactional data

| Data | Flow | Local role |
|---|---|---|
| products, categories, customers (server-created), suppliers, branches, counters | server → till (pull) | cached copy; server is authoritative |
| sales, sale items, payments, refunds, stock movements, cash sessions, expenses, customers created offline | till → server (push) | created locally; server acknowledges/owns after sync |
| users + hashed credentials | cached on till at first online sign-in or added locally | offline authentication only |
| settings (receipt text, printer, sync interval) | local meta table | per-till configuration |

## 5. Files on the till PC (installed layout)

```
%LOCALAPPDATA%\SoftCoraPOS\
├── app\                    program files — replaced on update
│   ├── SoftCora-POS.exe    the whole service + embedded screen
│   ├── start-till.cmd      launcher (binds 127.0.0.1, logs to logs\till.log)
│   └── VERSION.txt
├── data\                   NEVER touched by install/upgrade
│   ├── softcora-pos.sqlite the till's world (WAL journal)
│   ├── device.json         device id + token (0600)
│   └── backups\*.json.gz   manual backups (queue + identity included)
└── logs\
    ├── application.log  sync.log  error.log  security.log   (2 MB × 3 rollover)
    └── till.log         stdout of the service (via start-till.cmd)
```

Bind address: the launcher uses `127.0.0.1` (only this PC). `SOFTCORA_HOST=0.0.0.0`
exposes the till screen to the shop LAN intentionally (e.g. a tablet display) —
the API still requires sign-in for everything except first-run setup.

## 6. Authentication & identity (summary — detail in OFFLINE_SECURITY.md)

- Staff passwords/PINs are stored **only as scrypt hashes** (per-user salt,
  N=16384); verification is timing-safe; sessions expire after 12 h.
- The device's server token is issued once via a one-time activation code shown
  once in the web admin; stored in `device.json` (0600). Rotation endpoint exists.
- Every synchronizable row is keyed by `uuid` + `device_id`; invoice numbers are
  device-scoped (`SC-POS-KBL-8F31A7-000123`) so two tills can never collide.

## 7. Conflict model

Financial records (sales, refunds, sessions) are **append-only**: a conflict on a
financial record can only resolve to *accept server* (+ corrective record), never
"last write wins". Master data conflicts (product price edited on both sides)
offer accept-server / keep-till / merge-safe-fields, decided by an admin in the
web Conflict Center; financial overrides need the extra
`override-financial-sync-conflicts` permission. The till mirrors decisions back.

## 8. Printing (offline)

Two paths, chosen per till in *Settings → Receipt printer*:

| mode | how | when |
|---|---|---|
| `dialog` (default) | formatted 80 mm window → `window.print()` | any printer Windows knows; full Unicode (Dari/Pashto/Chinese) |
| `raw` | ESC/POS bytes → shared Windows printer (`copy /b \\PC\POS80`) | silent thermal printing, no click; ASCII receipts only; optional cash-drawer kick |

A print happens **after** the sale is committed; a printer failure is a screen
message, never a data problem.

## 9. Why not…

- **PWA/service-worker caching of the whole SPA** — cannot commit sales offline
  safely; browser storage is not a transaction engine. The till's SQLite outbox is.
- **Electron/Tauri** — adds 100+ MB and a native build story for zero capability
  this system needs; the Node SEA + embedded screen pattern is ⅓ the size and
  builds on any OS.
- **Shipping the whole Quasar app offline** — rewriting a working online system
  is risk with no business payoff; the till covers the offline-critical subset.
