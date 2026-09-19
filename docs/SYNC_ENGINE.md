# Sync Engine — central side

The web POS, PostgreSQL and the Laravel API are unchanged. This document covers
what was added around them so an **offline Windows till** can keep selling with
no internet and then hand its work to the server, safely.

```
Windows till (SQLite + outbox)  ⇄  Laravel API + PostgreSQL  ⇄  Web POS (unchanged)
        /api/v1/sync/*                     sync_* tables
```

## 1. Identity

Every synchronizable row carries (migration
`2026_09_19_000003_add_sync_identity_columns.php`):

| column | meaning |
|---|---|
| `uuid` | global identity. **Sync never uses the local auto-increment id** — two tills both have a sale with id 17. |
| `revision` | bumped on every server write; a device push states the revision it edited. |
| `sync_seq` | stamp from one global counter (`sync_seq_global`, `sync_touch_row()` triggers on PostgreSQL). This is the cursor devices walk. |
| `origin` | `web` or `offline` — how the row entered the system. |
| `device_id` | which installation created/last touched it (`SC-POS-KBL-8F31A7`). |
| `synced_at` | when a device-originated row landed centrally. |

Devices are registered in `pos_devices` (`2026_09_19_000001…`): `device_id`,
status (`pending → active → disabled|revoked`), `last_seen_at`, `last_sync_at`,
`last_pull_seq` (the cursor), `pending_count`, `failed_count`, `last_error`.

## 2. The outbox protocol

On the till every local write goes into `sync_queue` first:

```
(id, uuid, device_id, entity_type, entity_uuid, operation, payload,
 status ∈ pending|syncing|synced|failed|conflict, attempts, last_attempt_at,
 error_message, created_at, synced_at)
```

A record is **synced only after the server acknowledges it**:

```
push  →  server applies each change in its own transaction and answers per change
      →  device marks applied | duplicate | conflict | rejected | error
      →  pull (cursor)  →  apply locally  →  ack  →  advance last_sync_cursor
```

* `applied` / `duplicate` → the queue row becomes `synced` (a duplicate is a
  success: it means the earlier attempt already landed).
* `conflict` → the queue row becomes `conflict`; the local record is kept.
* `rejected` → `failed`, kept, shown with the server's reason.
* `error` (5xx, timeouts) → `failed` with `attempts + 1` and exponential backoff;
  **successes in the same batch are never rolled back.**

## 3. Idempotency

`sync_inbox` (unique `change_uuid`) stores the result of every processed change,
written **in the same transaction** that applied it. A repeated `Sync Now`, a
lost response, a restart mid-sync or a batch resent after a timeout therefore
replays the stored answer instead of applying anything twice.

Per-change transactions also mean a 97/100 batch keeps its 97 successes: the
device retries only the remainder.

## 4. Pull (incremental, never the whole database)

`GET /api/v1/sync/pull?since_seq=<cursor>&limit=500` walks the one ordered
`sync_seq` stream across the tables the till is allowed to see
(`config/sync.php → pull_tables`), scoped to the device's company and branch.
It answers with `data`, tombstones for deleted rows (`deleted`), `next_cursor`
and `has_more`; the device repeats until `has_more` is false.

The first sync is just a pull from cursor 0 (10,000 rows is normal); after that a
quiet morning moves 17.

## 5. What flows which way

| server → POS (masters) | POS → server (transactions & local creations) |
|---|---|
| products, categories, prices, tax, stock *info*, customers, suppliers, users/roles, counters, branches | sales (+ lines + payments, one atomic change), refunds, stock movements, cash sessions, cash movements, offline customers, offline products, expenses, end-of-day reports |

Stock is never a number pushed in either direction: the till sends **movements**
(delta + reason) and the server applies them under a row lock, keeping
`stock_adjustment` as the ledger. Cash sessions are per device and per cashier
and are never merged.

## 6. Conflicts

`sync_conflicts` holds every disagreement: `severity` (`info|warning|critical`),
`policy`, both payloads, `differing_fields`, `status`
(`pending → accepted_server|kept_local|merged|dismissed`), who resolved it and
why.

Policies (`config/sync.php → conflict_policies`):

| policy | entities | behaviour |
|---|---|---|
| `append_only` | sale, payment, refund, cash session, cash movement, stock movement/adjustment, expense, end-of-day | a duplicate uuid is a replay; the same uuid with **different** content is a `critical` conflict. History is never rewritten. |
| `field_merge` | product, customer | only `merge_fields` are taken from the device; anything else is kept and the conflict is logged. |
| `server_wins` | category, supplier, user | the central copy stays; the device is told to refresh. |

`Server-owned fields` (`stock_qty`, totals, loyalty, ids, timestamps) can never
be set by a device at all.

Resolving: `accepted_server` re-sends the server row so the till visibly corrects
itself; `kept_local` writes the till's version centrally (master data only);
`merged` writes exactly the fields an administrator ticked. Financial conflicts
need `override-financial-sync-conflicts` (super admin / platform owner / VIP) —
otherwise the administrator records a refund or an adjustment, which is how the
business already corrects money.

## 7. API

Device lifecycle:

| method | path | auth |
|---|---|---|
| POST | `/api/v1/sync/register` | activation code (issued once under Settings → Devices) |
| POST | `/api/v1/sync/token/rotate` | device token |

Device sync (Bearer device token + `X-Device-Id`):

| method | path | purpose |
|---|---|---|
| GET | `/api/v1/sync/status` | device state, server seq, cursor, limits, pending conflicts |
| POST | `/api/v1/sync/heartbeat` | app version, pending/failed counts, last error |
| POST | `/api/v1/sync/push` | apply offline work |
| GET | `/api/v1/sync/pull` | incremental changes since a cursor |
| POST | `/api/v1/sync/ack` | cursor + rows the till stored (and any it could not) |
| GET | `/api/v1/sync/conflicts` | this device's conflicts and their resolutions |

The legacy `/api/sync/*` paths are registered from the same definition.

Web administration (session + `sync_admin:…` abilities):

| method | path |
|---|---|
| GET/POST | `/api/devices`, `PUT /api/devices/{device}`, `POST /api/devices/{device}/{enable\|disable\|revoke\|reauthorize}` |
| GET | `/api/sync-conflicts`, `/api/sync-conflicts/pending`, `/api/sync-conflicts/{conflict}` |
| POST | `/api/sync-conflicts/{conflict}/resolve` |
| GET | `/api/sync/overview`, `/api/sync/batches`, `POST /api/sync/prune` |

## 8. Security

* The till authenticates with a device token (`scpos_…`, 32 random bytes, stored
  as sha256), never with a user's password; users still sign in on the till with
  their own credentials, cached locally as a hash.
* Activation codes are single-use and expire; tokens can be rotated; disabling or
  revoking a device is effective on the very next call.
* Every sync request is recorded in `sync_batches`; every decision is recorded in
  the audit log.

## 9. Offline client

`offline/` (Node 22 + `node:sqlite`) implements the till: local SQLite schema,
outbox, sync engine, offline auth (hashed PIN/password, token in a 0600 file),
local reports that mark rows Local / Synced / Pending, and backup/update rules
that never delete the queue, the device id or the database.

The desktop shell (Tauri + Vue 3 + Quasar + TypeScript) and the
`SoftCora-POS-Setup.exe` installer are packaging work that sits on top of the same
service; nothing in the sync design depends on the shell.
