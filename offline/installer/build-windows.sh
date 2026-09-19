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
TOOLING="${TOOLING:-/home/user/tooling}"
INSTALLER="$OFFLINE/installer"
BUILD="$OFFLINE/build"
DIST="$OFFLINE/dist"
CACHE="$BUILD/cache"
PAYLOAD="$BUILD/payload"

ESBUILD="$TOOLING/node_modules/.bin/esbuild"
POSTJECT="$TOOLING/node_modules/.bin/postject"
SEVENZA="$TOOLING/node_modules/7zip-bin/linux/x64/7za"
NODE="$(command -v node)"

SEA_FUSE="NODE_SEA_FUSE_fce680ab2cc467b6e072b8b5df1996b2"
APP_NAME="SoftCora POS"
EXE_NAME="SoftCora-POS.exe"

log() { printf '\033[1m▸ %s\033[0m\n' "$*"; }
die() { printf '\033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

for tool in "$ESBUILD" "$POSTJECT" "$SEVENZA" "$NODE"; do
  [ -x "$tool" ] || die "missing build tool: $tool (run: npm install esbuild postject 7zip-bin in $TOOLING)"
done

mkdir -p "$BUILD" "$DIST" "$CACHE" "$PAYLOAD"

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

  log "Fetching the Windows runtime (node.exe) from the npm registry"
  RUNTIME_VERSION="$(node -e "console.log(require('$TOOLING/node_modules/node-win-x64/package.json').version)" 2>/dev/null || echo '26.9.0')"

  curl -sS --max-time 300 -o "$CACHE/runtime.tgz" \
    "https://registry.npmjs.org/node-win-x64/-/node-win-x64-${RUNTIME_VERSION}.tgz" \
    || die "could not download the Windows runtime"

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

curl -sf --max-time 5 http://127.0.0.1:7899/api/device >/dev/null || { cat "$BUILD/selfcheck.log"; die "the packaged till did not answer on its API"; }
curl -sf --max-time 5 http://127.0.0.1:7899/ | grep -q "SoftCora POS" || die "the embedded screen was not served"
"$SELFCHECK" --cli status >/dev/null || die "the packaged CLI did not run"
[ -f "$SELFCHECK_DATA/softcora-pos.sqlite" ] || die "the packaged till did not create its database"

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

# Windows text files must have CRLF endings: cmd.exe mis-parses LF-only .cmd
# files. Enforced here so a checkout on any platform builds a runnable payload.
python3 - "$PAYLOAD" <<'PY'
import glob, os, sys
root = sys.argv[1]
for pattern in ('**/*.cmd', '**/*.ps1', '**/*.txt'):
    for path in glob.glob(os.path.join(root, pattern), recursive=True):
        with open(path, 'rb') as fh:
            data = fh.read()
        fixed = data.replace(b'\r\n', b'\n').replace(b'\n', b'\r\n')
        if fixed != data:
            with open(path, 'wb') as fh:
                fh.write(fixed)
            print(f"  crlf: {os.path.relpath(path, root)}")
PY

# ── 6. the installer: a real .exe, assembled from a 7-Zip SFX stub ──────────
log "Packing the installer"
cd "$PAYLOAD"
"$SEVENZA" a -t7z -mx=9 -m0=lzma2 "$BUILD/payload.7z" ./* >/dev/null

SFX_SRC="$TOOLING/node_modules/maker-7z-sfx/7zsd_extra_162_3888/7zsd_All_x64.sfx"
[ -f "$SFX_SRC" ] || die "the 7-Zip SFX stub is missing (npm install maker-7z-sfx)"

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

log "Done"
ls -la "$DIST"
echo
echo "  installer : $DIST/SoftCora-POS-Setup.exe   ($(du -h "$DIST/SoftCora-POS-Setup.exe" | cut -f1))"
echo "  portable  : $DIST/SoftCoraPOS-portable-win64.zip ($(du -h "$DIST/SoftCoraPOS-portable-win64.zip" | cut -f1))"
echo "  checksums : $(cat "$DIST/sha256.txt" | tr '\n' ' ')"
