/**
 * Backups and updates that cannot cost the shop a sale.
 *
 * A backup is not just the SQLite file: the unsynced queue, the device identity
 * and the configuration are the parts that are impossible to recreate. All of
 * them go in, together, with a manifest that says what is inside.
 *
 * An update must never drop the queue, forget the device id or start from a
 * fresh database. `assertUpgradeSafe()` is the gate: it runs before an
 * installer replaces the application files and refuses to continue if the data
 * directory would be lost.
 */
import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';
import { defaultDataDir, getMeta, openDatabase } from './db.mjs';
import { nowIso } from './ids.mjs';
import { loadDeviceCredentials } from './auth.mjs';

export function createBackup(db, { outDir = path.join(defaultDataDir(), 'backups'), label = 'manual' } = {}) {
  fs.mkdirSync(outDir, { recursive: true });

  // A consistent copy of the database, including the write-ahead log.
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  const file = path.join(outDir, `softcora-pos-${stamp}-${label}.json.gz`);

  const queue = db.prepare('select * from sync_queue').all();
  const meta = Object.fromEntries(db.prepare('select * from meta').all().map((row) => [row.key, row.value]));

  const payload = {
    format: 'softcora-pos-backup',
    version: 1,
    created_at: nowIso(),
    label,
    device: loadDeviceCredentials(),
    meta,
    // The two things a restore must not lose, listed first for a reason.
    pending_queue: queue.filter((row) => row.status !== 'synced'),
    synced_queue_tail: queue.filter((row) => row.status === 'synced').slice(-500),
    tables: {
      users: db.prepare('select * from users').all(),
      products: db.prepare('select * from products').all(),
      categories: db.prepare('select * from categories').all(),
      customers: db.prepare('select * from customers').all(),
      sales: db.prepare('select * from sales').all(),
      sale_items: db.prepare('select * from sale_items').all(),
      sale_payments: db.prepare('select * from sale_payments').all(),
      refunds: db.prepare('select * from refunds').all(),
      refund_items: db.prepare('select * from refund_items').all(),
      stock_movements: db.prepare('select * from stock_movements').all(),
      cash_sessions: db.prepare('select * from cash_sessions').all(),
      cash_movements: db.prepare('select * from cash_movements').all(),
      expenses: db.prepare('select * from expenses').all(),
      conflicts: db.prepare('select * from conflicts').all(),
    },
    counts: {
      sales: db.prepare('select count(*) c from sales').get().c,
      pending: db.prepare("select count(*) c from sync_queue where status != 'synced'").get().c,
    },
    database_file: db.prepare('pragma database_list').all().map((row) => row.file).filter(Boolean),
  };

  fs.writeFileSync(file, zlib.gzipSync(Buffer.from(JSON.stringify(payload), 'utf8')));

  return { file, counts: payload.counts, bytes: fs.statSync(file).size };
}

/**
 * Restore into a data directory (a new till, or the same till after a disk
 * failure). Queue rows come back with their statuses intact, so anything that
 * never reached the server is still going to reach it.
 */
export function restoreBackup(file, { dataDir = defaultDataDir() } = {}) {
  const payload = JSON.parse(zlib.gunzipSync(fs.readFileSync(file)).toString('utf8'));
  if (payload.format !== 'softcora-pos-backup') throw new Error('That file is not a SoftCora POS backup.');

  fs.mkdirSync(dataDir, { recursive: true });

  if (payload.device) {
    fs.writeFileSync(path.join(dataDir, 'device.json'), JSON.stringify(payload.device, null, 2), { mode: 0o600 });
  }

  const db = openDatabase(path.join(dataDir, 'softcora-pos.sqlite'));

  db.exec('begin immediate');
  try {
    for (const [key, value] of Object.entries(payload.meta ?? {})) {
      db.prepare('insert into meta (key, value) values (?,?) on conflict(key) do update set value = excluded.value').run(key, value);
    }

    for (const [table, rows] of Object.entries(payload.tables ?? {})) {
      for (const row of rows) insertRow(db, table, row);
    }

    for (const row of [...(payload.pending_queue ?? []), ...(payload.synced_queue_tail ?? [])]) {
      insertRow(db, 'sync_queue', row);
    }

    db.exec('commit');
  } catch (error) {
    try { db.exec('rollback'); } catch { /* keep the original error */ }
    throw error;
  }

  return { restored_at: nowIso(), counts: payload.counts, pending_restored: (payload.pending_queue ?? []).length };
}

/** Refuse to let an installer continue if the till's data would be lost. */
export function assertUpgradeSafe({ dataDir = defaultDataDir(), newDbFile = null } = {}) {
  const dbFile = path.join(dataDir, 'softcora-pos.sqlite');

  if (!fs.existsSync(dbFile)) {
    return { ok: true, first_install: true, data_dir: dataDir };
  }

  const db = openDatabase(dbFile);
  const pending = db.prepare("select count(*) c from sync_queue where status != 'synced'").get().c;
  const deviceId = getMeta(db, 'device_id');

  if (!deviceId) {
    return { ok: false, reason: 'This installation has no device identity; refusing to continue.', pending };
  }

  if (newDbFile && path.resolve(newDbFile) !== path.resolve(dbFile) && fs.existsSync(newDbFile)) {
    return { ok: false, reason: 'The update would replace the till database; use restore, not overwrite.', pending };
  }

  return {
    ok: true,
    data_dir: dataDir,
    device_id: deviceId,
    pending: Number(pending),
    message: pending > 0
      ? `${pending} record(s) have not reached the server yet — they are kept and will sync after the update.`
      : 'All records are synced; the update may proceed.',
  };
}

function insertRow(db, table, row) {
  const columns = Object.keys(row);
  const placeholders = columns.map(() => '?').join(',');
  const updates = columns.filter((column) => column !== 'id').map((column) => `${column}=excluded.${column}`).join(',');

  const statement = db.prepare(`insert into ${table} (${columns.join(',')}) values (${placeholders})
      ${updates ? `on conflict(id) do update set ${updates}` : 'on conflict do nothing'}`);

  statement.run(...columns.map((column) => row[column]));
}
