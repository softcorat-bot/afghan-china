#!/usr/bin/env bash
#
# Builds the Windows deliverable:
#
#   dist/SoftCora-POS-Setup.exe          the installer (self-extracting, per-user, no admin)
#   dist/SoftCoraPOS-portable-win64.zip  the same till for copy-and-run
#   dist/sha256.txt                      checksums of everything produced
#
# What it does, in order:
#
#   0. checks the installer scripts can be read by the Windows hosts that run
#      them (ASCII, CRLF, byte-order marks, balanced quoting) — cheap, so it
#      runs before anything is downloaded or compiled
#   1. bundles the till into one CommonJS file            (esbuild)
#   2. builds a Node single-executable blob, embedding the screen (node --experimental-sea-config)
#   3. injects it into a Windows node.exe                 (postject)
#   4. self-tests the *same* blob on Linux, so the packaging is proven here
#   5. packs the payload and glues it to a 7-Zip SFX stub (7zsd_All_x64.sfx)
#
# Requirements (all fetched from the npm registry, nothing else):
#   esbuild, postject, 7zip-bin, node-win-x64   — plus bash and python3.
#
# Usage:  installer/build-windows.sh [--skip-runtime-download]
set -euo pipefail

OFFLINE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
INSTALLER="$OFFLINE/installer"
BUILD="$OFFLINE/build"
DIST="$OFFLINE/dist"
CACHE="$BUILD/cache"
PAYLOAD="$BUILD/payload"
TOOLING="${TOOLING:-$BUILD/tooling}"
NPM="${NPM:-npm}"

ESBUILD="$TOOLING/node_modules/.bin/esbuild"
POSTJECT="$TOOLING/node_modules/.bin/postject"
SEVENZA="$TOOLING/node_modules/7zip-bin/linux/x64/7za"
SFX_SRC="$TOOLING/sfx/7zsd_All_x64.sfx"
NODE="$(command -v node)"

SEA_FUSE="NODE_SEA_FUSE_fce680ab2cc467b6e072b8b5df1996b2"
APP_NAME="SoftCora POS"
EXE_NAME="SoftCora-POS.exe"

log() { printf '\033[1m▸ %s\033[0m\n' "$*"; }
die() { printf '\033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

[ -n "$NODE" ] || die "the build needs Node 22.5 or newer on PATH"

mkdir -p "$BUILD" "$DIST" "$CACHE" "$PAYLOAD"

# --- the tools this build uses -------------------------------------------------
# Kept inside the build folder and fetched on demand, so a fresh checkout builds
# with one command and nothing has to be installed by hand beforehand.
if [ ! -x "$ESBUILD" ] || [ ! -x "$POSTJECT" ] || [ ! -x "$SEVENZA" ]; then
  log "Fetching the build tools (esbuild, postject, 7zip-bin)"
  mkdir -p "$TOOLING"
  # npm installs into the nearest package.json it can find, which here is the
  # offline app one. A marker of our own keeps the tools out of the app.
  printf '{"name":"softcora-pos-build-tools","private":true}\n' > "$TOOLING/package.json"
  ( cd "$TOOLING" \
    && "$NPM" install --no-save --no-audit --no-fund --loglevel=error \
         esbuild postject 7zip-bin ) \
    || die "could not install the build tools into $TOOLING"
fi

# The 7-Zip SFX stub is fetched as a bare tarball on purpose: the npm package
# that ships it depends on @electron-forge, whose tree reaches a git dependency
# that cannot be fetched on every machine. Only a small pe file is really wanted.
if [ ! -f "$SFX_SRC" ]; then
  log "Fetching the 7-Zip SFX stub (maker-7z-sfx)"
  mkdir -p "$(dirname "$SFX_SRC")"
  python3 - "$(dirname "$SFX_SRC")" <<'SFX_PY' || die "could not download the 7-Zip SFX stub"
import json, os, sys, tarfile, urllib.request

target = sys.argv[1]
opener = urllib.request.build_opener()
opener.addheaders = [('user-agent', 'softcora-build')]

with opener.open('https://registry.npmjs.org/maker-7z-sfx', timeout=120) as response:
    meta = json.load(response)

version = meta['dist-tags']['latest']
tarball = meta['versions'][version]['dist']['tarball']
archive = os.path.join(target, 'maker-7z-sfx.tgz')

with opener.open(tarball, timeout=300) as response, open(archive, 'wb') as out:
    out.write(response.read())

with tarfile.open(archive) as tar:
    for member in tar.getmembers():
        if member.isfile() and member.name.endswith('.sfx'):
            member.name = os.path.basename(member.name)
            tar.extract(member, target)

os.remove(archive)
print(f'  maker-7z-sfx {version}: stub extracted into {target}')
SFX_PY
fi

for tool in "$ESBUILD" "$POSTJECT" "$SEVENZA" "$SFX_SRC"; do
  [ -e "$tool" ] || die "missing build tool: $tool"
done

# Vendored binaries arrive from npm without their executable bit on some
# filesystems; the build would otherwise fail at the packing step.
chmod +x "$ESBUILD" "$POSTJECT" "$SEVENZA" 2>/dev/null || true
"$SEVENZA" i >/dev/null 2>&1 || die "the 7-Zip binary at $SEVENZA will not run"

# ── 0. the installer scripts, before anything is built ──────────────────────
# These files are read by cmd.exe and Windows PowerShell 5.1 with the machine's
# own code page, and neither can be run here, so what breaks them is checked
# instead: a single typographic character in a .ps1 is enough to make Windows
# PowerShell misread the whole script and stop the install with an error that
# points somewhere else entirely. Failing here costs seconds; failing on a shop's
# till costs a visit.
log "Checking the installer payload in version control"
"$NODE" "$INSTALLER/check-payload.mjs" "$INSTALLER/payload" || die "the installer payload would not run on a Windows PC"

# ── 1. one file ─────────────────────────────────────────────────────────────
log "Bundling the till (esbuild)"
cd "$OFFLINE"
"$ESBUILD" src/win-main.mjs \
  --bundle --platform=node --format=cjs --target=node22 \
  --outfile="$BUILD/till.cjs" \
  --log-level=warning --log-override:empty-import-meta=silent \
  --banner:js="// SoftCora POS — offline till. Built $(date -u +%Y-%m-%dT%H:%M:%SZ) from $OFFLINE"

# ── 2. the embedded payload (application + screen) ──────────────────────────
log "Building the single-executable blob"
"$NODE" --experimental-sea-config "$INSTALLER/sea-config.json" >/dev/null

# ── 3. a Windows executable ─────────────────────────────────────────────────
NODE_EXE="$CACHE/$EXE_NAME"

if [ ! -f "$NODE_EXE" ]; then
  if [ "${1:-}" = "--skip-runtime-download" ]; then
    die "no cached runtime at $NODE_EXE and downloading was skipped"
  fi

  # nodejs.org is not reachable from every build machine, but the npm registry
  # carries the same runtime as `node-win-x64`. The tarball is only used if it
  # matches the integrity hash the registry publishes for that version.
  log "Fetching the Windows runtime (node.exe) from the npm registry"
  python3 - "$CACHE" <<'FETCH_PY' || die "could not download the Windows runtime"
import base64, hashlib, json, os, sys, urllib.request

cache = sys.argv[1]
opener = urllib.request.build_opener()
opener.addheaders = [('user-agent', 'softcora-build')]

with opener.open('https://registry.npmjs.org/node-win-x64', timeout=120) as response:
    meta = json.load(response)

version = meta['dist-tags']['latest']
dist = meta['versions'][version]['dist']
target = os.path.join(cache, 'runtime.tgz')

with opener.open(dist['tarball'], timeout=900) as response, open(target, 'wb') as out:
    while chunk := response.read(1 << 20):
        out.write(chunk)

with open(target, 'rb') as handle:
    data = handle.read()

algorithm, _, expected = dist.get('integrity', '').partition('-')
if algorithm:
    actual = base64.b64encode(hashlib.new(algorithm, data).digest()).decode()
    if actual != expected:
        os.remove(target)
        raise SystemExit(f'node-win-x64 {version} failed its {algorithm} integrity check')

print(f'  node-win-x64 {version}: {len(data)} bytes, integrity verified')
FETCH_PY

  tar -xzf "$CACHE/runtime.tgz" -C "$CACHE" package/bin/node.exe
  mv "$CACHE/package/bin/node.exe" "$NODE_EXE"
  rm -rf "$CACHE/package" "$CACHE/runtime.tgz"
fi

log "Injecting the blob into $(basename "$NODE_EXE")"
cp "$NODE_EXE" "$BUILD/$EXE_NAME"
"$POSTJECT" "$BUILD/$EXE_NAME" NODE_SEA_BLOB "$BUILD/sea-prep.blob" \
  --sentinel-fuse "$SEA_FUSE" >/dev/null

# ── 4. prove the packaging here, on Linux, with the same blob ───────────────
log "Self-testing the packaged form (Linux host, same blob, same assets)"
SELFCHECK="$BUILD/softcora-pos-selfcheck"
cp "$NODE" "$SELFCHECK"
"$POSTJECT" "$SELFCHECK" NODE_SEA_BLOB "$BUILD/sea-prep.blob" --sentinel-fuse "$SEA_FUSE" >/dev/null

SELFCHECK_DATA="$(mktemp -d)"
SOFTCORA_DATA="$SELFCHECK_DATA" SOFTCORA_PORT=7899 SOFTCORA_HOST=127.0.0.1 "$SELFCHECK" >"$BUILD/selfcheck.log" 2>&1 &
SELFCHECK_PID=$!
trap 'kill $SELFCHECK_PID 2>/dev/null || true' EXIT

for _ in $(seq 1 40); do
  sleep 0.25
  if curl -sf --max-time 2 http://127.0.0.1:7899/api/device >/dev/null; then break; fi
done

fetch() {
  # Assets are fetched to files rather than piped into `grep -q`: a pipeline
  # that ends early can mask a real failure, and when something is wrong the
  # response is worth printing.
  local path="$1" out="$2"
  local code
  code="$(curl -s -o "$out" -w '%{http_code}' --max-time 5 "http://127.0.0.1:7899$path" || echo 000)"
  printf '    %s -> %s (%s bytes)\n' "$path" "$code" "$(wc -c < "$out" | tr -d ' ')"

  [ "$code" = "200" ] || { head -c 400 "$out"; echo; return 1; }
}

fetch /api/device "$BUILD/selfcheck-device.json" >/dev/null || { cat "$BUILD/selfcheck.log"; die "the packaged till did not answer on its API"; }
fetch / "$BUILD/selfcheck-index.html" || { cat "$BUILD/selfcheck.log"; die "the embedded screen was not served"; }
grep -q "SoftCora POS" "$BUILD/selfcheck-index.html" || die "the served screen is not the till's screen"
fetch /app.js "$BUILD/selfcheck-app.js" || die "the embedded script was not served"
fetch /styles.css "$BUILD/selfcheck-styles.css" || die "the embedded stylesheet was not served"
"$SELFCHECK" --cli status >/dev/null || die "the packaged CLI did not run"
[ -f "$SELFCHECK_DATA/softcora-pos.sqlite" ] || die "the packaged till did not create its database"
rm -f "$BUILD"/selfcheck-device.json "$BUILD"/selfcheck-index.html "$BUILD"/selfcheck-app.js "$BUILD"/selfcheck-styles.css

kill $SELFCHECK_PID 2>/dev/null || true
trap - EXIT
rm -rf "$SELFCHECK_DATA" "$SELFCHECK"
log "  ✓ packaged form boots, serves the embedded screen, creates its database and runs the CLI"

# ── 5. the payload the installer ships ──────────────────────────────────────
log "Assembling the payload"
rm -rf "$PAYLOAD"
mkdir -p "$PAYLOAD/app"

cp "$BUILD/$EXE_NAME" "$PAYLOAD/app/"
cp "$OFFLINE/README.md" "$PAYLOAD/app/README.md"
cp "$INSTALLER/payload/SoftCora POS.cmd" "$PAYLOAD/app/"
cp "$INSTALLER/payload/start-till.cmd" "$PAYLOAD/app/"
cp "$INSTALLER/payload/VERSION.txt" "$PAYLOAD/app/"
cp "$INSTALLER/payload/install.ps1" "$INSTALLER/payload/uninstall.ps1" "$INSTALLER/payload/verify.ps1" "$PAYLOAD/"
cp "$INSTALLER/payload/install.cmd" "$PAYLOAD/"
cp "$INSTALLER/payload/README-FIRST.txt" "$PAYLOAD/"

# The Windows runtime's provenance, so a shop can verify it independently.
{
  echo "runtime: node $(basename "$NODE_EXE") from npm package node-win-x64"
  echo "sha256(node.exe): $(sha256sum "$NODE_EXE" | cut -d' ' -f1)"
  echo "verify against: https://nodejs.org/dist/  (SHASUMS256.txt)"
} > "$PAYLOAD/app/RUNTIME.txt"

# Windows text files are where this build has been bitten before, so the payload
# is normalised *and* verified rather than just normalised:
#
#   * cmd.exe mis-parses LF-only .cmd files (labels and parenthesised blocks),
#     and prints a byte-order mark as a stray character before @echo off;
#   * Windows PowerShell 5.1 reads a .ps1 that has no byte-order mark with the
#     machine's ANSI code page, where the UTF-8 bytes of a typographic dash
#     decode into a character PowerShell accepts as a string delimiter - one em
#     dash in a comment is then enough to make the whole script unparseable, with
#     an error reported far from the character that caused it.
#
# So: CRLF everywhere, ASCII everywhere, no mark on .cmd, a UTF-8 mark on the
# .ps1 copies that ship, and the quoting proved to balance read both ways. The
# real PowerShell parser runs too when pwsh is installed (it is on the CI
# runners), which is the last word on whether a script parses.
log "Checking the installer payload (ASCII, CRLF, marks, quoting)"
"$NODE" "$INSTALLER/check-payload.mjs" "$PAYLOAD" --fix --bom --no-pwsh
"$NODE" "$INSTALLER/check-payload.mjs" "$PAYLOAD" --require-crlf --require-bom

# ── 6. the installer: a real .exe, assembled from a 7-Zip SFX stub ──────────
log "Packing the installer"
cd "$PAYLOAD"
"$SEVENZA" a -t7z -mx=9 -m0=lzma2 "$BUILD/payload.7z" ./* >/dev/null

# A 7-Zip SFX has three parts, in this order:
#     [ the stub (a real Windows PE program) ]
#     [ ;!@Install@!UTF-8! config … ;!@InstallEnd@! ]
#     [ the 7z archive ]
# The config sits *between* them: the stub reads its own tail, stops at the
# terminator, and treats everything after it as the archive.
cat "$INSTALLER/sfx-config.txt" > "$BUILD/setup.sfx"
printf ';!@InstallEnd@!\r\n' >> "$BUILD/setup.sfx"

cat "$SFX_SRC" "$BUILD/setup.sfx" "$BUILD/payload.7z" > "$DIST/SoftCora-POS-Setup.exe"

# ── 7. the portable alternative ─────────────────────────────────────────────
log "Writing the portable zip"
python3 - "$PAYLOAD" "$DIST/SoftCoraPOS-portable-win64.zip" <<'PY'
import os, sys, zipfile
root, out = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as z:
    for folder, _dirs, files in os.walk(root):
        for name in files:
            full = os.path.join(folder, name)
            z.write(full, os.path.relpath(full, root))
print(f"  {out}")
PY

# ── 8. checksums ────────────────────────────────────────────────────────────
cd "$DIST"
sha256sum SoftCora-POS-Setup.exe SoftCoraPOS-portable-win64.zip > sha256.txt
sha256sum "$BUILD/$EXE_NAME" > SoftCora-POS.exe.sha256

# ── 9. the release notes the publish step uploads ───────────────────────────
# The notes live in version control (docs/RELEASE-NOTES-<version>.md, because
# dist/ is not tracked) and are copied here with this build's own measurements
# filled in, so a release can be cut straight after a build with:
#   gh release create v<version> dist/… --notes-file dist/RELEASE-NOTES.md
# The bundle is not bit-reproducible (esbuild stamps a timestamp into it), so
# the checksums are substituted rather than maintained by hand.
VERSION="$(sed -n 's/^SoftCora POS //p' "$INSTALLER/payload/VERSION.txt" | tr -d '\r')"
NOTES_SRC="$OFFLINE/docs/RELEASE-NOTES-$VERSION.md"
if [ -f "$NOTES_SRC" ]; then
  python3 - "$NOTES_SRC" "$DIST" "$PAYLOAD/app/$EXE_NAME" "$OFFLINE" <<'NOTES_PY'
import hashlib, os, re, subprocess, sys

src, dist, exe, offline = sys.argv[1:5]

def sha256(path):
    digest = hashlib.sha256()
    with open(path, 'rb') as handle:
        while chunk := handle.read(1 << 20):
            digest.update(chunk)
    return digest.hexdigest()

def size(path):
    n = os.path.getsize(path)
    return f'{n:,} bytes ({n / 1048576:.1f} MiB)'

commit = subprocess.run(['git', '-C', offline, 'rev-parse', '--short', 'HEAD'],
                        capture_output=True, text=True, check=False).stdout.strip() or 'unknown'

fields = {
    'SETUP_SHA256': sha256(os.path.join(dist, 'SoftCora-POS-Setup.exe')),
    'SETUP_SIZE': size(os.path.join(dist, 'SoftCora-POS-Setup.exe')),
    'ZIP_SHA256': sha256(os.path.join(dist, 'SoftCoraPOS-portable-win64.zip')),
    'ZIP_SIZE': size(os.path.join(dist, 'SoftCoraPOS-portable-win64.zip')),
    'EXE_SIZE': f'{os.path.getsize(exe):,}-byte',
    'COMMIT': commit,
}

notes = open(src, encoding='utf-8').read()
for key, value in fields.items():
    notes = notes.replace('{{' + key + '}}', value)

unfilled = sorted(set(re.findall(r'\{\{(\w+)\}\}', notes)))
if unfilled:
    raise SystemExit(f'the release notes ask for values the build does not provide: {unfilled}')

with open(os.path.join(dist, 'RELEASE-NOTES.md'), 'w', encoding='utf-8') as out:
    out.write(notes)
print(f'  notes: {len(fields)} values filled in from this build')
NOTES_PY
  log "Copied the $VERSION release notes into $(basename "$DIST")"
else
  log "No release notes for $VERSION at ${NOTES_SRC#"$OFFLINE"/} — writing none"
fi

log "Done"
ls -la "$DIST"
echo
echo "  installer : $DIST/SoftCora-POS-Setup.exe   ($(du -h "$DIST/SoftCora-POS-Setup.exe" | cut -f1))"
echo "  portable  : $DIST/SoftCoraPOS-portable-win64.zip ($(du -h "$DIST/SoftCoraPOS-portable-win64.zip" | cut -f1))"
echo "  checksums : $(cat "$DIST/sha256.txt" | tr '\n' ' ')"
