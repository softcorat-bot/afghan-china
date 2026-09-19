/**
 * Signing in with no internet — safely.
 *
 * The till caches the staff accounts it is allowed to use, but it caches
 * *hashes*: scrypt with a per-user random salt for passwords and PINs. There is
 * no plaintext anywhere in the database, so a stolen laptop leaks no password
 * the cashier might have reused somewhere that matters.
 *
 * The device's own server token is a different secret, kept in a file only the
 * OS user can read (0600); the desktop shell replaces that file with the OS
 * keychain, which is the same contract with a better lock.
 */
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { defaultDataDir, getMeta, setMeta } from './db.mjs';
import { nowIso, uuid } from './ids.mjs';

const SCRYPT = { N: 16384, r: 8, p: 1, keylen: 64 };
const SESSION_HOURS = 12;

export function hashSecret(secret, salt = crypto.randomBytes(16).toString('hex')) {
  const derived = crypto.scryptSync(String(secret), salt, SCRYPT.keylen, { N: SCRYPT.N, r: SCRYPT.r, p: SCRYPT.p });
  return `scrypt$${SCRYPT.N}$${salt}$${derived.toString('hex')}`;
}

export function verifySecret(secret, stored) {
  if (!stored || !stored.startsWith('scrypt$')) return false;

  const [, n, salt, expected] = stored.split('$');
  const derived = crypto.scryptSync(String(secret), salt, SCRYPT.keylen, { N: Number(n), r: SCRYPT.r, p: SCRYPT.p });
  const a = Buffer.from(expected, 'hex');
  const b = derived;

  return a.length === b.length && crypto.timingSafeEqual(a, b);
}

/** Cache (or refresh) a staff member so they can sign in while offline. */
export function cacheUser(db, user, secret, { type = 'password' } = {}) {
  // SQLite bindings are strict: undefined is not a value, null is.
  const bind = (value) => (value === undefined ? null : value);

  const existing = db.prepare('select id from users where uuid = ? or (email is not null and email = ?)')
    .get(bind(user.uuid), bind(user.email));

  const record = {
    uuid: user.uuid ?? uuid(),
    name: bind(user.name) ?? 'Staff',
    username: bind(user.username),
    email: bind(user.email),
    role: bind(user.role) ?? 'Counter',
    permissions: JSON.stringify(user.permissions ?? []),
    active: user.active === false ? 0 : 1,
    updated_at: nowIso(),
  };

  if (existing) {
    db.prepare(`update users set name=?, username=?, email=?, role=?, permissions=?, active=?, updated_at=?,
      ${type === 'pin' ? 'pin_hash' : 'password_hash'}=? where id=?`)
      .run(record.name, record.username, record.email, record.role, record.permissions, record.active,
        record.updated_at, hashSecret(secret), existing.id);
    return existing.id;
  }

  const info = db.prepare(`insert into users (uuid, name, username, email, role, permissions, active, updated_at,
      ${type === 'pin' ? 'pin_hash' : 'password_hash'}) values (?,?,?,?,?,?,?,?,?)`)
    .run(record.uuid, record.name, record.username, record.email, record.role, record.permissions, record.active,
      record.updated_at, hashSecret(secret));

  return Number(info.lastInsertRowid);
}

/** Sign in against the local cache. Works with the network cable unplugged. */
export function login(db, { identifier, secret, allowPin = true }) {
  const user = db.prepare(`select * from users
      where active = 1 and (email = ? or username = ? or name = ?)
      limit 1`).get(identifier, identifier, identifier);

  if (!user) return { ok: false, reason: 'unknown_user' };

  const byPassword = verifySecret(secret, user.password_hash);
  const byPin = allowPin && verifySecret(secret, user.pin_hash);

  if (!byPassword && !byPin) return { ok: false, reason: 'bad_credentials' };

  const token = crypto.randomBytes(32).toString('hex');
  const expires = new Date(Date.now() + SESSION_HOURS * 3600 * 1000).toISOString();

  db.prepare('insert into sessions (token_hash, user_id, created_at, expires_at, device_id) values (?,?,?,?,?)')
    .run(sha256(token), user.id, nowIso(), expires, getMeta(db, 'device_id'));

  db.prepare('update users set last_login_at = ? where id = ?').run(nowIso(), user.id);

  return { ok: true, token, expires_at: expires, user: publicUser(user) };
}

export function resolveSession(db, token) {
  if (!token) return null;

  const row = db.prepare('select * from sessions where token_hash = ?').get(sha256(token));
  if (!row) return null;

  if (row.expires_at && new Date(row.expires_at) < new Date()) {
    db.prepare('delete from sessions where token_hash = ?').run(row.token_hash);
    return null;
  }

  const user = db.prepare('select * from users where id = ?').get(row.user_id);

  return user ? publicUser(user) : null;
}

export function logout(db, token) {
  if (token) db.prepare('delete from sessions where token_hash = ?').run(sha256(token));
}

export function publicUser(user) {
  return {
    id: user.id,
    uuid: user.uuid,
    name: user.name,
    email: user.email,
    role: user.role,
    permissions: safeJson(user.permissions, []),
  };
}

/* ── the device's own credential ─────────────────────────────────────────── */

function deviceFile(dir = defaultDataDir()) {
  return path.join(dir, 'device.json');
}

export function saveDeviceCredentials({ device_id, device_token, company_id, branch_id, token_file } = {}, dir = defaultDataDir()) {
  const file = token_file || deviceFile(dir);
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, JSON.stringify({ device_id, device_token, company_id, branch_id, saved_at: nowIso() }, null, 2), {
    mode: 0o600,
  });
  try { fs.chmodSync(file, 0o600); } catch { /* Windows: the profile ACL is the lock */ }

  return file;
}

export function loadDeviceCredentials(dir = defaultDataDir()) {
  const file = deviceFile(dir);
  if (!fs.existsSync(file)) return null;

  try {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch {
    return null;
  }
}

export function sha256(value) {
  return crypto.createHash('sha256').update(String(value)).digest('hex');
}

function safeJson(value, fallback) {
  try { return JSON.parse(value); } catch { return fallback; }
}

export { setMeta };
