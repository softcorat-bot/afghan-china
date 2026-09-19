#!/usr/bin/env node
/**
 * Till administration from a terminal — the same operations the screen offers,
 * for installers and technicians with no browser at hand.
 *
 *   node src/cli.mjs init --device SC-POS-KBL-8F31A7 --server https://pos.example.com
 *   node src/cli.mjs register --code ABCD-1234
 *   node src/cli.mjs staff --user cashier@shop.af --password '…' --pin 1111
 *   node src/cli.mjs sync | status | queue | conflicts | backup | verify
 */
import readline from 'node:readline';
import fs from 'node:fs';
import path from 'node:path';
import { defaultDataDir, getMeta, openDatabase, setMeta } from './db.mjs';
import { cacheUser, loadDeviceCredentials, saveDeviceCredentials } from './auth.mjs';
import { registerDevice, SyncClient } from './sync/client.mjs';
import { SyncEngine } from './sync/engine.mjs';
import { counts, recentLog, retryFailed } from './queue.mjs';
import { suggestDeviceId } from './ids.mjs';
import { assertUpgradeSafe, createBackup, restoreBackup } from './backup.mjs';

const [command, ...rest] = process.argv.slice(2);
const flags = parseFlags(rest);
const db = openDatabase();

const deviceId = getMeta(db, 'device_id');

switch (command) {
  case 'init': {
    const id = flags.device || deviceId || suggestDeviceId(flags.branch ?? 'POS');
    setMeta(db, 'device_id', id);
    setMeta(db, 'server_url', flags.server ?? getMeta(db, 'server_url', 'http://localhost:8000'));
    setMeta(db, 'device_name', flags.name ?? 'Offline POS');

    console.log(`Device id   ${id}`);
    console.log(`Server      ${getMeta(db, 'server_url')}`);
    console.log(`Database    ${db.prepare('pragma database_list').get()?.file}`);
    console.log('\nNext: ask an administrator to authorize this device id in Settings → Devices,');
    console.log('then run:  node src/cli.mjs register --code <THEIR-CODE>');
    break;
  }

  case 'register': {
    if (!deviceId) fail('Run `init` first.');

    const serverUrl = flags.server ?? getMeta(db, 'server_url', 'http://localhost:8000');
    const result = await registerDevice({
      baseUrl: serverUrl,
      deviceId,
      activationCode: flags.code,
      name: flags.name ?? getMeta(db, 'device_name', 'Offline POS'),
      platform: process.platform,
      appVersion: '1.0.0',
    });

    setMeta(db, 'server_url', serverUrl);
    setMeta(db, 'company_id', result.company_id);
    setMeta(db, 'branch_id', result.branch_id);
    saveDeviceCredentials({ device_id: deviceId, device_token: result.device_token, company_id: result.company_id, branch_id: result.branch_id });

    console.log(`Registered ${deviceId} (branch ${result.branch_id}). The device token is stored, hashed on the server.`);
    break;
  }

  case 'staff': {
    // Caching a staff member requires their secret once, here — it is hashed
    // immediately and never stored in clear text.
    const secret = flags.password ?? flags.pin;
    if (!flags.user || !secret) fail('Usage: staff --user <email> --password <secret> [--pin 1234]');

    cacheUser(db, { name: flags.name ?? flags.user, email: flags.user, role: flags.role ?? 'Counter' }, secret, {
      type: flags.pin && !flags.password ? 'pin' : 'password',
    });

    console.log(`${flags.user} can now sign in on this till with no internet.`);
    break;
  }

  case 'sync': {
    const engine = engineFor(db, deviceId);
    const result = await engine.syncNow();

    console.log(JSON.stringify(result, null, 2));
    process.exit(result.ok ? 0 : 1);
  }

  case 'status': {
    const engine = engineFor(db, deviceId);
    console.log(JSON.stringify({ ...engine.status(), log: recentLog(db, 5) }, null, 2));
    break;
  }

  case 'queue': {
    const rows = db.prepare('select id, entity_type, status, attempts, error_message from sync_queue order by id desc limit 40').all();
    console.table(rows);
    console.log(counts(db));
    break;
  }

  case 'retry': {
    console.log(`Re-queued ${retryFailed(db, flags.id ? Number(flags.id) : null)} record(s).`);
    break;
  }

  case 'conflicts': {
    console.table(db.prepare('select id, entity_type, severity, status, reason from conflicts order by id desc limit 40').all());
    break;
  }

  case 'backup': {
    const result = createBackup(db, { label: flags.label ?? 'cli' });
    console.log(`Backup: ${result.file} (${result.counts.sales} sales, ${result.counts.pending} unsynced)`);
    break;
  }

  case 'restore': {
    if (!flags.file) fail('Usage: restore --file <backup.json.gz> [--dir <data-dir>]');
    console.log(restoreBackup(flags.file, { dataDir: flags.dir ?? defaultDataDir() }));
    break;
  }

  case 'verify': {
    // What an installer runs before replacing application files.
    console.log(assertUpgradeSafe({ dataDir: defaultDataDir() }));
    break;
  }

  case 'serve': {
    const { startServer } = await import('./server.mjs');
    startServer({ port: Number(flags.port ?? process.env.SOFTCORA_PORT ?? 7817) });
    break;
  }

  default:
    console.log(`SoftCora offline POS

  init      --device <id> --server <url>   set this installation's identity
  register  --code <ACTIVATION-CODE>       exchange the code for a device token
  staff     --user <email> --password|--pin <secret>
  serve     [--port 7817]                  run the till screen
  sync | status | queue | retry | conflicts
  backup | restore --file <backup> | verify
`);
}

function engineFor(database, id) {
  const credentials = loadDeviceCredentials();
  const client = new SyncClient({
    baseUrl: getMeta(database, 'server_url', 'http://localhost:8000'),
    deviceId: id,
    token: credentials?.device_token ?? null,
  });

  return new SyncEngine({ db: database, client, deviceId: id });
}

function parseFlags(args) {
  const result = {};
  for (let index = 0; index < args.length; index++) {
    const arg = args[index];
    if (!arg.startsWith('--')) continue;

    const [key, inline] = arg.slice(2).split('=');
    result[key] = inline ?? (args[index + 1]?.startsWith('--') ? true : args[++index] ?? true);
  }

  return result;
}

function fail(message) {
  console.error(message);
  process.exit(1);
}
