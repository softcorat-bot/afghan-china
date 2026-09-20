/**
 * The till's file logs.
 *
 * Four channels, four files, one format:
 *
 *   application.log — start/stop, backups, restores, configuration changes
 *   sync.log        — every sync cycle: what went up, what came down, what failed
 *   error.log       — unhandled errors, failed requests, permanent queue failures
 *   security.log    — sign-ins (ok and refused), sign-outs, registration, staff
 *                     changes, token events
 *
 * Rules this file exists to keep:
 *   - Never log a secret. Passwords, PINs, device tokens and session tokens are
 *     scrubbed before a line is written.
 *   - Logs are capped: at ~2 MB a file rolls (three generations kept), so a till
 *     that runs for years does not fill its disk.
 *   - Writing logs must never break the till: any logging failure degrades to a
 *     console line and nothing else.
 */
import fs from 'node:fs';
import path from 'node:path';
import { defaultDataDir } from './db.mjs';

const MAX_BYTES = 2 * 1024 * 1024;
const GENERATIONS = 3;

/** Where the logs live. `%LOCALAPPDATA%\SoftCoraPOS\logs` on an installed till. */
export function logsDir() {
  if (process.env.SOFTCORA_LOGS) return process.env.SOFTCORA_LOGS;

  const data = defaultDataDir();

  // The installed layout is <root>\data and <root>\logs side by side; a
  // development checkout keeps its logs next to its data directory instead.
  if (path.basename(data) === 'data') return path.join(path.dirname(data), 'logs');

  return path.join(data, 'logs');
}

const SECRET_PATTERNS = [
  /("(?:password|pass|secret|pin|token|device_token|api_token|activation_code|session|authorization)"\s*:\s*")([^"]+)(")/gi,
  /((?:password|secret|pin|token|bearer)\s*[=:]\s*)(\S+)/gi,
  /(scrypt\$\d+\$)[a-f0-9]+\$[a-f0-9]+/gi,
];

/** Scrub anything that could ever be a credential from a log line. */
export function redact(value) {
  let text = typeof value === 'string' ? value : JSON.stringify(value);
  if (text === undefined) return '';

  for (const pattern of SECRET_PATTERNS) {
    text = text.replace(pattern, '$1***$3');
  }

  return text;
}

function serialize(fields) {
  if (!fields || typeof fields !== 'object') return '';

  const safe = {};
  for (const [key, value] of Object.entries(fields)) {
    if (value === undefined) continue;
    safe[key] = typeof value === 'string' ? value : value;
  }

  const rendered = Object.entries(safe)
    .map(([key, value]) => `${key}=${typeof value === 'string' ? value : redact(value)}`)
    .join(' ');

  return rendered ? ` ${redact(rendered)}` : '';
}

function roll(file) {
  try {
    for (let i = GENERATIONS - 1; i >= 1; i--) {
      const older = `${file}.${i}`;
      const newer = `${file}.${i + 1}`;
      if (fs.existsSync(older)) {
        if (i + 1 > GENERATIONS) fs.rmSync(older, { force: true });
        else fs.renameSync(older, newer);
      }
    }
    if (fs.existsSync(file)) fs.renameSync(file, `${file}.1`);
  } catch { /* rotation is best-effort; logging must go on */ }
}

function writer(channel) {
  return (level, message, fields = null) => {
    // The directory is resolved per write: SOFTCORA_DATA can change between
    // module load and the first line (tests, installers pointing at a profile).
    const dir = logsDir();
    const file = path.join(dir, `${channel}.log`);
    const line = `${new Date().toISOString()} [${level}] ${redact(String(message))}${serialize(fields)}\n`;

    try {
      fs.mkdirSync(dir, { recursive: true });
      if (fs.existsSync(file) && fs.statSync(file).size > MAX_BYTES) roll(file);
      fs.appendFileSync(file, line);
    } catch (error) {
      console.error(`log ${channel} unavailable: ${error.message}`);
    }

    // The service console is still captured by start-till.cmd → logs\till.log.
    if (level === 'ERROR') console.error(line.trimEnd());
    else if (level !== 'DEBUG' || process.env.SOFTCORA_DEBUG) console.log(line.trimEnd());
  };
}

const channels = {
  application: writer('application'),
  sync: writer('sync'),
  error: writer('error'),
  security: writer('security'),
};

export const logs = {
  app: (message, fields) => channels.application('INFO', message, fields),
  appWarn: (message, fields) => channels.application('WARN', message, fields),
  sync: (message, fields) => channels.sync('INFO', message, fields),
  syncWarn: (message, fields) => channels.sync('WARN', message, fields),
  error: (message, fields) => channels.error('ERROR', message, fields),
  security: (message, fields) => channels.security('INFO', message, fields),
  securityWarn: (message, fields) => channels.security('WARN', message, fields),
};

/** Read the tail of a channel for the diagnostics endpoint. */
export function readLogTail(channel, maxLines = 100) {
  const file = path.join(logsDir(), `${channel}.log`);

  try {
    if (!fs.existsSync(file)) return [];
    const lines = fs.readFileSync(file, 'utf8').split('\n').filter((line) => line.trim());
    return lines.slice(-maxLines);
  } catch {
    return [];
  }
}
