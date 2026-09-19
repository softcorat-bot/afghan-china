/**
 * The till's local service: HTTP on 127.0.0.1 (and 0.0.0.0 when asked), with
 * SQLite behind it. The screen talks to this, never to the internet — which is
 * why unplugging the network changes nothing about selling.
 */
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';

import {
  addCashMovement, closeSession, createCustomer, createProduct, createSale, findByBarcode, findCustomer, getSale,
  openSession, openSessionRow, refundSale, salesSummary, searchCustomers, searchProducts, sessionTotals,
} from './pos.mjs';
import { assetExists, contentType, readAsset } from './public-assets.mjs';
import { cacheUser, loadDeviceCredentials, login as localLogin, logout, resolveSession, saveDeviceCredentials } from './auth.mjs';
import { getMeta, openDatabase, setMeta } from './db.mjs';
import { SyncClient, registerDevice } from './sync/client.mjs';
import { SyncEngine } from './sync/engine.mjs';
import { counts, recentLog, retryFailed } from './queue.mjs';
import { createBackup } from './backup.mjs';
import { nowIso, suggestDeviceId } from './ids.mjs';
import { isMainModule, moduleDir } from './runtime-paths.mjs';

const PUBLIC_DIR = path.join(moduleDir, '..', 'public');

export function createTill({ dbFile = null, serverUrl = null, port = 7817, host = '0.0.0.0' } = {}) {
  const db = openDatabase(dbFile ?? undefined);
  const deviceId = getMeta(db, 'device_id');

  const credentials = loadDeviceCredentials();
  const client = new SyncClient({
    baseUrl: serverUrl ?? getMeta(db, 'server_url', 'http://localhost:8000'),
    deviceId,
    token: credentials?.device_token ?? null,
  });

  const engine = new SyncEngine({ db, client, deviceId, onProgress: (event) => broadcast(event) });

  // A device that is not registered yet simply cannot sync; it sells anyway.
  if (deviceId) setMeta(db, 'device_id', deviceId);

  return { db, engine, client, deviceId, port, host };
}

const clients = new Set();

function broadcast(payload) {
  const data = `data: ${JSON.stringify(payload)}\n\n`;
  for (const res of clients) {
    try { res.write(data); } catch { clients.delete(res); }
  }
}

export function startServer({ dbFile = null, serverUrl = null, port = process.env.SOFTCORA_PORT ?? 7817, host = '0.0.0.0' } = {}) {
  const till = createTill({ dbFile, serverUrl, port, host });
  const { db, engine } = till;

  const server = http.createServer(async (request, response) => {
    const url = new URL(request.url, `http://${request.headers.host ?? 'localhost'}`);

    // The screen may be opened from another origin (the shop's own browser or a
    // preview host), so the local API is explicit about being reachable.
    response.setHeader('access-control-allow-origin', request.headers.origin ?? '*');
    response.setHeader('access-control-allow-headers', 'content-type, authorization, x-session');
    response.setHeader('access-control-allow-methods', 'GET, POST, PUT, DELETE, OPTIONS');

    if (request.method === 'OPTIONS') {
      response.writeHead(204).end();
      return;
    }

    try {
      if (url.pathname.startsWith('/api/')) {
        await handleApi({ request, response, url, till });
        return;
      }

      serveStatic(request, response, url);
    } catch (error) {
      send(response, error.status ?? 500, { message: error.message });
    }
  });

  server.listen(port, host, () => {
    console.log(`SoftCora offline POS listening on http://${host}:${port}`);
    console.log(`Data: ${db.prepare('pragma database_list').get()?.file}`);
  });

  // Automatic sync is a convenience, never a requirement: it runs quietly in the
  // background and grows a log line when it fails.
  let timer = null;
  const schedule = () => {
    if (timer) clearInterval(timer);
    const minutes = Number(getMeta(db, 'auto_sync_minutes', '5'));
    if (getMeta(db, 'auto_sync', '1') !== '1' || !(minutes > 0)) return;

    timer = setInterval(() => { engine.syncNow({ force: false }).catch(() => {}); }, minutes * 60 * 1000);
    timer.unref?.();
  };

  schedule();

  return { server, till, stop: () => { if (timer) clearInterval(timer); server.close(); } };
}

async function handleApi({ request, response, url, till }) {
  const { db, engine, client } = till;
  const body = await readBody(request);
  const sessionToken = (request.headers.authorization ?? '').replace(/^Bearer\s+/i, '') || request.headers['x-session'];
  const user = resolveSession(db, sessionToken);

  const route = `${request.method} ${url.pathname}`;

  switch (true) {
    /* ── device identity ─────────────────────────────────────────────── */
    case route === 'GET /api/device':
      return send(response, 200, {
        device_id: getMeta(db, 'device_id'),
        registered: Boolean(loadDeviceCredentials()?.device_token),
        server_url: getMeta(db, 'server_url'),
        branch_id: getMeta(db, 'branch_id'),
        company_id: getMeta(db, 'company_id'),
        connectivity: getMeta(db, 'connectivity'),
      });

    case route === 'POST /api/device/register': {
      // First run may register itself (that is what the activation code is
      // for); once staff exist, changing this till's identity needs a sign-in.
      if (Number(db.prepare('select count(*) c from users').get().c) > 0) requireUser(user);

      const deviceId = body.device_id || getMeta(db, 'device_id') || suggestDeviceId(body.branch ?? 'POS');
      const serverUrl = body.server_url || getMeta(db, 'server_url', 'http://localhost:8000');

      const result = await registerDevice({
        baseUrl: serverUrl, deviceId, activationCode: body.activation_code,
        name: body.name ?? 'Offline POS', platform: process.platform, appVersion: '1.0.0',
      });

      setMeta(db, 'device_id', deviceId);
      setMeta(db, 'server_url', serverUrl);
      setMeta(db, 'company_id', result.company_id);
      setMeta(db, 'branch_id', result.branch_id);
      setMeta(db, 'branch_name', result.branch?.name ?? '');

      saveDeviceCredentials({
        device_id: deviceId,
        device_token: result.device_token,
        company_id: result.company_id,
        branch_id: result.branch_id,
      });

      return send(response, 201, { device_id: deviceId, status: result.device?.status, branch_id: result.branch_id });
    }

    /* ── opening the till ───────────────────────────────────────────── */
    case route === 'POST /api/auth/login': {
      // While the server is reachable the login goes through it (so roles and
      // permissions are current) and the credentials are cached as a hash for
      // the next time the internet is gone.
      if (body.online && body.server_url) {
        try {
          const remote = await remoteLogin(body.server_url, body.identifier, body.secret);
          const userId = cacheUser(db, remote.user, body.secret, { type: body.use_pin ? 'pin' : 'password' });
          setMeta(db, 'last_online_login_at', nowIso());

          const local = localLogin(db, { identifier: remote.user.email ?? body.identifier, secret: body.secret, allowPin: true });
          return send(response, 200, { ...local, cached_user_id: userId, source: 'server' });
        } catch (error) {
          // Fall through to the local cache — a broken server must not lock the
          // cashier out of their own till.
        }
      }

      const result = localLogin(db, { identifier: body.identifier, secret: body.secret, allowPin: body.use_pin !== false });

      return send(response, result.ok ? 200 : 401, result);
    }

    case route === 'POST /api/auth/logout':
      logout(db, sessionToken);
      return send(response, 200, { ok: true });

    /* ── first run ───────────────────────────────────────────────────── */
    /* A till that has just been installed has no users, so nobody can sign
       in yet — and a shop with no terminal at hand must never be stuck behind
       a sign-in it cannot pass. This is the one endpoint that answers what a
       brand-new till needs to know, and it is the only thing the screen shows
       until the first sign-in exists. */
    case route === 'GET /api/setup': {
      const staffCount = db.prepare('select count(*) c from users').get().c;

      return send(response, 200, {
        needs_setup: Number(staffCount) === 0,
        staff_count: Number(staffCount),
        device_id: getMeta(db, 'device_id'),
        device_name: getMeta(db, 'device_name'),
        registered: Boolean(loadDeviceCredentials()?.device_token),
        server_url: getMeta(db, 'server_url'),
        branch_id: getMeta(db, 'branch_id'),
        data_dir: db.prepare('pragma database_list').get()?.file ?? null,
        app_version: '1.0.0',
      });
    }

    case route === 'GET /api/staff': {
      // Listing who can sign in is not a secret on the shop's own machine, and
      // it has to work before the first sign-in exists.
      const rows = db.prepare('select id, uuid, name, email, role, active from users order by id').all();
      return send(response, 200, { data: rows });
    }

    case route === 'GET /api/auth/me':
      return send(response, user ? 200 : 401, { user });

    /* ── catalogue & customers ──────────────────────────────────────── */
    case route === 'GET /api/products':
      return send(response, 200, { data: searchProducts(db, { query: url.searchParams.get('q') ?? '', limit: Number(url.searchParams.get('limit') ?? 30) }) });

    case url.pathname.startsWith('/api/products/barcode/'): {
      const code = decodeURIComponent(url.pathname.split('/').pop());
      const product = findByBarcode(db, code);

      return send(response, product ? 200 : 404, product ?? { message: `No product with barcode ${code}` });
    }

    case route === 'POST /api/products':
      requireUser(user);
      return send(response, 201, { product: createProduct(db, { deviceId: till.deviceId, user, ...body }) });

    case route === 'GET /api/customers':
      return send(response, 200, { data: searchCustomers(db, { query: url.searchParams.get('q') ?? '' }) });

    // First-run provisioning: a fresh till has no staff at all, so the person
    // installing it may add the first sign-in. After that it needs a signed-in
    // user, and only the hash of what they typed is ever stored.
    case route === 'POST /api/staff': {
      const staffCount = db.prepare('select count(*) c from users').get().c;

      if (Number(staffCount) > 0) requireUser(user);
      if (!body.identifier || !body.secret) return send(response, 422, { message: 'A user and a password or PIN are required.' });

      const id = cacheUser(db, {
        uuid: body.uuid ?? undefined,
        name: body.name ?? body.identifier,
        email: body.identifier,
        role: body.role ?? 'Counter',
      }, body.secret, { type: body.use_pin ? 'pin' : 'password' });

      return send(response, 201, { ok: true, user_id: id, message: `${body.identifier} can now sign in on this till with no internet.` });
    }

    case route === 'POST /api/customers':
      requireUser(user);
      return send(response, 201, { customer: createCustomer(db, { deviceId: till.deviceId, user, ...body }) });

    case route === 'GET /api/customers/lookup':
      return send(response, 200, { customer: findCustomer(db, { phone: url.searchParams.get('phone') }) });

    /* ── selling ────────────────────────────────────────────────────── */
    case route === 'POST /api/sales': {
      requireUser(user);
      const sale = createSale(db, {
        deviceId: till.deviceId,
        user,
        session: openSessionRow(db),
        items: body.items ?? [],
        payments: body.payments ?? [],
        customerId: body.customer_id ?? null,
        billDiscount: body.bill_discount ?? 0,
        note: body.note ?? null,
      });

      return send(response, 201, { sale });
    }

    case route === 'GET /api/sales':
      return send(response, 200, { data: db.prepare('select * from sales order by id desc limit 50').all() });

    case url.pathname.startsWith('/api/sales/') && request.method === 'GET':
      return send(response, 200, { sale: getSale(db, decodeURIComponent(url.pathname.split('/').pop())) });

    case url.pathname.endsWith('/refund') && request.method === 'POST': {
      requireUser(user);
      const saleUuid = decodeURIComponent(url.pathname.split('/')[3]);

      return send(response, 201, { refund: refundSale(db, { deviceId: till.deviceId, saleUuid, user, ...body }) });
    }

    /* ── drawer ─────────────────────────────────────────────────────── */
    case route === 'GET /api/session':
      return send(response, 200, { session: openSessionRow(db) });

    case route === 'POST /api/session/open':
      requireUser(user);
      return send(response, 201, { session: openSession(db, { deviceId: till.deviceId, user, ...body }) });

    case route === 'GET /api/session/current/totals': {
      const open = openSessionRow(db);

      return send(response, 200, { totals: open ? sessionTotals(db, open.id) : null });
    }

    case url.pathname.endsWith('/movement') && request.method === 'POST': {
      requireUser(user);
      const sessionId = Number(url.pathname.split('/')[3]);

      return send(response, 201, { session: addCashMovement(db, { deviceId: till.deviceId, sessionId, user, ...body }) });
    }

    case url.pathname.endsWith('/close') && request.method === 'POST': {
      requireUser(user);
      const sessionId = Number(url.pathname.split('/')[3]);

      return send(response, 200, { totals: closeSession(db, { deviceId: till.deviceId, sessionId, user, ...body }) });
    }

    case url.pathname.endsWith('/z') && request.method === 'GET':
      return send(response, 200, { totals: sessionTotals(db, Number(url.pathname.split('/')[3])) });

    /* ── synchronization ────────────────────────────────────────────── */
    case route === 'GET /api/status':
      return send(response, 200, {
        status: engine.status(),
        queue: counts(db),
        log: recentLog(db, 10),
        connectivity: getMeta(db, 'connectivity'),
      });

    case route === 'POST /api/sync/now': {
      const result = await engine.syncNow({ skipPush: Boolean(body.skip_push), skipPull: Boolean(body.skip_pull) });

      return send(response, 200, result);
    }

    case route === 'POST /api/sync/probe':
      return send(response, 200, await engine.probe());

    case route === 'POST /api/sync/retry':
      return send(response, 200, { retried: retryFailed(db, body.id ?? null), queue: counts(db) });

    case route === 'GET /api/sync/stream':
      response.writeHead(200, {
        'content-type': 'text/event-stream',
        'cache-control': 'no-cache',
        connection: 'keep-alive',
      });
      response.write(`data: ${JSON.stringify({ phase: 'hello', status: engine.status() })}\n\n`);
      clients.add(response);
      request.on('close', () => clients.delete(response));
      return;

    case route === 'GET /api/sync/queue': {
      const status = url.searchParams.get('status');
      const rows = status
        ? db.prepare('select * from sync_queue where status = ? order by id desc limit 200').all(status)
        : db.prepare('select * from sync_queue order by id desc limit 200').all();

      return send(response, 200, { data: rows });
    }

    case route === 'GET /api/conflicts':
      return send(response, 200, {
        data: db.prepare('select * from conflicts order by id desc limit 200').all(),
        pending: db.prepare("select count(*) c from conflicts where status = 'pending'").get().c,
      });

    /* ── reports, settings, backup ──────────────────────────────────── */
    case route === 'GET /api/reports/summary':
      return send(response, 200, salesSummary(db, {
        from: url.searchParams.get('from'),
        to: url.searchParams.get('to'),
      }));

    case route === 'GET /api/settings':
      return send(response, 200, {
        auto_sync: getMeta(db, 'auto_sync', '1') === '1',
        auto_sync_minutes: Number(getMeta(db, 'auto_sync_minutes', '5')),
        server_url: getMeta(db, 'server_url'),
        receipt_header: getMeta(db, 'receipt_header'),
        receipt_footer: getMeta(db, 'receipt_footer'),
        currency: getMeta(db, 'currency', 'AFN'),
        return_window_days: Number(getMeta(db, 'return_window_days', '7')),
      });

    case route === 'PUT /api/settings': {
      requireUser(user);
      for (const key of ['auto_sync', 'auto_sync_minutes', 'server_url', 'receipt_header', 'receipt_footer', 'currency', 'return_window_days']) {
        if (body[key] !== undefined) setMeta(db, key, typeof body[key] === 'boolean' ? (body[key] ? '1' : '0') : String(body[key]));
      }

      return send(response, 200, { ok: true });
    }

    case route === 'POST /api/backup': {
      requireUser(user);

      return send(response, 201, createBackup(db, { label: body.label ?? 'manual' }));
    }

    default:
      return send(response, 404, { message: `Unknown route ${route}` });
  }
}

function requireUser(user) {
  if (!user) {
    const error = new Error('Sign in first.');
    error.status = 401;
    throw error;
  }

  return user;
}

async function remoteLogin(serverUrl, identifier, secret) {
  const base = String(serverUrl).replace(/\/+$/, '');
  const response = await fetch(`${base}/api/login`, {
    method: 'POST',
    headers: { 'content-type': 'application/json', accept: 'application/json' },
    body: JSON.stringify({ email: identifier, password: secret }),
  });

  if (!response.ok) throw new Error(`Central sign-in refused (${response.status}).`);

  const payload = await response.json();
  if (!payload.user) throw new Error('Central sign-in returned no user.');

  return payload;
}

function serveStatic(request, response, url) {
  const relative = url.pathname === '/' ? 'index.html' : url.pathname.replace(/^\/+/, '');

  // A packaged till carries its screen inside the executable; a development one
  // reads it from disk. Same files either way.
  if (relative.includes('..') || !assetExists(relative)) {
    response.writeHead(404, { 'content-type': 'text/plain' }).end('Not found');

    return;
  }

  const body = readAsset(relative);
  response.writeHead(200, { 'content-type': contentType(relative), 'content-length': Buffer.byteLength(body) });
  response.end(body);
}

function readBody(request) {
  return new Promise((resolve) => {
    if (request.method === 'GET' || request.method === 'HEAD') return resolve({});

    let data = '';
    request.on('data', (chunk) => { data += chunk; });
    request.on('end', () => {
      try { resolve(data ? JSON.parse(data) : {}); } catch { resolve({}); }
    });
  });
}

function send(response, status, payload) {
  if (response.writableEnded) return;
  const body = JSON.stringify(payload ?? {});
  response.writeHead(status, { 'content-type': 'application/json; charset=utf-8', 'content-length': Buffer.byteLength(body) });
  response.end(body);
}

if (isMainModule(import.meta.url)) {
  startServer({
    dbFile: process.env.SOFTCORA_DB ?? null,
    serverUrl: process.env.SOFTCORA_SERVER ?? null,
    port: Number(process.env.SOFTCORA_PORT ?? 7817),
  });
}
