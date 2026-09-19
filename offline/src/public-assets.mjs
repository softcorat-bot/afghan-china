/**
 * The till screen, wherever it happens to live.
 *
 * In development it is a folder next to the source; packaged as a single
 * executable it is embedded in the binary (Node's single-executable assets), so
 * an installed till cannot end up "missing a file" after an update.
 */
import fs from 'node:fs';
import path from 'node:path';
import { moduleDir, requireFromModule } from './runtime-paths.mjs';

const PUBLIC_DIR = path.join(moduleDir, '..', 'public');

const TYPES = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json',
};

let seaModule;

/** Loaded lazily: `node:sea` only exists inside a packaged executable. */
function sea() {
  if (seaModule === undefined) {
    try {
      seaModule = requireFromModule('node:sea');
    } catch {
      seaModule = null;
    }
  }

  return seaModule;
}

export function isPackaged() {
  try {
    return Boolean(sea()?.isSea?.());
  } catch {
    return false;
  }
}

export function assetExists(name) {
  const key = name === '' ? 'index.html' : name;

  if (isPackaged()) {
    try {
      sea().getAsset(key);

      return true;
    } catch {
      return false;
    }
  }

  const file = path.join(PUBLIC_DIR, key);

  return fs.existsSync(file) && fs.statSync(file).isFile();
}

export function readAsset(name) {
  const key = name === '' ? 'index.html' : name;

  if (isPackaged()) {
    const bytes = sea().getAsset(key);

    return typeof bytes === 'string' ? bytes : Buffer.from(bytes).toString('utf8');
  }

  return fs.readFileSync(path.join(PUBLIC_DIR, key), 'utf8');
}

export function contentType(name) {
  return TYPES[path.extname(name)] ?? 'application/octet-stream';
}
