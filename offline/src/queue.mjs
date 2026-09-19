/**
 * The outbox.
 *
 * A local write is not "done" when it is saved — it is done when the central
 * server has acknowledged it. Until then its queue row stays here, survives
 * restarts, and is retried with backoff. Nothing in this file deletes a row
 * because a sync failed; the only rows that leave are ones the server took.
 */
import { nowIso, round, uuid } from './ids.mjs';

/** Backoff after `attempts` failed tries: 5s, 20s, 80s … capped at 15 minutes. */
export function backoffSeconds(attempts) {
  return Math.min(15 * 60, 5 * 4 ** Math.max(0, attempts - 1));
}

/** Put a local write in the outbox. Always called inside the write's transaction. */
export function enqueue(db, { deviceId, entityType, entityUuid, operation = 'create', payload }) {
  const changeUuid = uuid();
  const bind = (value) => (value === undefined ? null : value);

  const info = db.prepare(`insert into sync_queue
      (uuid, device_id, entity_type, entity_uuid, operation, payload, status, attempts, created_at)
      values (?,?,?,?,?,?, 'pending', 0, ?)`)
    .run(changeUuid, bind(deviceId), entityType, entityUuid, operation, JSON.stringify(payload), nowIso());

  return { id: Number(info.lastInsertRowid), change_uuid: changeUuid };
}

/**
 * Rows that are due now: still pending, or failed and past their backoff.
 *
 * `force` is what a human pressing Sync Now means: try everything, including the
 * rows that are still waiting out a backoff. Automatic sync leaves it alone, so
 * a dead server is not hammered by a timer.
 */
export function dueRows(db, limit = 100, { force = false } = {}) {
  if (force) {
    return db.prepare(`select * from sync_queue where status in ('pending', 'failed') order by id limit ?`).all(limit);
  }

  return db.prepare(`select * from sync_queue
      where status in ('pending', 'failed')
        and (next_attempt_at is null or next_attempt_at <= ?)
      order by id
      limit ?`).all(nowIso(), limit);
}

export function claim(db, rows) {
  if (!rows.length) return [];

  const mark = db.prepare("update sync_queue set status = 'syncing', last_attempt_at = ? where id = ?");
  for (const row of rows) mark.run(nowIso(), row.id);

  return rows.map((row) => ({ ...row, status: 'syncing', last_attempt_at: nowIso() }));
}

export function counts(db) {
  const rows = db.prepare('select status, count(*) as total from sync_queue group by status').all();
  const result = { pending: 0, syncing: 0, synced: 0, failed: 0, conflict: 0, total: 0 };

  for (const row of rows) {
    result[row.status] = Number(row.total);
    result.total += Number(row.total);
  }

  return result;
}

/**
 * Record what the server said about one change.
 *
 * applied    → synced (the work is central)
 * duplicate  → synced (an earlier attempt already landed it — still a success)
 * conflict   → conflict: the local record is kept and an administrator decides
 * rejected   → failed with the server's reason; a human or a fix retries it
 * error      → failed with backoff; the next Sync Now tries again
 */
export function recordResult(db, row, result, { storeQueueRow = true } = {}) {
  const status = result.status;
  const serverId = result.server_id ?? result.server_row?.id ?? null;
  const conflictId = result.conflict_id ?? null;

  if (status === 'applied' || status === 'duplicate') {
    db.prepare(`update sync_queue set status='synced', synced_at=?, error_message=null, server_id=?, next_attempt_at=null
        where id = ?`).run(nowIso(), serverId === null ? null : String(serverId), row.id);

    markEntitySynced(db, row, result);

    return 'synced';
  }

  if (status === 'conflict') {
    db.prepare("update sync_queue set status='conflict', conflict_id=?, error_message=?, next_attempt_at=null where id = ?")
      .run(conflictId, result.message ?? 'Conflict — waiting for an administrator.', row.id);

    setEntityState(db, row.entity_type, row.entity_uuid, 'conflict');
    rememberConflict(db, row, result);

    return 'conflict';
  }

  if (status === 'rejected') {
    db.prepare("update sync_queue set status='failed', attempts=attempts+1, error_message=?, next_attempt_at=null where id = ?")
      .run(result.message ?? 'Rejected by the server.', row.id);

    setEntityState(db, row.entity_type, row.entity_uuid, 'failed');

    return 'failed';
  }

  // Transport or server error: keep it, back off, try again later.
  return recordTransportError(db, row, result.message ?? 'Sync failed.');
}

export function recordTransportError(db, row, message) {
  const attempts = Number(row.attempts ?? 0) + 1;
  const next = new Date(Date.now() + backoffSeconds(attempts) * 1000).toISOString();

  db.prepare(`update sync_queue set status='failed', attempts=?, error_message=?, next_attempt_at=?
      where id = ?`).run(attempts, message, next, row.id);

  setEntityState(db, row.entity_type, row.entity_uuid, 'failed');

  return 'failed';
}

/** "Retry now" from the Sync Status panel: forget the backoff, keep the row. */
export function retryFailed(db, id = null) {
  if (id === null) {
    const info = db.prepare("update sync_queue set status='pending', next_attempt_at=null where status = 'failed'").run();
    return Number(info.changes);
  }

  const info = db.prepare("update sync_queue set status='pending', next_attempt_at=null where id = ? and status = 'failed'").run(id);

  return Number(info.changes);
}

export function log(db, entry) {
  db.prepare(`insert into sync_log (at, direction, status, uploaded, downloaded, duplicates, conflicts, failed, message, duration_ms)
      values (?,?,?,?,?,?,?,?,?,?)`).run(
    nowIso(),
    entry.direction ?? 'full',
    entry.status ?? 'ok',
    entry.uploaded ?? 0,
    entry.downloaded ?? 0,
    entry.duplicates ?? 0,
    entry.conflicts ?? 0,
    entry.failed ?? 0,
    entry.message ?? null,
    entry.duration_ms ?? null,
  );
}

export function recentLog(db, limit = 20) {
  return db.prepare('select * from sync_log order by id desc limit ?').all(limit);
}

/* ── helpers ─────────────────────────────────────────────────────────────── */

const ENTITY_TABLE = {
  sale: 'sales',
  refund: 'refunds',
  stock_movement: 'stock_movements',
  stock_adjustment: 'stock_movements',
  cash_session: 'cash_sessions',
  cash_movement: 'cash_movements',
  customer: 'customers',
  product: 'products',
  expense: 'expenses',
};

function setEntityState(db, entityType, uuidValue, state) {
  const table = ENTITY_TABLE[entityType];
  if (!table) return;

  try {
    db.prepare(`update ${table} set sync_state = ? where uuid = ?`).run(state, uuidValue);
  } catch { /* a table without sync_state is simply not tracked */ }
}

function markEntitySynced(db, row, result) {
  const table = ENTITY_TABLE[row.entity_type];
  if (!table) return;

  try {
    const serverRow = result.server_row ?? null;
    const serverId = serverRow?.id ?? result.server_id ?? null;
    const serverInvoice = serverRow?.invoice_no ?? null;

    if (row.entity_type === 'sale') {
      db.prepare(`update sales set sync_state='synced', synced_at=?, server_id=coalesce(?, server_id),
          server_invoice_no=coalesce(?, server_invoice_no) where uuid = ?`)
        .run(nowIso(), serverId, serverInvoice, row.entity_uuid);

      // The movements this sale produced travelled with it: the server rebuilds
      // them from the sale payload, so they are synced once the sale is.
      db.prepare("update stock_movements set sync_state='synced' where ref_type='sale' and ref_uuid = ?").run(row.entity_uuid);

      return;
    }

    if (row.entity_type === 'refund') {
      db.prepare(`update refunds set sync_state='synced' where uuid = ?`).run(row.entity_uuid);
      db.prepare("update stock_movements set sync_state='synced' where ref_type='refund' and ref_uuid = ?").run(row.entity_uuid);

      return;
    }

    db.prepare(`update ${table} set sync_state='synced' where uuid = ?`).run(row.entity_uuid);
  } catch { /* ignore: the queue row is the source of truth for sync state */ }
}

function rememberConflict(db, row, result) {
  const payload = safeJson(row.payload, {});
  const serverRow = result.server_row ?? null;

  db.prepare(`insert into conflicts
      (server_conflict_id, entity_type, entity_uuid, severity, policy, status, reason, local_payload,
       server_payload, differing_fields, detected_at, sync_state)
      values (?,?,?,?,?,?,?,?,?,?,?,?)
      on conflict(server_conflict_id) do update set status=excluded.status, reason=excluded.reason,
        server_payload=excluded.server_payload, detected_at=excluded.detected_at`)
    .run(
      result.conflict_id ?? null,
      row.entity_type,
      row.entity_uuid,
      result.severity ?? 'critical',
      result.policy ?? 'append_only',
      'pending',
      result.message ?? null,
      JSON.stringify(payload),
      serverRow ? JSON.stringify(serverRow) : null,
      JSON.stringify(Object.keys(result.differing_fields ?? {})),
      nowIso(),
      'conflict',
    );
}

export function safeJson(value, fallback) {
  try { return JSON.parse(value); } catch { return fallback; }
}

export function percentage(part, whole) {
  return whole > 0 ? round((part / whole) * 100, 1) : 0;
}
