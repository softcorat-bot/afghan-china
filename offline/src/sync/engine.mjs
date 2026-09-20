/**
 * The sync engine.
 *
 * Contract, in the order the till performs it:
 *
 *   push (outbox, in batches)  →  server ACK per change
 *                              →  pull from the stored cursor
 *                              →  apply locally
 *                              →  ACK the cursor
 *
 * Consequences that are deliberate, not incidental:
 *  - a change is only marked synced after the server acknowledges it;
 *  - a re-sent change returns the earlier result (the server is idempotent) and
 *    is treated as a success, not an error;
 *  - a failed change stays in the queue with a backoff, and the successes in the
 *    same batch are never rolled back;
 *  - "online" is not guessed from a Wi-Fi icon: the engine asks the server.
 */
import { claim, counts, dueRows, log, recordResult, recordTransportError } from '../queue.mjs';
import { applyPull } from './apply.mjs';
import { getMeta, setMeta } from '../db.mjs';
import { nowIso } from '../ids.mjs';
import { logs } from '../log.mjs';

export const STATES = {
  OFFLINE: 'offline',
  ONLINE: 'online',
  SYNCING: 'syncing',
  SYNCED: 'synced',
  PENDING: 'pending',
  FAILED: 'failed',
  CONFLICT: 'conflict',
};

export const CONNECTIVITY = {
  NETWORK_UNAVAILABLE: 'network_unavailable',
  SERVER_UNAVAILABLE: 'server_unavailable',
  AUTHENTICATED: 'authenticated',
  ONLINE: 'online',
  SYNC_AVAILABLE: 'sync_available',
};

export class SyncEngine {
  constructor({ db, client, deviceId, batchSize = 100, onProgress = () => {} }) {
    this.db = db;
    this.client = client;
    this.deviceId = deviceId;
    this.batchSize = batchSize;
    this.onProgress = onProgress;
  }

  /** What the Sync Status control shows. Real numbers, from real tables. */
  status() {
    const queue = counts(this.db);
    const lastLog = this.db.prepare('select * from sync_log order by id desc limit 1').get();

    return {
      device_id: this.deviceId,
      state: this.currentState(queue),
      connectivity: getMeta(this.db, 'connectivity', CONNECTIVITY.NETWORK_UNAVAILABLE),
      cursor: Number(getMeta(this.db, 'last_sync_cursor', 0)),
      last_sync_at: getMeta(this.db, 'last_sync_at'),
      last_sync_result: lastLog ? {
        status: lastLog.status,
        uploaded: lastLog.uploaded,
        downloaded: lastLog.downloaded,
        duplicates: lastLog.duplicates,
        conflicts: lastLog.conflicts,
        failed: lastLog.failed,
        message: lastLog.message,
      } : null,
      pending: queue.pending,
      failed: queue.failed,
      conflicts: queue.conflict,
      synced: queue.synced,
      next_attempt_at: this.db.prepare("select min(next_attempt_at) as at from sync_queue where status = 'failed'").get()?.at ?? null,
      auto_sync: getMeta(this.db, 'auto_sync', '1') === '1',
    };
  }

  currentState(queue) {
    if (this.syncing) return STATES.SYNCING;
    if (queue.conflict > 0) return STATES.CONFLICT;
    if (queue.failed > 0) return STATES.FAILED;
    if (queue.pending > 0 || queue.syncing > 0) return STATES.PENDING;
    if (!this.serverReachable) return STATES.OFFLINE;

    return STATES.SYNCED;
  }

  /**
   * Ask the server whether it is there — and whether it will talk to this till.
   * A dead Wi-Fi network, an unreachable server and an expired device token are
   * three different problems and the till reports them as three different things.
   */
  async probe() {
    try {
      const status = await this.client.status();
      this.serverReachable = true;
      this.deviceStatus = status.device?.status ?? null;
      setMeta(this.db, 'connectivity', CONNECTIVITY.SYNC_AVAILABLE);
      setMeta(this.db, 'server_seq', status.server_seq ?? null);

      return { ok: true, connectivity: CONNECTIVITY.SYNC_AVAILABLE, status };
    } catch (error) {
      this.serverReachable = false;

      const connectivity = error.code === 'network_unavailable'
        ? CONNECTIVITY.NETWORK_UNAVAILABLE
        : CONNECTIVITY.SERVER_UNAVAILABLE;

      setMeta(this.db, 'connectivity', connectivity);

      return { ok: false, connectivity, error: error.message, code: error.code ?? null };
    }
  }

  /** One Sync Now: push, then pull, then tell the server how far we got. */
  async syncNow({ skipPush = false, skipPull = false, force = true } = {}) {
    if (this.syncing) return { ok: false, reason: 'already_syncing' };

    this.syncing = true;
    const started = Date.now();
    this.onProgress({ phase: 'start', at: nowIso() });

    try {
      const push = skipPush ? { uploaded: 0, duplicates: 0, conflicts: 0, failed: 0 } : await this.push({ force });
      const pull = skipPull ? { downloaded: 0, deferred: 0 } : await this.pull();

      this.syncing = false;

      const status = (push.failed > 0 || pull.failed > 0) ? 'partial' : 'ok';
      log(this.db, {
        direction: 'full',
        status,
        uploaded: push.uploaded,
        downloaded: pull.downloaded,
        duplicates: push.duplicates,
        conflicts: push.conflicts,
        failed: push.failed + (pull.failed ?? 0),
        message: push.failed > 0 ? `${push.failed} change(s) could not be applied yet.` : null,
        duration_ms: Date.now() - started,
      });

      setMeta(this.db, 'last_sync_at', nowIso());

      const result = {
        ok: status === 'ok',
        status,
        uploaded: push.uploaded,
        downloaded: pull.downloaded,
        duplicates: push.duplicates,
        conflicts: push.conflicts,
        failed: push.failed,
        deferred: pull.deferred,
        duration_ms: Date.now() - started,
        state: this.status(),
      };

      logs.sync('sync finished', {
        status, uploaded: push.uploaded, downloaded: pull.downloaded,
        duplicates: push.duplicates, conflicts: push.conflicts, failed: push.failed,
        duration_ms: result.duration_ms,
      });

      this.onProgress({ phase: 'done', ...result });

      return result;
    } catch (error) {
      this.syncing = false;

      log(this.db, {
        direction: 'full',
        status: 'failed',
        failed: 1,
        message: error.message,
        duration_ms: Date.now() - started,
      });

      logs.error('sync cycle failed', { error: error.message });
      this.onProgress({ phase: 'error', message: error.message });

      return { ok: false, status: 'failed', message: error.message, state: this.status() };
    }
  }

  /** Everything in the outbox that the server has not acknowledged. */
  async push({ force = false } = {}) {
    const summary = { uploaded: 0, duplicates: 0, conflicts: 0, failed: 0, batches: 0 };

    for (;;) {
      const rows = dueRows(this.db, this.batchSize, { force });
      if (!rows.length) break;

      const claimed = claim(this.db, rows);
      this.onProgress({ phase: 'push', total: claimed.length, done: summary.uploaded + summary.failed });

      const batchUuid = cryptoRandom();
      const changes = claimed.map((row) => ({
        change_uuid: row.uuid,
        entity_type: row.entity_type,
        operation: row.operation,
        uuid: row.entity_uuid,
        captured_at: safeParse(row.payload)?.captured_at ?? row.created_at,
        payload: JSON.parse(row.payload),
      }));

      let response;
      try {
        response = await this.client.push({ batch_uuid: batchUuid, changes });
      } catch (error) {
        // The whole batch failed to travel: every claimed row keeps its place in
        // the queue and takes a backoff. Nothing is marked synced by mistake.
        const blocked = error.code === 'device_token_invalid' || error.code === 'device_blocked';

        setMeta(this.db, 'connectivity', blocked
          ? error.code
          : (error.code === 'network_unavailable' ? CONNECTIVITY.NETWORK_UNAVAILABLE : CONNECTIVITY.SERVER_UNAVAILABLE));

        if (blocked) {
          // Retrying will not help: this till needs an administrator.
          setMeta(this.db, 'device_blocked_reason', error.message);
          logs.securityWarn('sync refused by the server — device needs an administrator', { code: error.code, device_id: this.deviceId });
          for (const row of claimed) {
            this.db.prepare("update sync_queue set status='failed', error_message=?, next_attempt_at=null where id = ?")
              .run(error.message, row.id);
          }
        } else {
          logs.syncWarn('push batch did not travel; rows stay queued with backoff', { batch_size: claimed.length, error: error.message });
          for (const row of claimed) recordTransportError(this.db, row, error.message);
        }

        summary.failed += claimed.length;
        summary.error = error.message;
        this.onProgress({ phase: 'push', total: claimed.length, done: summary.uploaded + summary.failed, error: error.message });

        break;
      }

      this.serverReachable = true;
      setMeta(this.db, 'connectivity', CONNECTIVITY.SYNC_AVAILABLE);

      const results = new Map((response.results ?? []).map((result) => [result.change_uuid, result]));

      for (const row of claimed) {
        const result = results.get(row.uuid) ?? {
          status: 'error',
          message: 'The server did not report this change.',
        };

        const outcome = recordResult(this.db, row, result);

        if (outcome === 'synced') {
          if (result.status === 'duplicate') summary.duplicates++;
          else summary.uploaded++;
        } else if (outcome === 'conflict') {
          summary.conflicts++;
        } else {
          summary.failed++;
        }
      }

      summary.batches++;
      this.onProgress({
        phase: 'push',
        total: claimed.length,
        done: summary.uploaded + summary.failed + summary.conflicts,
        batch: summary.batches,
      });

      if (claimed.length < this.batchSize) break;
    }

    return summary;
  }

  /** Everything the server changed since the till last looked. */
  async pull() {
    const summary = { downloaded: 0, deferred: 0, rounds: 0, failed: 0 };
    let cursor = Number(getMeta(this.db, 'last_sync_cursor', 0));

    for (;;) {
      let page;
      try {
        page = await this.client.pull({ sinceSeq: cursor });
      } catch (error) {
        // The pull failed: whatever the reason, the cursor does not move, so
        // nothing is skipped and nothing is duplicated.
        summary.failed++;
        summary.error = error.message;
        summary.code = error.code ?? null;

        return summary;
      }

      const applied = applyPull(this.db, page);
      summary.downloaded += applied.applied + applied.deleted;
      summary.deferred += applied.deferred;
      summary.rounds++;

      cursor = Number(page.next_cursor ?? cursor);

      // The cursor is stored only after the rows are in the local database, so a
      // crash mid-pull re-fetches rather than skipping.
      setMeta(this.db, 'last_sync_cursor', cursor);
      this.onProgress({ phase: 'pull', downloaded: summary.downloaded, cursor });

      try {
        await this.client.ack({ cursor, applied: applied.applied, failed: applied.deferred });
      } catch { /* the cursor is safe locally; ack is for the server's view */ }

      if (!page.has_more) break;
    }

    // Conflicts an administrator has decided about, so the till can release the
    // queue row and show the outcome instead of an eternal badge.
    try {
      await this.refreshConflicts();
    } catch { /* non-fatal */ }

    return summary;
  }

  async refreshConflicts() {
    const response = await this.client.conflicts();
    const rows = response.data ?? [];

    this.db.exec('begin immediate');
    try {
      for (const conflict of rows) {
        this.db.prepare(`insert into conflicts
            (server_conflict_id, entity_type, entity_uuid, severity, policy, status, reason, local_payload,
             server_payload, differing_fields, detected_at, resolved_at, resolution_note, sync_state)
            values (?,?,?,?,?,?,?,?,?,?,?,?,?,'conflict')
            on conflict(server_conflict_id) do update set status=excluded.status, resolved_at=excluded.resolved_at,
              resolution_note=excluded.resolution_note, reason=excluded.reason`).run(
          conflict.id,
          conflict.entity_type,
          conflict.entity_uuid,
          conflict.severity,
          conflict.policy ?? null,
          conflict.status,
          conflict.reason ?? null,
          JSON.stringify(conflict.local_payload ?? null),
          JSON.stringify(conflict.server_payload ?? null),
          JSON.stringify(conflict.differing_fields ?? []),
          conflict.detected_at ?? null,
          conflict.resolved_at ?? null,
          conflict.resolution_note ?? null,
        );

        if (conflict.status && conflict.status !== 'pending') {
          // Decided centrally: the local record may now be released.
          this.db.prepare(`update sync_queue set status = 'synced', synced_at = ?, error_message = ?
              where entity_uuid = ? and status = 'conflict'`).run(nowIso(), `Resolved centrally: ${conflict.status}`, conflict.entity_uuid);
        }
      }
      this.db.exec('commit');
    } catch (error) {
      try { this.db.exec('rollback'); } catch { /* ignore */ }
      throw error;
    }

    return rows.length;
  }
}

function safeParse(value) {
  try { return JSON.parse(value); } catch { return null; }
}

function cryptoRandom() {
  return globalThis.crypto?.randomUUID?.() ?? `batch-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}
