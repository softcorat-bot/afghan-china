/**
 * Where this code lives.
 *
 * Running from source, every module is a real file on disk and its location
 * matters. Packaged into the single-executable till, the modules are inside the
 * binary and there is no directory at all — the screen is read from the embedded
 * assets instead (see public-assets.mjs). Both cases have to load without
 * throwing, so this module answers "is there a file?" once, safely.
 */
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

function currentModuleFile() {
  try {
    const url = import.meta?.url;
    return url ? fileURLToPath(url) : null;
  } catch {
    // A bundled build replaces import.meta with an empty object; not an error.
    return null;
  }
}

/** Absolute path of this module, or null when it is not a file on disk. */
export const moduleFile = currentModuleFile();

/** Directory holding the source, falling back to the working directory. */
export const moduleDir = moduleFile ? path.dirname(moduleFile) : process.cwd();

/** `require`, usable from ESM and from a bundled CommonJS build alike. */
export const requireFromModule = createRequire(moduleFile ?? process.execPath);

/**
 * True when this module is the file Node was asked to run.
 *
 * The caller passes its own `import.meta.url`: each module knows its own
 * location, so asking "was I the entry point?" only makes sense about the module
 * doing the asking. Packaged (or otherwise bundled) builds have no URL to give
 * and get `false` — there the entry point is the executable itself.
 */
export function isMainModule(moduleUrl, argv1 = process.argv[1]) {
  if (!moduleUrl || !argv1) return false;
  try {
    return path.resolve(fileURLToPath(moduleUrl)) === path.resolve(argv1);
  } catch {
    return false;
  }
}
