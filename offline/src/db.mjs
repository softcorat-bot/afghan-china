/**
 * The till's own database.
 *
 * Everything the shop needs to keep trading with the internet off lives here:
 * the catalogue, the customers, the cash sessions, the sales, and — most
 * importantly — the outbox (`sync_queue`) that remembers every local write until
 * the central server has acknowledged it.
 *
 * Two rules this file exists to enforce:
 *   1. Sales are written in a transaction together with their stock movements
 *      and their outbox row. A sale that is in the database is in the queue.
 *   2. Nothing here is ever deleted because syncing failed. Updates to this
 *      installation must never drop the queue, the device identity or the file.
 */
import { DatabaseSync } from 'node:sqlite';
import fs from 'node:fs';
import path from 'node:path';

import { suggestDeviceId } from './ids.mjs';

export const SCHEMA_VERSION = 1;

const SCHEMA = `
-- Key/value store for identity and cursors: device_id, branch, last_sync_cursor…
create table if not exists meta (
  key   text primary key,
  value text
);

-- Staff who may sign in on this till, with credentials hashed locally.
-- No plaintext password or PIN is ever written to this database.
create table if not exists users (
  id            integer primary key autoincrement,
  uuid          text unique,
  name          text not null,
  username      text,
  email         text,
  role          text,
  permissions   text,               -- json array
  pin_hash      text,
  password_hash text,
  active        integer default 1,
  last_login_at text,
  updated_at    text
);

create table if not exists sessions (
  token_hash text primary key,
  user_id    integer not null,
  created_at text,
  expires_at text,
  device_id  text
);

create table if not exists categories (
  id   integer primary key autoincrement,
  uuid text unique,
  name text,
  sync_state text default 'synced'
);

create table if not exists products (
  id              integer primary key autoincrement,
  uuid            text unique,
  name            text not null,
  name_fa         text,
  sku             text,
  barcode         text,
  category_id     integer,
  unit            text default 'pcs',
  cost_price      real default 0,
  sale_price      real default 0,
  wholesale_price real,
  tax_rate        real default 0,
  track_inventory integer default 1,
  stock_qty       real default 0,
  min_stock       real default 0,
  active          integer default 1,
  revision        integer default 1,
  sync_state      text default 'synced',
  updated_at      text
);
create index if not exists products_barcode_idx on products(barcode);
create index if not exists products_name_idx on products(name);

create table if not exists customers (
  id            integer primary key autoincrement,
  uuid          text unique,
  name          text not null,
  phone         text,
  email         text,
  address       text,
  total_spent   real default 0,
  orders_count  integer default 0,
  loyalty_points integer default 0,
  revision      integer default 1,
  sync_state    text default 'synced',
  updated_at    text
);
create index if not exists customers_phone_idx on customers(phone);

create table if not exists suppliers (
  id integer primary key autoincrement,
  uuid text unique,
  name text,
  phone text,
  sync_state text default 'synced'
);

create table if not exists counters (
  id integer primary key autoincrement,
  uuid text unique,
  name text,
  branch_id integer,
  sync_state text default 'synced'
);

create table if not exists branches (
  id integer primary key autoincrement,
  uuid text unique,
  name text,
  code text,
  sync_state text default 'synced'
);

-- Cashier sessions. A session belongs to one device and one cashier; two
-- independent sessions are never merged, here or centrally.
create table if not exists cash_sessions (
  id            integer primary key autoincrement,
  uuid          text unique,
  user_id       integer,
  counter_id    integer,
  opened_at     text,
  closed_at     text,
  opening_float real default 0,
  counted_cash  real,
  expected_cash real,
  variance      real,
  cash_sales    real default 0,
  card_sales    real default 0,
  mobile_sales  real default 0,
  cash_in       real default 0,
  cash_out      real default 0,
  total_sales   real default 0,
  orders_count  integer default 0,
  status        text default 'open',
  note          text,
  sync_state    text default 'pending'
);

create table if not exists cash_movements (
  id         integer primary key autoincrement,
  uuid       text unique,
  session_id integer not null,
  user_id    integer,
  type       text,                  -- in | out
  amount     real,
  reason     text,
  created_at text,
  sync_state text default 'pending'
);

create table if not exists sales (
  id             integer primary key autoincrement,
  uuid           text unique,
  device_invoice_no text unique,    -- what the receipt shows: DEVICE-000123
  server_invoice_no text,
  server_id      integer,
  session_id     integer,
  counter_id     integer,
  customer_id    integer,
  user_id        integer,
  sold_at        text,
  captured_at    text,
  subtotal       real default 0,
  discount       real default 0,
  tax            real default 0,
  total          real default 0,
  paid           real default 0,
  change_due     real default 0,
  refunded_amount real default 0,
  status         text default 'completed',
  note           text,
  sync_state     text default 'pending',
  synced_at      text
);
create index if not exists sales_sync_state_idx on sales(sync_state);
create index if not exists sales_sold_at_idx on sales(sold_at);

create table if not exists sale_items (
  id           integer primary key autoincrement,
  uuid         text unique,
  sale_id      integer not null,
  product_id   integer,
  product_uuid text,
  name         text,
  barcode      text,
  unit_price   real,
  cost_price   real,
  qty          real,
  discount     real default 0,
  tax          real default 0,
  line_total   real,
  refunded_qty real default 0
);
create index if not exists sale_items_sale_idx on sale_items(sale_id);

create table if not exists sale_payments (
  id        integer primary key autoincrement,
  uuid      text unique,
  sale_id   integer not null,
  method    text,
  amount    real,
  reference text
);

create table if not exists refunds (
  id          integer primary key autoincrement,
  uuid        text unique,
  sale_id     integer,
  user_id     integer,
  amount      real,
  reason      text,
  captured_at text,
  sync_state  text default 'pending'
);

create table if not exists refund_items (
  id           integer primary key autoincrement,
  refund_id    integer not null,
  sale_item_id integer,
  product_uuid text,
  qty          real,
  amount       real
);

-- Stock is a ledger, never a pushed number: opening, purchase, sale, return,
-- adjustment. Current stock is the sum of these movements.
create table if not exists stock_movements (
  id           integer primary key autoincrement,
  uuid         text unique,
  product_id   integer,
  product_uuid text,
  type         text,               -- increase | decrease
  qty          real,
  reason       text,
  note         text,
  user_id      integer,
  ref_type     text,
  ref_uuid     text,
  created_at   text,
  sync_state   text default 'pending'
);

create table if not exists expenses (
  id         integer primary key autoincrement,
  uuid       text unique,
  user_id    integer,
  spent_on   text,
  category   text,
  payee      text,
  amount     real,
  method     text,
  reference  text,
  note       text,
  sync_state text default 'pending'
);

/*
 * The outbox. One row per local write that the server has not acknowledged.
 * entity_uuid is the identity the server knows; the local auto-increment id is
 * never used for synchronization.
 */
create table if not exists sync_queue (
  id             integer primary key autoincrement,
  uuid           text unique,
  device_id      text,
  entity_type    text not null,
  entity_uuid    text not null,
  operation      text default 'create',
  payload        text not null,
  status         text default 'pending',   -- pending|syncing|synced|failed|conflict
  attempts       integer default 0,
  last_attempt_at text,
  next_attempt_at text,
  error_message  text,
  server_id      text,
  conflict_id    integer,
  batch_uuid     text,
  created_at     text,
  synced_at      text
);
create index if not exists sync_queue_status_idx on sync_queue(status);
create index if not exists sync_queue_entity_idx on sync_queue(entity_type, entity_uuid);

-- Every sync attempt, so the Sync Status panel can show real history instead of
-- a spinner that lies.
create table if not exists sync_log (
  id          integer primary key autoincrement,
  at          text,
  direction   text,                -- push | pull | full
  status      text,                -- ok | partial | failed | offline
  uploaded    integer default 0,
  downloaded  integer default 0,
  duplicates  integer default 0,
  conflicts   integer default 0,
  failed      integer default 0,
  message     text,
  duration_ms integer
);

-- Mirror of the server's conflict centre: what a device raised, and what an
-- administrator decided about it.
create table if not exists conflicts (
  id                integer primary key autoincrement,
  server_conflict_id integer unique,
  entity_type       text,
  entity_uuid       text,
  severity          text,
  policy            text,
  status            text default 'pending',
  reason            text,
  local_payload     text,
  server_payload    text,
  differing_fields  text,
  detected_at       text,
  resolved_at       text,
  resolution_note   text,
  sync_state        text
);
create index if not exists conflicts_status_idx on conflicts(status);
`;

/** Open (and initialise) the till database. */
export function openDatabase(file = process.env.SOFTCORA_DB || defaultDbPath()) {
  fs.mkdirSync(path.dirname(file), { recursive: true });

  const db = new DatabaseSync(file);
  db.exec('pragma journal_mode = wal');
  db.exec('pragma foreign_keys = on');
  db.exec('pragma busy_timeout = 5000');
  db.exec(SCHEMA);

  const current = Number(getMeta(db, 'schema_version') || 0);
  if (current < SCHEMA_VERSION) {
    setMeta(db, 'schema_version', String(SCHEMA_VERSION));
  }

  // A till gets its own identity the first time it starts, before it has rung
  // anything: receipt numbers are device-scoped, so a sale must never be
  // numbered before the device knows which device it is. Registration with the
  // server later keeps this id, it does not replace it.
  if (!getMeta(db, 'device_id')) {
    setMeta(db, 'device_id', suggestDeviceId(getMeta(db, 'branch_code')));
  }

  return db;
}

/**
 * Where the till keeps its database.
 *
 * Installed on Windows it must not depend on the working directory of a
 * shortcut — that would create a second, empty database and look exactly like
 * lost data. The profile folder is the one place an update never touches.
 */
export function defaultDataDir() {
  if (process.env.SOFTCORA_DATA) return process.env.SOFTCORA_DATA;

  if (process.platform === 'win32' && process.env.LOCALAPPDATA) {
    return path.join(process.env.LOCALAPPDATA, 'SoftCoraPOS', 'data');
  }

  return path.join(process.cwd(), 'data');
}

export function defaultDbPath() {
  return path.join(defaultDataDir(), 'softcora-pos.sqlite');
}

export function getMeta(db, key, fallback = null) {
  const row = db.prepare('select value from meta where key = ?').get(key);
  return row ? row.value : fallback;
}

export function setMeta(db, key, value) {
  db.prepare('insert into meta (key, value) values (?, ?) on conflict(key) do update set value = excluded.value')
    .run(key, value === null || value === undefined ? null : String(value));
}

export function metaNumber(db, key, fallback = 0) {
  const value = getMeta(db, key);
  return value === null ? fallback : Number(value);
}

/** Run a function inside a transaction; rolls back on any throw. */
export function transaction(db, fn) {
  db.exec('begin immediate');
  try {
    const result = fn();
    db.exec('commit');
    return result;
  } catch (error) {
    try { db.exec('rollback'); } catch { /* the original error matters more */ }
    throw error;
  }
}
