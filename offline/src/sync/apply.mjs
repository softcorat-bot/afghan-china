/**
 * Applying what the server sent.
 *
 * Master data is upserted by uuid. The one subtlety that matters: a row with an
 * unsynced local change is **left alone**. Overwriting it here would silently
 * destroy a shop-floor edit before the server ever heard about it; instead the
 * edit goes up on the next push and the server's conflict policy decides — which
 * is the only place that decision belongs.
 */
import { nowIso } from '../ids.mjs';

const TABLE_MAP = {
  products: 'products',
  product_categories: 'categories',
  customers: 'customers',
  suppliers: 'suppliers',
  counters: 'counters',
  branches: 'branches',
};

const COLUMNS = {
  products: ['name', 'name_fa', 'sku', 'barcode', 'unit', 'cost_price', 'sale_price', 'wholesale_price',
    'tax_rate', 'track_inventory', 'stock_qty', 'min_stock', 'active', 'revision'],
  categories: ['name'],
  customers: ['name', 'phone', 'email', 'address', 'total_spent', 'orders_count', 'loyalty_points', 'revision'],
  suppliers: ['name', 'phone'],
  counters: ['name', 'branch_id'],
  branches: ['name', 'code'],
};

export function applyPull(db, pull) {
  const summary = { applied: 0, deferred: 0, deleted: 0, tables: {} };

  db.exec('begin immediate');
  try {
    for (const [table, rows] of Object.entries(pull.data ?? {})) {
      const local = TABLE_MAP[table];
      if (!local || !Array.isArray(rows)) continue;

      summary.tables[local] = summary.tables[local] || { applied: 0, deferred: 0 };

      for (const row of rows) {
        if (!row.uuid) continue;

        if (hasLocalPendingChange(db, row.uuid)) {
          summary.deferred++;
          summary.tables[local].deferred++;
          continue;
        }

        upsert(db, local, row);
        summary.applied++;
        summary.tables[local].applied++;
      }
    }

    for (const [table, tombstones] of Object.entries(pull.deleted ?? {})) {
      const local = TABLE_MAP[table];
      if (!local || !Array.isArray(tombstones)) continue;

      for (const row of tombstones) {
        if (!row.uuid || hasLocalPendingChange(db, row.uuid)) {
          summary.deferred++;
          continue;
        }

        remove(db, local, row.uuid);
        summary.deleted++;
      }
    }

    db.exec('commit');
  } catch (error) {
    try { db.exec('rollback'); } catch { /* keep the original error */ }
    throw error;
  }

  return summary;
}

function hasLocalPendingChange(db, uuidValue) {
  const row = db.prepare("select 1 as busy from sync_queue where entity_uuid = ? and status in ('pending','syncing','failed','conflict') limit 1")
    .get(uuidValue);

  return Boolean(row);
}

function upsert(db, table, row) {
  const columns = COLUMNS[table];
  const values = columns.map((column) => normalise(row[column]));

  const placeholders = columns.map(() => '?').join(',');
  const updates = columns.map((column) => `${column}=excluded.${column}`).join(',');

  db.prepare(`insert into ${table} (uuid, ${columns.join(',')}, sync_state, updated_at)
      values (?, ${placeholders}, 'synced', ?)
      on conflict(uuid) do update set ${updates}, sync_state='synced', updated_at=excluded.updated_at`)
    .run(row.uuid, ...values, nowIso());
}

function remove(db, table, uuidValue) {
  if (table === 'products' || table === 'customers') {
    // Keep the history that references them (a sale line still names the item);
    // deactivating is what "deleted centrally" means at a till.
    db.prepare(`update ${table} set active = 0, sync_state='synced', updated_at=? where uuid = ?`).run(nowIso(), uuidValue);

    return;
  }

  db.prepare(`delete from ${table} where uuid = ?`).run(uuidValue);
}

function normalise(value) {
  if (value === undefined || value === null) return null;
  if (typeof value === 'boolean') return value ? 1 : 0;

  return value;
}
