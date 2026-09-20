# Offline Database — the till's SQLite (as implemented)

## 1. Engine & location

- **SQLite via Node's built-in `node:sqlite`** (`DatabaseSync`) — no native addon,
  no separate server process, no npm dependency. Minimum runtime: Node 22.5,
  which is exactly what the executable embeds.
- Installed location: `%LOCALAPPDATA%\SoftCoraPOS\data\softcora-pos.sqlite`
  (`SOFTCORA_DATA` overrides). Dev checkout: `./data/softcora-pos.sqlite`.
- PRAGMAs at open: `journal_mode = wal` (crash-safe concurrent reads),
  `foreign_keys = on`, `busy_timeout = 5000`.

## 2. Schema management & versioning

There is no external migration runner (deliberate — the till is one executable).
`src/db.mjs` holds the full schema as idempotent `create table if not exists`
DDL plus additive indexes, applied at every open — a fresh till and a years-old
one converge to the same structure. `meta.schema_version` records the level
(`SCHEMA_VERSION = 1`). A breaking change in future means: bump
`SCHEMA_VERSION`, add an idempotent migration step keyed on the **current**
meta value, keep the upgrade path in the same file so every release can read
every older database. Downgrade = restore a backup (the installer never deletes
data, so the previous `data\` folder is still valid).

**Never destructive**: no `drop table`, no column drops, no type rewrites.
Updates of the application never touch `data\` at all (see `installer/payload/install.ps1`
and `backup.mjs → assertUpgradeSafe()`).

## 3. Tables (and why each exists)

| table | purpose | sync direction |
|---|---|---|
| `meta` | key/value: `device_id`, `server_url`, cursors (`last_sync_cursor`), settings (`auto_sync*`, `printer_*`, `receipt_*`), `schema_version`, connectivity | local only |
| `users` | staff allowed to sign in offline. Holds **scrypt hashes only** (`password_hash`, `pin_hash`), role, permissions JSON, active flag | cached (server → till at online sign-in; provisionable locally) |
| `sessions` | till sessions: `token_hash` (sessions are themselves hashed), user, expiry (12 h) | local only |
| `categories`, `products`, `customers`, `suppliers`, `counters`, `branches` | reference data for selling; `uuid` is the identity; `sync_state` tracks pending/conflict/synced | pull (cache) — products/customers can also be created offline → push |
| `cash_sessions`, `cash_movements` | drawer shifts: float, ins/outs, expected vs counted, variance, totals per tender | push |
| `sales` | one row per committed sale: device-scoped `device_invoice_no` (unique), `server_invoice_no` back-filled after sync, totals, `paid`, `change_due`, `refunded_amount`, status, `sync_state` | push |
| `sale_items` | lines with `product_uuid` (identity stable across devices), price/cost/qty/discount/`line_total`, `refunded_qty` | inside sale push |
| `sale_payments` | tenders: method (`cash\|card\|mobile\|credit`), amount, reference | inside sale push |
| `refunds`, `refund_items` | append-only returns, restock-aware | push |
| `stock_movements` | **the inventory ledger**: increase/decrease + reason + `ref_type/ref_uuid`. Stock = sum(movements); offline sales post `decrease` immediately | push |
| `expenses` | till-level petty expenses | push |
| `sync_queue` (the outbox) | one row per unacknowledged local write: `change_uuid`, entity, operation, `payload` JSON, `status (pending\|syncing\|synced\|failed\|conflict)`, `attempts`, `next_attempt_at` (backoff), `error_message`, `batch_uuid` | drive of the sync engine |
| `sync_log` | every sync cycle with counts and duration — powers the Sync Status panel truthfully | local only |
| `conflicts` | mirror of the server's conflict center (decisions flow down) | pull |

Indexes: barcode/name search on `products`, phone on `customers`,
`sync_queue(status)` and `(entity_type, entity_uuid)`, `sales(sync_state)`,
`sales(sold_at)`, `sale_items(sale_id)`, `conflicts(status)`.

## 4. Transaction rules (crash safety)

`db.mjs → transaction(db, fn)` wraps every multi-row write in
`begin immediate … commit`, `rollback` on any throw. A sale commits:
sale + items + payments + stock movements + **outbox row** as one unit.
There is no possible database state with a sale but no queue entry, a payment but
no sale, or a movement but no stock history. WAL mode makes a mid-write power cut
a rollback, not corruption.

## 5. Stock consistency

Stock is never a pushed number. Local sale → local `stock_movements` row
(`decrease`) in the same transaction; on sync the movement reaches the server's
ledger; the server's pull brings **server-computed `stock_qty`** back as cache —
which is safe because a product with a pending local change is deferred, never
overwritten (see `sync/apply.mjs → hasLocalPendingChange`).

## 6. Backups & restore

`POST /api/backup` (or `cli.mjs backup`) writes
`data\backups\softcora-pos-<ISO>-<label>.json.gz`: all tables, the **unsynced
queue**, device credentials, meta/config, counts and a manifest. Restore
(`cli.mjs restore --file …`) re-inserts everything into a fresh database and the
till picks up exactly where it left off — pending sales included. Verified by the
e2e step "a backup carries the unsynced queue and a restore keeps it".

Backups are ordinary files; unzipping one is a support action, not a UI feature
(the gunzip command is in `TROUBLESHOOTING.md`). Automatic backup cadence is a
shop policy (recommendation: daily via Task Scheduler → `SoftCora-POS.exe --cli backup`).

## 7. Space & housekeeping

- Two 8-hour busy days ≈ a few MB; log rollover caps logs at 4 × 2 MB × 3.
- `sync_queue` keeps synced history for audit; periodic prune of the **server**
  idempotency ledger is an admin action (*Settings → Synchronization → Prune*).
- SQLite auto-checkpoint handles WAL growth; no VACUUM scheduled (documented
  optional maintenance: `SoftCora-POS.exe --cli backup` then fresh restore).
