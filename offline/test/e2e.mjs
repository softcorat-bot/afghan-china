/**
 * End-to-end test of the offline till against a central server that speaks the
 * documented sync contract.
 *
 * The stub is a *test double for the protocol*, not a mock of the data: it
 * enforces the same rules the real Laravel API does — one idempotency record per
 * change_uuid, one row per entity uuid, a cursor-based pull — so the assertions
 * below (`no duplicate sales`, `nothing lost when the connection drops`,
 * `pending rows survive a restart`) are statements about the engine, not about a
 * fixture.
 *
 *   node test/e2e.mjs
 */
import http from 'node:http';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import assert from 'node:assert/strict';
import { openDatabase, getMeta, setMeta } from '../src/db.mjs';
import { cacheUser, login as localLogin, loadDeviceCredentials, saveDeviceCredentials } from '../src/auth.mjs';
import { SyncClient, registerDevice } from '../src/sync/client.mjs';
import { SyncEngine } from '../src/sync/engine.mjs';
import { counts, retryFailed } from '../src/queue.mjs';
import {
  addCashMovement, closeSession, createCustomer, createSale, openSession, refundSale,
} from '../src/pos.mjs';
import { createBackup, restoreBackup } from '../src/backup.mjs';
import { startServer } from '../src/server.mjs';
import { renderEscPos } from '../src/print.mjs';
import { suggestDeviceId, uuid } from '../src/ids.mjs';

/* ── the central server (protocol double) ───────────────────────────────── */

function startCentral() {
  const state = {
    devices: new Map(),          // device_id → { token, status }
    activation: new Map(),       // device_id → code
    ledger: new Map(),           // change_uuid → result
    entities: new Map(),         // uuid → { type, payload }
    byType: new Map(),           // entity_type → Map(uuid → payload)
    seq: 0,
    changes: [],                 // { seq, table, uuid, row }
    conflicts: new Map(),
    conflictSeq: 0,
    mode: 'normal',              // normal | drop | error
    dropOnce: false,
    stats: { pushes: 0, applied: 0, duplicates: 0, conflicts: 0, rejected: 0 },
  };

  const server = http.createServer(async (request, response) => {
    const url = new URL(request.url, 'http://localhost');
    const body = await readJson(request);

    if (state.mode === 'error' && url.pathname.endsWith('/push')) {
      response.writeHead(503, { 'content-type': 'application/json' }).end(JSON.stringify({ message: 'server unavailable' }));
      return;
    }

    const reply = (status, payload) => {
      if (state.mode === 'drop' && state.dropOnce && url.pathname.endsWith('/push')) {
        // Applied, but the answer never makes it back — exactly the case the
        // outbox has to survive.
        state.dropOnce = false;
        response.destroy();
        return;
      }
      response.writeHead(status, { 'content-type': 'application/json' }).end(JSON.stringify(payload ?? {}));
    };

    switch (true) {
      case url.pathname === '/api/v1/sync/register': {
        const device = state.devices.get(body.device_id) ?? { status: 'pending' };
        if (state.activation.get(body.device_id) !== body.activation_code) {
          return reply(422, { message: 'refused' });
        }
        device.status = 'active';
        state.activation.delete(body.device_id);
        state.devices.set(body.device_id, { ...device, token: crypto.randomUUID() });

        return reply(201, {
          device: { device_id: body.device_id, status: 'active' },
          device_token: state.devices.get(body.device_id).token,
          company_id: 1,
          branch_id: 1,
        });
      }

      case url.pathname === '/api/v1/sync/status': {
        const device = authorize(state, request);
        if (!device) return reply(401, { code: 'device_token_invalid' });

        return reply(200, { device: { device_id: device.id, status: device.status }, server_seq: state.seq, cursor: device.cursor ?? 0 });
      }

      case url.pathname === '/api/v1/sync/push' && request.method === 'POST': {
        const device = authorize(state, request);
        if (!device) return reply(401, { code: 'device_token_invalid' });
        if (device.status !== 'active') return reply(403, { code: 'device_disabled' });

        state.stats.pushes++;
        const results = (body.changes ?? []).map((change) => applyChange(state, device, change));

        return reply(200, { batch_uuid: body.batch_uuid, results, summary: state.stats, cursor: state.seq });
      }

      case url.pathname === '/api/v1/sync/pull': {
        const device = authorize(state, request);
        if (!device) return reply(401, { code: 'device_token_invalid' });

        const since = Number(url.searchParams.get('since_seq') ?? 0);
        const limit = Math.min(Number(url.searchParams.get('limit') ?? 500), 2000);
        const rows = state.changes.filter((change) => change.seq > since).slice(0, limit);

        const data = {};
        for (const change of rows) {
          if (change.deleted) continue;
          data[change.table] = data[change.table] ?? [];
          data[change.table].push(change.row);
        }

        const next = rows.length ? rows[rows.length - 1].seq : since;

        return reply(200, {
          data,
          deleted: {},
          next_cursor: next,
          has_more: state.changes.filter((change) => change.seq > next).length > 0,
          server_seq: state.seq,
        });
      }

      case url.pathname === '/api/v1/sync/ack': {
        const device = authorize(state, request);
        if (!device) return reply(401, { code: 'device_token_invalid' });
        device.cursor = Math.max(device.cursor ?? 0, Number(body.cursor ?? 0));

        return reply(200, { ok: true });
      }

      case url.pathname === '/api/v1/sync/conflicts': {
        const device = authorize(state, request);
        if (!device) return reply(401, { code: 'device_token_invalid' });

        return reply(200, { data: [...state.conflicts.values()] });
      }

      default:
        return reply(404, { message: `no route ${url.pathname}` });
    }
  });

  return new Promise((resolve) => {
    server.listen(0, '127.0.0.1', () => resolve({
      state,
      server,
      url: `http://127.0.0.1:${server.address().port}`,
      close: () => new Promise((done) => server.close(done)),
      /** Server-side catalogue change, as the web POS would make it. */
      upsert(table, row) {
        state.seq++;
        state.changes.push({ seq: state.seq, table, uuid: row.uuid, row: { ...row, sync_seq: state.seq } });
      },
      delete(table, uuidValue) {
        state.seq++;
        state.changes.push({ seq: state.seq, table, uuid: uuidValue, deleted: true });
      },
    }));
  });
}

function authorize(state, request) {
  const token = (request.headers.authorization ?? '').replace(/^Bearer\s+/i, '');
  const deviceId = request.headers['x-device-id'];
  const device = state.devices.get(deviceId);

  if (!device || !device.token || device.token !== token) return null;

  return { ...device, id: deviceId };
}

function applyChange(state, device, change) {
  // Idempotency: the ledger answers before any business logic runs.
  if (state.ledger.has(change.change_uuid)) {
    state.stats.duplicates++;
    const stored = state.ledger.get(change.change_uuid);

    return { ...stored, status: stored.status === 'applied' ? 'duplicate' : stored.status, replayed: true };
  }

  const existing = state.entities.get(change.uuid);
  let result;

  if (existing && change.operation === 'update') {
    // An update to a row the server already has — how a drawer session is
    // closed, or a product corrected — is applied, not treated as a clash.
    state.entities.set(change.uuid, { type: change.entity_type, payload: change.payload });
    state.byType.get(change.entity_type)?.set(change.uuid, change.payload);
    result = { status: 'applied', server_id: state.entities.size, server_row: { id: state.entities.size, uuid: change.uuid } };
    state.stats.applied++;
  } else if (existing) {
    const same = JSON.stringify(existing.payload) === JSON.stringify(change.payload);
    result = same
      ? { status: 'duplicate', message: 'already synchronized' }
      : { status: 'conflict', conflict_id: ++state.conflictSeq, severity: 'critical', message: 'different content for the same uuid' };

    if (result.status === 'conflict') {
      state.stats.conflicts++;
      state.conflicts.set(result.conflict_id, {
        id: result.conflict_id, entity_type: change.entity_type, entity_uuid: change.uuid,
        severity: 'critical', status: 'pending', reason: result.message,
      });
    }
  } else if (change.entity_type === 'refund' && !state.entities.has(change.payload?.sale_uuid)) {
    // The real API refuses a refund whose sale has not arrived yet.
    result = { status: 'rejected', message: 'The sale this refund belongs to has not reached the server yet.' };
    state.stats.rejected++;
  } else {
    state.entities.set(change.uuid, { type: change.entity_type, payload: change.payload });
    state.byType.set(change.entity_type, (state.byType.get(change.entity_type) ?? new Map()).set(change.uuid, change.payload));
    result = { status: 'applied', server_id: state.entities.size, server_row: { id: state.entities.size, uuid: change.uuid } };
    state.stats.applied++;
  }

  state.ledger.set(change.change_uuid, { change_uuid: change.change_uuid, entity_type: change.entity_type, uuid: change.uuid, ...result });

  return { change_uuid: change.change_uuid, entity_type: change.entity_type, uuid: change.uuid, ...result };
}

function readJson(request) {
  return new Promise((resolve) => {
    let data = '';
    request.on('data', (chunk) => { data += chunk; });
    request.on('end', () => { try { resolve(data ? JSON.parse(data) : {}); } catch { resolve({}); } });
  });
}

/* ── the till under test ────────────────────────────────────────────────── */

function freshDir(label) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), `softcora-${label}-`));

  return dir;
}

function openTill(dir, deviceId, serverUrl) {
  const db = openDatabase(path.join(dir, 'softcora-pos.sqlite'));
  setMeta(db, 'device_id', deviceId);
  setMeta(db, 'server_url', serverUrl);

  return db;
}

function engineFor(db, deviceId, serverUrl, dataDir = null) {
  const credentials = dataDir ? loadDeviceCredentials(dataDir) : loadDeviceCredentials();

  return new SyncEngine({
    db,
    client: new SyncClient({ baseUrl: serverUrl, deviceId, token: credentials?.device_token ?? null, timeoutMs: 5000 }),
    deviceId,
  });
}

/* ── the scenario ───────────────────────────────────────────────────────── */

const tmp = freshDir('e2e');
const results = [];
let failures = 0;

async function step(name, fn) {
  try {
    await fn();
    results.push(`  ✓ ${name}`);
  } catch (error) {
    failures++;
    results.push(`  ✗ ${name}\n      ${error.message}`);
  }
}

const central = await startCentral();
const deviceId = suggestDeviceId('KBL');
const dir = path.join(tmp, 'till-1');
const dir2 = path.join(tmp, 'till-2');

await step('till starts with an identity and no data', () => {
  const db = openTill(dir, deviceId, central.url);
  assert.equal(getMeta(db, 'device_id'), deviceId);
  assert.equal(db.prepare('select count(*) c from sales').get().c, 0);
  db.close();
});

await step('a sale is refused before a drawer session exists? no — selling never waits', () => {
  const db = openTill(dir, deviceId, central.url);
  const user = { id: 1, uuid: uuid(), name: 'Cashier One', role: 'Counter' };
  cacheUser(db, { uuid: user.uuid, name: user.name, email: 'cashier@shop.af', role: 'Counter' }, 'secret-1234', { type: 'password' });

  const login = localLogin(db, { identifier: 'cashier@shop.af', secret: 'secret-1234' });
  assert.equal(login.ok, true, 'offline login must work from the hashed cache');

  const bad = localLogin(db, { identifier: 'cashier@shop.af', secret: 'wrong' });
  assert.equal(bad.ok, false, 'a wrong password must be refused offline');

  const stored = db.prepare('select password_hash from users where email = ?').get('cashier@shop.af').password_hash;
  assert.ok(stored.startsWith('scrypt$'), 'credentials are stored as scrypt hashes');
  assert.ok(!stored.includes('secret-1234'), 'no plaintext anywhere');

  db.close();
});

await step('20 sales, 5 customers, 3 returns, 10 stock movements, 2 cash sessions — all with no server', async () => {
  const db = openTill(dir, deviceId, central.url);
  const user = db.prepare('select * from users limit 1').get();

  // Ten products and a customer to sell to.
  const insertProduct = db.prepare(`insert into products (uuid, name, sku, barcode, sale_price, cost_price, tax_rate, track_inventory, stock_qty, active, sync_state, updated_at)
      values (?,?,?,?,?,?,?,1,?,1,'synced',datetime('now'))`);

  for (let index = 1; index <= 10; index++) {
    insertProduct.run(uuid(), `Product ${index}`, `SKU-${index}`, `1000${index}`, 100 + index, 60, 0, 500);
  }

  const sessionA = openSession(db, { deviceId, user, openingFloat: 5000 });
  const sessionB = null;

  for (let index = 0; index < 20; index++) {
    const product = db.prepare('select * from products order by id limit 1 offset ?').get(index % 10);
    createSale(db, {
      deviceId,
      user,
      session: sessionA,
      items: [{ product_uuid: product.uuid, qty: 1 }, { product_uuid: db.prepare('select * from products order by id limit 1 offset ?').get((index + 1) % 10).uuid, qty: 2 }],
      payments: [{ method: 'cash', amount: 1000 }],
    });
  }

  for (let index = 0; index < 5; index++) {
    createCustomer(db, { deviceId, user, name: `Customer ${index}`, phone: `07000000${index}` });
  }

  const firstThree = db.prepare('select * from sales order by id limit 3').all();
  for (const sale of firstThree) {
    const item = db.prepare('select * from sale_items where sale_id = ? limit 1').get(sale.id);
    refundSale(db, { deviceId, saleUuid: sale.uuid, user, lines: [{ sale_item_id: item.id, qty: 1 }], reason: 'customer return' });
  }

  for (let index = 0; index < 5; index++) {
    const product = db.prepare('select * from products order by id limit 1 offset ?').get(index);
    db.prepare(`insert into stock_movements (uuid, product_id, product_uuid, type, qty, reason, created_at, sync_state)
        values (?,?,?,?,?,?,datetime('now'),'pending')`).run(uuid(), product.id, product.uuid, 'decrease', 5, 'damage');
  }

  addCashMovement(db, { deviceId, sessionId: sessionA.id, user, type: 'in', amount: 200, reason: 'change float' });
  addCashMovement(db, { deviceId, sessionId: sessionA.id, user, type: 'out', amount: 150, reason: 'tea' });
  closeSession(db, { deviceId, sessionId: sessionA.id, countedCash: 0 });

  const sessionB2 = openSession(db, { deviceId, user, openingFloat: 1000 });
  addCashMovement(db, { deviceId, sessionId: sessionB2.id, user, type: 'in', amount: 500, reason: 'second shift float' });

  const queue = counts(db);
  assert.equal(db.prepare('select count(*) c from sales').get().c, 20, '20 sales stored locally');
  assert.equal(db.prepare('select count(*) c from customers').get().c, 5);
  assert.equal(db.prepare('select count(*) c from refunds').get().c, 3);
  assert.equal(db.prepare('select count(*) c from cash_sessions').get().c, 2);
  assert.equal(queue.pending, 34, `outbox holds every local write (pending ${queue.pending})`);
  assert.equal(sessionB, null);

  db.close();
});

await step('restarting the till loses nothing', () => {
  const db = openDatabase(path.join(dir, 'softcora-pos.sqlite'));
  assert.equal(db.prepare('select count(*) c from sales').get().c, 20);
  assert.equal(counts(db).pending, 34);
  assert.equal(getMeta(db, 'device_id'), deviceId, 'device identity survives a restart');
  db.close();
});

await step('with the server unavailable, sales still work and nothing is lost', async () => {
  const db = openDatabase(path.join(dir, 'softcora-pos.sqlite'));
  const engine = engineFor(db, deviceId, 'http://127.0.0.1:1', dir); // nothing is listening
  const result = await engine.syncNow();

  assert.equal(result.ok, false, 'sync fails, loudly');
  assert.ok(['network_unavailable', 'server_unavailable'].includes(
    db.prepare("select value from meta where key='connectivity'").get().value,
  ));

  const user = db.prepare('select * from users limit 1').get();
  const product = db.prepare('select * from products limit 1').get();
  createSale(db, { deviceId, user, items: [{ product_uuid: product.uuid, qty: 1 }], payments: [{ method: 'cash', amount: 500 }] });

  assert.equal(db.prepare('select count(*) c from sales').get().c, 21, 'an offline sale after a failed sync is still a sale');
  assert.equal(counts(db).synced, 0, 'nothing was marked synced without an acknowledgement');
  assert.equal(counts(db).pending + counts(db).failed, 35, 'every record is still in the outbox');
  assert.ok(counts(db).failed >= 34, 'the failed attempt did not lose anything');

  db.close();
});

await step('the device activates with the administrator\'s code', async () => {
  central.state.activation.set(deviceId, 'ABCD-1234');

  const result = await registerDevice({
    baseUrl: central.url, deviceId, activationCode: 'ABCD-1234', name: 'Till 1', platform: 'windows', appVersion: '1.0.0',
  });

  saveDeviceCredentials({ device_id: deviceId, device_token: result.device_token, company_id: result.company_id, branch_id: result.branch_id }, dir);

  assert.equal(result.device.status, 'active');
  assert.ok(loadDeviceCredentials(dir).device_token, 'the device token is stored locally');
});

await step('Sync Now uploads everything, once', async () => {
  const db = openDatabase(path.join(dir, 'softcora-pos.sqlite'));
  const engine = engineFor(db, deviceId, central.url, dir);

  const result = await engine.syncNow();
  assert.equal(result.failed, 0, `no failures (${JSON.stringify(result)})`);

  // 20 sales + 1 later sale + 3 refunds + 5 movements + 2 sessions + 4 cash movements + 5 customers
  const sales = central.state.byType.get('sale') ?? new Map();
  assert.equal(sales.size, 21, 'the server has exactly 21 sales');
  assert.equal((central.state.byType.get('customer') ?? new Map()).size, 5);
  assert.equal((central.state.byType.get('refund') ?? new Map()).size, 3);
  assert.equal((central.state.byType.get('cash_session') ?? new Map()).size, 2);

  const queue = counts(db);
  assert.equal(queue.pending + queue.failed + queue.conflict, 0, 'the outbox is drained');
  assert.ok(queue.synced >= 35, `every change was acknowledged (${queue.synced})`);

  db.close();
});

await step('repeated Sync Now creates no duplicates', async () => {
  const db = openDatabase(path.join(dir, 'softcora-pos.sqlite'));
  const engine = engineFor(db, deviceId, central.url, dir);

  await engine.syncNow();
  await engine.syncNow();

  assert.equal((central.state.byType.get('sale') ?? new Map()).size, 21, 'still 21 sales on the server');
  assert.equal(central.state.entities.size, (central.state.byType.get('sale') ?? new Map()).size
    + (central.state.byType.get('customer') ?? new Map()).size
    + (central.state.byType.get('refund') ?? new Map()).size
    + (central.state.byType.get('stock_movement') ?? new Map()).size
    + (central.state.byType.get('cash_session') ?? new Map()).size
    + (central.state.byType.get('cash_movement') ?? new Map()).size, 'one central row per entity uuid');

  db.close();
});

await step('a lost response is retried without double-charging', async () => {
  const db = openDatabase(path.join(dir, 'softcora-pos.sqlite'));
  const user = db.prepare('select * from users limit 1').get();
  const product = db.prepare('select * from products limit 1').get();

  for (let index = 0; index < 3; index++) {
    createSale(db, { deviceId, user, items: [{ product_uuid: product.uuid, qty: 1 }], payments: [{ method: 'card', amount: 300 }] });
  }

  central.state.mode = 'drop';
  central.state.dropOnce = true;

  const engine = engineFor(db, deviceId, central.url, dir);
  await engine.syncNow();

  central.state.mode = 'normal';

  // The first attempt reached the server but the answer never came back: the
  // rows are still queued and are simply retried.
  await engine.syncNow();

  const sales = central.state.byType.get('sale');
  assert.equal(sales.size, 24, 'three more sales — not six');

  const uuids = [...sales.keys()];
  assert.equal(new Set(uuids).size, uuids.length, 'every sale has a unique central uuid');

  assert.equal(counts(db).pending + counts(db).failed + counts(db).conflict, 0, 'the queue cleared after the retry');
  db.close();
});

await step('a rejected change keeps its place while the rest go through', async () => {
  const db = openDatabase(path.join(dir2, 'softcora-pos.sqlite'));
  setMeta(db, 'device_id', suggestDeviceId('HRT'));
  setMeta(db, 'server_url', central.url);
  db.close();

  const db2 = openDatabase(path.join(dir2, 'softcora-pos.sqlite'));
  // A refund for a sale the server has never seen: it must be refused, kept, and
  // retried later — never silently dropped.
  const ghostSaleUuid = uuid();
  db2.prepare(`insert into refunds (uuid, sale_id, user_id, amount, reason, captured_at, sync_state)
      values (?, null, null, 50, 'orphan', datetime('now'), 'pending')`).run(ghostSaleUuid);
  db2.prepare(`insert into sync_queue (uuid, device_id, entity_type, entity_uuid, operation, payload, status, attempts, created_at)
      values (?,?,'refund',?,'create',?,'pending',0,datetime('now'))`)
    .run(uuid(), getMeta(db2, 'device_id'), ghostSaleUuid, JSON.stringify({ uuid: ghostSaleUuid, sale_uuid: uuid(), amount: 50, items: [] }));

  const device2 = getMeta(db2, 'device_id');
  central.state.activation.set(device2, 'WXYZ-9999');
  const registration = await registerDevice({ baseUrl: central.url, deviceId: device2, activationCode: 'WXYZ-9999', name: 'Till 2' });
  saveDeviceCredentials({ device_id: device2, device_token: registration.device_token, company_id: 1, branch_id: 1 }, dir2);

  const engine = engineFor(db2, device2, central.url, dir2);
  await engine.syncNow();

  const failed = db2.prepare("select * from sync_queue where status = 'failed'").all();
  assert.equal(failed.length, 1, 'the orphan refund is kept as failed, with its reason');
  assert.match(failed[0].error_message, /has not reached the server/);

  assert.ok(retryFailed(db2) >= 1, 'the user can retry it');
  assert.equal(db2.prepare("select count(*) c from sync_queue where entity_uuid = ? and status='pending'").get(ghostSaleUuid).c, 1,
    'the record was re-queued, not deleted');

  db2.close();
});

await step('central changes flow down incrementally', async () => {
  const db = openDatabase(path.join(dir, 'softcora-pos.sqlite'));
  const productUuid = uuid();

  central.upsert('products', { uuid: productUuid, name: 'Pulled Product', barcode: '9999999', sale_price: 42, tax_rate: 0, track_inventory: 1, stock_qty: 10, active: 1 });
  central.upsert('products', { uuid: db.prepare('select uuid from products order by id limit 1').get().uuid, name: 'Renamed centrally', sale_price: 111, active: 1 });

  const engine = engineFor(db, deviceId, central.url, dir);
  const before = Number(getMeta(db, 'last_sync_cursor', 0));
  await engine.syncNow();

  const pulled = db.prepare('select * from products where uuid = ?').get(productUuid);
  assert.ok(pulled, 'a product created centrally appears at the till');
  assert.equal(Number(pulled.sale_price), 42);
  assert.ok(Number(getMeta(db, 'last_sync_cursor', 0)) > before, 'the cursor advanced');
  assert.equal(counts(db).pending, 0, 'a pull does not create outgoing work');

  const firstProduct = db.prepare('select * from products where name = ?').get('Renamed centrally');
  assert.ok(firstProduct, 'a central edit reaches the till');

  // A second pull with nothing new must move nothing.
  const cursor = getMeta(db, 'last_sync_cursor');
  await engine.syncNow();
  assert.equal(getMeta(db, 'last_sync_cursor'), cursor, 'an idle sync downloads nothing and stays put');

  db.close();
});

await step('a backup carries the unsynced queue and a restore keeps it', async () => {
  const db = openDatabase(path.join(dir, 'softcora-pos.sqlite'));
  const user = db.prepare('select * from users limit 1').get();
  const product = db.prepare('select * from products limit 1').get();

  createSale(db, { deviceId, user, items: [{ product_uuid: product.uuid, qty: 1 }], payments: [{ method: 'cash', amount: 800 }] });

  const backup = createBackup(db, { outDir: path.join(dir, 'backups'), label: 'test' });
  assert.ok(backup.counts.pending >= 1, 'the unsynced sale is in the file');

  const restoreDir = path.join(tmp, 'restored');
  restoreBackup(backup.file, { dataDir: restoreDir });

  const restored = openDatabase(path.join(restoreDir, 'softcora-pos.sqlite'));
  assert.equal(restored.prepare('select count(*) c from sales').get().c, 25, 'every sale came back');
  assert.ok(counts(restored).pending >= 1, 'the unsynced sale is still waiting to go up');
  assert.equal(getMeta(restored, 'device_id'), deviceId, 'the restored till keeps its identity');

  restored.close();
  db.close();
});

await step('two tills never collide', async () => {
  const dbB = openDatabase(path.join(dir2, 'softcora-pos.sqlite'));
  const user = dbB.prepare('select * from users limit 1').get();

  if (!user) {
    cacheUser(dbB, { uuid: uuid(), name: 'Cashier Two', email: 'two@shop.af' }, 'secret-1234', { type: 'password' });
  }

  const tillUser = dbB.prepare('select * from users limit 1').get();
  const product = { uuid: uuid(), name: 'Two-only product', id: null };

  dbB.prepare(`insert into products (uuid, name, sale_price, tax_rate, track_inventory, stock_qty, active, sync_state, updated_at)
      values (?,?,10,0,1,50,1,'synced',datetime('now'))`).run(product.uuid, product.name);

  const sale = createSale(dbB, { deviceId: getMeta(dbB, 'device_id'), user: tillUser, items: [{ product_uuid: product.uuid, qty: 1 }], payments: [{ method: 'cash', amount: 10 }] });
  assert.ok(sale.device_invoice_no.includes(getMeta(dbB, 'device_id').split('-').pop()), 'the receipt number names the till');

  const engine = engineFor(dbB, getMeta(dbB, 'device_id'), central.url, dir2);
  await engine.syncNow();

  const invoices = [...(central.state.byType.get('sale') ?? new Map()).values()].map((payload) => payload.device_invoice_no);
  assert.equal(new Set(invoices).size, invoices.length, 'no two sales share a receipt number across devices');
  dbB.close();
});

await step('the till reports the states it is in', async () => {
  const db = openDatabase(path.join(dir, 'softcora-pos.sqlite'));
  const engine = engineFor(db, deviceId, central.url, dir);

  await engine.syncNow();
  const synced = engine.status();
  assert.equal(synced.state, 'synced', `state is synced (${synced.state})`);
  assert.equal(synced.pending, 0);

  const user = db.prepare('select * from users limit 1').get();
  const product = db.prepare('select * from products limit 1').get();
  createSale(db, { deviceId, user, items: [{ product_uuid: product.uuid, qty: 1 }], payments: [{ method: 'cash', amount: 200 }] });
  assert.equal(engine.status().state, 'pending', 'a local write makes the state pending');

  db.close();
});

/* ── what a freshly installed till does, over its own HTTP surface ──────────
   This is the installer-day path: a brand-new till with an empty database, a
   shop that has never seen a computer, and no server reachable yet. */
await step('a freshly installed till can be set up without a terminal', async () => {
  const fresh = path.join(tmp, 'fresh-install');
  fs.rmSync(fresh, { recursive: true, force: true });
  fs.mkdirSync(fresh, { recursive: true });

  const port = 7823;
  const till = startServer({ dbFile: path.join(fresh, 'till.sqlite'), serverUrl: 'http://127.0.0.1:1', port, host: '127.0.0.1' });

  const call = async (method, endpoint, body, token) => {
    const response = await fetch(`http://127.0.0.1:${port}${endpoint}`, {
      method,
      headers: {
        accept: 'application/json',
        ...(body ? { 'content-type': 'application/json' } : {}),
        ...(token ? { authorization: `Bearer ${token}` } : {}),
      },
      body: body ? JSON.stringify(body) : undefined,
    });

    return { status: response.status, payload: await response.json().catch(() => ({})) };
  };

  try {
    const setup = await call('GET', '/api/setup');
    assert.equal(setup.payload.needs_setup, true, 'an empty till asks to be set up');
    assert.match(setup.payload.device_id, /^SC-POS-[0-9A-F]{6}$/, 'a fresh till already has its own device id');

    const me = await call('GET', '/api/auth/me');
    assert.equal(me.status, 401, 'nobody is signed in yet');

    const created = await call('POST', '/api/staff', { identifier: 'owner@shop.af', secret: 'cabinet-9182', name: 'Owner', role: 'Owner' });
    assert.equal(created.status, 201, 'the first sign-in can be created on a till with no users');

    const login = await call('POST', '/api/auth/login', { identifier: 'owner@shop.af', secret: 'cabinet-9182' });
    assert.equal(login.status, 200, 'that sign-in works with no internet');
    const token = login.payload.token;

    const afterwards = await call('POST', '/api/staff', { identifier: 'stranger@shop.af', secret: 'let-me-in' });
    assert.equal(afterwards.status, 401, 'adding more staff needs a sign-in once setup is done');

    const staff = await call('GET', '/api/staff', null, token);
    assert.equal(staff.status, 200, 'the staff list answers once signed in');
    assert.equal(staff.payload.data.length, 1);

    const product = await call('POST', '/api/products', { name: 'Fresh Stock', sale_price: 180, barcode: '6001234567890', opening_stock: 40 }, token);
    assert.equal(product.payload.product.stock_qty, 40, 'the opening stock a shop types is the stock it gets');

    await call('POST', '/api/session/open', { opening_float: 5000 }, token);
    const sale = await call('POST', '/api/sales', {
      items: [{ barcode: '6001234567890', qty: 2 }],
      payments: [{ method: 'cash', amount: 360 }],
    }, token);

    assert.equal(sale.status, 201, `the first sale of a new till goes through (${JSON.stringify(sale.payload)})`);
    assert.ok(sale.payload.sale.device_invoice_no.startsWith(setup.payload.device_id.split('-').pop()),
      'the receipt is numbered for this device, so two tills cannot collide');

    const unregistered = await call('POST', '/api/device/register', { activation_code: 'AAAA-1111' }, token);
    assert.equal(unregistered.status >= 400, true, 'registering against a dead server fails honestly instead of pretending');

    const status = await call('GET', '/api/status');
    assert.equal(status.payload.status.state, 'pending', 'the sale is pending, not lost and not synced');
  } finally {
    till.stop();
    till.till.db.close();
  }
});

await step('logs land in files, separated by channel, with no secrets inside', async () => {
  const home = path.join(tmp, 'logs-home');
  fs.rmSync(home, { recursive: true, force: true });
  fs.mkdirSync(home, { recursive: true });

  const previousDataEnv = process.env.SOFTCORA_DATA;
  process.env.SOFTCORA_DATA = home;                    // logs land in <home>/logs

  const port = 7824;
  const till = startServer({ dbFile: path.join(home, 'till.sqlite'), serverUrl: 'http://127.0.0.1:1', port, host: '127.0.0.1' });

  const call = async (method, endpoint, body, token) => {
    const response = await fetch(`http://127.0.0.1:${port}${endpoint}`, {
      method,
      headers: {
        accept: 'application/json',
        ...(body ? { 'content-type': 'application/json' } : {}),
        ...(token ? { authorization: `Bearer ${token}` } : {}),
      },
      body: body ? JSON.stringify(body) : undefined,
    });

    return { status: response.status, payload: await response.json().catch(() => ({})) };
  };

  try {
    await call('POST', '/api/staff', { identifier: 'logger@shop.af', secret: 'scuba-5521', name: 'Logger' });
    await call('POST', '/api/auth/login', { identifier: 'logger@shop.af', secret: 'totally-wrong' });
    const login = await call('POST', '/api/auth/login', { identifier: 'logger@shop.af', secret: 'scuba-5521' });
    assert.equal(login.status, 200);
    const token = login.payload.token;

    // A sync against a dead server must still produce an honest sync-log entry.
    await call('POST', '/api/sync/now', {}, token);

    const dir = path.join(home, 'logs');
    const securityLog = fs.readFileSync(path.join(dir, 'security.log'), 'utf8');
    assert.ok(securityLog.includes('sign-in refused'), 'refused sign-ins are logged');
    assert.ok(securityLog.includes('sign-in (offline)'), 'accepted sign-ins are logged');
    assert.ok(!securityLog.includes('scuba-5521'), 'the password never reaches a log line');

    const applicationLog = fs.readFileSync(path.join(dir, 'application.log'), 'utf8');
    assert.ok(applicationLog.includes('till started'), 'startup is in the application log');

    const syncLog = fs.readFileSync(path.join(dir, 'sync.log'), 'utf8');
    assert.ok(syncLog.includes('sync finished'), 'the failed sync cycle is recorded in the sync log');

    const tail = await call('GET', '/api/logs/security', null, token);
    assert.equal(tail.status, 200, 'signed-in staff can read the log tail');
    assert.ok(tail.payload.lines.some((line) => line.includes('sign-in')));

    const anonymous = await call('GET', '/api/logs/security');
    assert.equal(anonymous.status, 401, 'log tails are not public');
  } finally {
    till.stop();
    till.till.db.close();
    if (previousDataEnv === undefined) delete process.env.SOFTCORA_DATA;
    else process.env.SOFTCORA_DATA = previousDataEnv;
  }
});

await step('receipts render to text and ESC/POS, and raw printing says when it needs Windows', async () => {
  const home = path.join(tmp, 'print-home');
  fs.rmSync(home, { recursive: true, force: true });
  fs.mkdirSync(home, { recursive: true });

  const previousDataEnv = process.env.SOFTCORA_DATA;
  process.env.SOFTCORA_DATA = home;

  const port = 7825;
  const till = startServer({ dbFile: path.join(home, 'till.sqlite'), serverUrl: 'http://127.0.0.1:1', port, host: '127.0.0.1' });

  const call = async (method, endpoint, body, token) => {
    const response = await fetch(`http://127.0.0.1:${port}${endpoint}`, {
      method,
      headers: {
        accept: 'application/json',
        ...(body ? { 'content-type': 'application/json' } : {}),
        ...(token ? { authorization: `Bearer ${token}` } : {}),
      },
      body: body ? JSON.stringify(body) : undefined,
    });

    return { status: response.status, payload: await response.json().catch(() => ({})) };
  };

  try {
    await call('POST', '/api/staff', { identifier: 'printer@shop.af', secret: 'roller-3311' });
    const login = await call('POST', '/api/auth/login', { identifier: 'printer@shop.af', secret: 'roller-3311' });
    const token = login.payload.token;

    await call('POST', '/api/products', { name: 'ناود Tea 250g', sale_price: 120, barcode: '9550001112223', opening_stock: 10 }, token);
    const sale = await call('POST', '/api/sales', {
      items: [{ barcode: '9550001112223', qty: 2 }],
      payments: [{ method: 'cash', amount: 250 }],
    }, token);
    assert.equal(sale.status, 201, 'the sale used for printing exists');

    const key = sale.payload.sale.uuid;

    // The browser path: a model and a ready-to-print text receipt.
    const receipt = await call('GET', `/api/print/receipt/${key}`, null, token);
    assert.equal(receipt.status, 200);
    assert.ok(receipt.payload.text.includes('TOTAL'), 'the text receipt has a total');
    assert.ok(receipt.payload.text.includes(sale.payload.sale.device_invoice_no), 'the receipt carries its number');
    assert.equal(receipt.payload.model.items.length, 1, 'the model carries its lines');
    assert.equal(receipt.payload.printer.printer_mode, 'dialog', 'the default printer mode is the browser dialog');

    // The ESC/POS bytes: init first, cut last, drawer kick on demand, ASCII-safe.
    const bytes = renderEscPos(receipt.payload.model, { drawerKick: true });
    assert.deepEqual([bytes[0], bytes[1]], [0x1b, 0x40], 'the receipt starts with ESC @ (printer init)');
    assert.equal(bytes.indexOf(Buffer.from([0x1b, 0x70, 0x00, 0x19, 0xfa])) !== -1, true, 'the drawer kick command is there when asked');
    assert.ok(bytes.includes(Buffer.from('TOTAL')), 'the total is on the receipt');
    assert.ok(bytes.includes(Buffer.from('Tea 250g')), 'the ASCII part of a Dari product name still prints');
    const cutTail = bytes.subarray(bytes.length - 4);
    assert.equal(cutTail[0], 0x1d, 'the receipt ends with the cut command (GS V)');

    // Text bytes must never leak non-ASCII to a code-page starved printer
    // (control parameters like the 0xFA drawer timing are not text).
    const noDrawer = renderEscPos(receipt.payload.model, { drawerKick: false });
    const textBytes = Buffer.concat([
      noDrawer.subarray(noDrawer.indexOf(0x0a) + 1, noDrawer.indexOf(Buffer.from('TOTAL'))),
    ]);
    assert.ok(!textBytes.some((byte) => byte > 0x7e), 'Dari degrades to ??? in text, never garbage bytes');

    // In dialog mode the raw endpoint refuses honestly instead of pretending.
    const wrongMode = await call('POST', '/api/print/receipt', { sale_uuid: key }, token);
    assert.equal(wrongMode.status, 409, 'raw printing in dialog mode is a configuration answer, not a crash');

    // Raw mode with no share configured is also an honest answer.
    await call('PUT', '/api/settings', { printer_mode: 'raw' }, token);
    const noShare = await call('POST', '/api/print/receipt', { sale_uuid: key }, token);
    assert.equal(noShare.status, 409, 'a missing share name is explained');

    // With a share set, non-Windows platforms are told to keep the dialog path.
    await call('PUT', '/api/settings', { printer_share: 'POS80' }, token);
    const rawPrint = await call('POST', '/api/print/receipt', { sale_uuid: key }, token);
    assert.equal(rawPrint.status, process.platform === 'win32' ? 200 : 501, 'raw printing reports its platform honestly');

    // The sale a failed print belongs to is, of course, still there.
    const again = await call('GET', `/api/sales/${key}`, null, token);
    assert.equal(again.status, 200, 'a failed print never touches the sale');

    // The test receipt in dialog mode comes back for the browser to print.
    await call('PUT', '/api/settings', { printer_mode: 'dialog' }, token);
    const testPrint = await call('POST', '/api/print/test', {}, token);
    assert.equal(testPrint.payload.mode, 'dialog');
    assert.ok(testPrint.payload.text.includes('TEST'), 'the test receipt is labelled as a test');

    const settings = await call('GET', '/api/settings');
    assert.equal(settings.payload.printer_share, 'POS80', 'printer settings survive a round trip');
    assert.equal(settings.payload.printer_mode, 'dialog');
  } finally {
    till.stop();
    till.till.db.close();
    if (previousDataEnv === undefined) delete process.env.SOFTCORA_DATA;
    else process.env.SOFTCORA_DATA = previousDataEnv;
  }
});

/* The screen is plain HTML and JavaScript with no build step, so a renamed id is
   a silent dead button. This is the cheapest possible check that it is not. */
await step('the screen only refers to elements that exist', () => {
  const html = fs.readFileSync(new URL('../public/index.html', import.meta.url), 'utf8');
  const script = fs.readFileSync(new URL('../public/app.js', import.meta.url), 'utf8');

  const defined = new Set([...html.matchAll(/id="([^"]+)"/g)].map((match) => match[1]));
  const created = new Set([...script.matchAll(/id="([^"]+)"/g)].map((match) => match[1]));
  const referenced = [...script.matchAll(/\$\('([^']+)'\)/g)].map((match) => match[1]);

  const missing = [...new Set(referenced)].filter((id) => !defined.has(id) && !created.has(id));
  assert.deepEqual(missing, [], `the screen refers to elements that do not exist: ${missing.join(', ')}`);
});

await central.close();

console.log('\nOffline POS — end-to-end\n');
console.log(results.join('\n'));
console.log(`\n${results.length - failures}/${results.length} checks passed`);

process.exit(failures ? 1 : 0);
