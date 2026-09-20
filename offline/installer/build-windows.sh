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
#   1. fetches the Windows runtime the till ships with, pinned by version, and a
#      runtime of that *same* version that can run on this build host
#   2. bundles the till into one CommonJS file            (esbuild)
#   3. builds a Node single-executable blob, embedding the screen — with the
#      pinned runtime, never with the build host's own Node
#                                                          (node --experimental-sea-config)
#   4. injects it into that Windows node.exe              (postject)
#   5. self-tests the *same* blob here, by running it under the pinned version,
#      so what is proved is the combination the shop's PC will run
#   6. packs the payload and glues it to a 7-Zip SFX stub (7zsd_All_x64.sfx)
#
# ⚠️ The blob and the binary that reads it must be the SAME Node version. A SEA
#    blob is an internal, version-specific serialization: node's deserializer
#    validates the format field it finds and *aborts the process* when the blob
#    was written by a different version. Building a blob with the host's Node and
#    injecting it into a newer node.exe produces an .exe that starts, aborts with
#    an assertion about SeaDeserializer, and installs nowhere. So the version is
#    pinned below, the blob is prepared by a runtime fetched *at that version*,
#    the version is read back out of both files, and the build fails if they
#    disagree. See docs/WINDOWS-INSTALLER.md → "The blob and the runtime".
#
# Requirements (all fetched from the npm registry, nothing else):
#   esbuild, postject, 7zip-bin, node-win-x64, node-<host>-<arch> — plus bash
#   and python3. The Node that runs this script is a build tool and nothing
#   else; what the till ships with is the pinned runtime.
#
# Usage:  installer/build-windows.sh [--skip-runtime-download]
#         SOFTCORA_NODE_VERSION=26.9.0 installer/build-windows.sh   (to move the pin)
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

# ── the runtime, pinned ─────────────────────────────────────────────────────
# The version the till ships with. Both the blob and the binary that reads it
# come from this one number, and nothing else in the build may bring a Node
# version of its own: see the ⚠️ note at the top of this file.
#
# Moving the pin is a one-line change plus a rebuild. The build stops, rather
# than shipping, if this version is not published for Windows x64, or if the
# runtime it fetched does not report the version it was asked for.
NODE_VERSION="${SOFTCORA_NODE_VERSION:-26.9.0}"
TARGET_PKG='node-win-x64'

log() { printf '\033[1m▸ %s\033[0m\n' "$*"; }
die() { printf '\033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

[ -n "$NODE" ] || die "the build needs Node 22.5 or newer on PATH"

# A second runtime, of the same version, that this machine can actually run: it
# prepares the SEA blob and then runs it for the self-test. Without it the only
# Node available here is the build host's own, and using that is exactly how an
# installer once shipped that aborted on every customer PC.
case "$(uname -s)" in
  Linux)  HOST_OS='linux' ;;
  Darwin) HOST_OS='darwin' ;;
  MINGW*|MSYS*|CYGWIN*) HOST_OS='win' ;;
  *) die "unsupported build host: $(uname -s). Build on Linux, or a Windows shell with bash and python3." ;;
esac

case "$(uname -m)" in
  x86_64|amd64)   HOST_CPU='x64' ;;
  aarch64|arm64)  HOST_CPU='arm64' ;;
  *) die "unsupported build host: $(uname -m). Build on x64 or arm64." ;;
esac

HOST_PKG="node-$HOST_OS-$HOST_CPU"
NODE_EXE="$CACHE/node-v$NODE_VERSION-win-x64.exe"
HOST_NODE="$CACHE/node-v$NODE_VERSION-$HOST_OS-$HOST_CPU"
[ "$HOST_PKG" = "$TARGET_PKG" ] && HOST_NODE="$NODE_EXE"

log "Runtime: node $NODE_VERSION for Windows x64 ($TARGET_PKG), built and self-tested by $HOST_PKG at the same version"

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

# ── 1. the runtimes ─────────────────────────────────────────────────────────
# Two runtimes, one version. The Windows one is what the till ships as; the
# other one runs *here*, so the blob can be prepared and then actually executed
# by a Node of the same version the shop's PC will execute it with. The build
# host's own Node never touches the blob - see the warning at the top of this
# file for what that costs.
#
# nodejs.org is not reachable from every build machine, but the npm registry
# carries the same runtime per platform. A tarball is only used if it matches
# the integrity hash the registry publishes for that version, and what comes out
# of it is checked against the pinned version - for the Windows binary by
# reading the version out of its own version resource, since it cannot be run
# here.
fetch_runtime() {
  # <npm package> <path inside the tarball> <where to put it> [--cache-only]
  python3 - "$1" "$2" "$3" "$CACHE" "$NODE_VERSION" "${4:-}" <<'FETCH_PY'
import base64, hashlib, json, os, shutil, sys, tarfile, urllib.request

package, member, target, cache, version, *flags = sys.argv[1:]
cache_only = '--cache-only' in flags

opener = urllib.request.build_opener()
opener.addheaders = [('user-agent', 'softcora-build')]


def file_version(path):
    """The FileVersion string out of a Windows executable's version resource."""
    with open(path, 'rb') as handle:
        data = handle.read()

    marker = 'FileVersion'.encode('utf-16-le')
    at = data.find(marker)
    if at < 0:
        return None

    cursor = at + len(marker)
    while data[cursor:cursor + 2] == b'\x00\x00':
        cursor += 2

    text = []
    while cursor + 1 < len(data) and data[cursor] != 0 and data[cursor + 1] == 0:
        text.append(chr(data[cursor]))
        cursor += 2

    return ''.join(text) or None


with opener.open(f'https://registry.npmjs.org/{package}', timeout=120) as response:
    meta = json.load(response)

# The version comes from the pin, never from `latest`: a Node release landing
# between two builds must not silently change what the shops run.
if version not in meta['versions']:
    newest = meta.get('dist-tags', {}).get('latest', 'unknown')
    raise SystemExit(f'{package} has no version {version} (its newest is {newest}). '
                     f'Move the pin in build-windows.sh, or build on a host this version covers.')

dist = meta['versions'][version]['dist']
archive = os.path.join(cache, f'{package}-{version}.tgz')

if not os.path.exists(archive):
    if cache_only:
        raise SystemExit(f'{archive} is not cached and downloads were skipped')
    partial = archive + '.part'
    with opener.open(dist['tarball'], timeout=900) as response, open(partial, 'wb') as out:
        while chunk := response.read(1 << 20):
            out.write(chunk)
    os.replace(partial, archive)

with open(archive, 'rb') as handle:
    data = handle.read()

# A cached tarball is verified again, every build: the point of the check is
# that whatever ends up inside the installer is the runtime that was asked for.
algorithm, _, expected = dist.get('integrity', '').partition('-')
if algorithm:
    actual = base64.b64encode(hashlib.new(algorithm, data).digest()).decode()
    if actual != expected:
        os.remove(archive)
        raise SystemExit(f'{package} {version} failed its {algorithm} integrity check')

if not os.path.exists(target):
    partial = target + '.part'
    with tarfile.open(archive) as tar:
        entry = tar.extractfile('package/' + member)
        if entry is None:
            raise SystemExit(f'{package} {version} has no {member} in it')
        with open(partial, 'wb') as out:
            shutil.copyfileobj(entry, out)
    os.replace(partial, target)
    os.chmod(target, 0o755)

if target.endswith('.exe'):
    reported = file_version(target)
    if reported != version:
        raise SystemExit(f'{target} is not {version}: it reports FileVersion {reported!r}. '
                         f'The blob it is about to receive would abort at startup.')

print(f'  {package} {version}: {member} -> {os.path.basename(target)} '
      f'({os.path.getsize(target):,} bytes, integrity verified)')
FETCH_PY
}

# --skip-runtime-download means what it says: use what is already in
# build/cache, and stop rather than fetch anything.
CACHE_ONLY=''
if [ "${1:-}" = "--skip-runtime-download" ]; then CACHE_ONLY='--cache-only'; fi

log "Fetching node $NODE_VERSION (Windows x64, and $HOST_OS-$HOST_CPU to build with)"
fetch_runtime "$TARGET_PKG" 'bin/node.exe' "$NODE_EXE" "$CACHE_ONLY" || die "could not fetch the Windows runtime"
if [ "$HOST_NODE" != "$NODE_EXE" ]; then
  fetch_runtime "$HOST_PKG" 'bin/node' "$HOST_NODE" "$CACHE_ONLY" || die "could not fetch the $HOST_PKG runtime"
fi

# What the build host will run the blob with must be the pinned version, proved
# by asking it - a name on a file is not a version. Then the same question is
# asked of the blob's other half, the Windows binary, which cannot be run here;
# fetch_runtime reads its version resource for that, above.
HOST_REPORTED="$("$HOST_NODE" -p 'process.versions.node')"
[ "$HOST_REPORTED" = "$NODE_VERSION" ] || die "$HOST_PKG reports $HOST_REPORTED, not the pinned $NODE_VERSION"
log "  build runtime verified: $HOST_PKG reports $HOST_REPORTED"

# ── 2. one file ─────────────────────────────────────────────────────────────
log "Bundling the till (esbuild)"
cd "$OFFLINE"
"$ESBUILD" src/win-main.mjs \
  --bundle --platform=node --format=cjs --target=node22 \
  --outfile="$BUILD/till.cjs" \
  --log-level=warning --log-override:empty-import-meta=silent \
  --banner:js="// SoftCora POS — offline till. Built $(date -u +%Y-%m-%dT%H:%M:%SZ) from $OFFLINE"

# ── 3. the embedded payload (application + screen) ──────────────────────────
# The blob is prepared by the pinned runtime, NOT by `$NODE`: what writes it must
# be the version that reads it. esbuild above may run on whatever Node is on
# PATH, because it only produces JavaScript.
log "Building the single-executable blob with node $NODE_VERSION"
"$HOST_NODE" --experimental-sea-config "$INSTALLER/sea-config.json" >/dev/null

# ── 4. a Windows executable ─────────────────────────────────────────────────
log "Injecting the blob into $(basename "$NODE_EXE")"
cp "$NODE_EXE" "$BUILD/$EXE_NAME"
"$POSTJECT" "$BUILD/$EXE_NAME" NODE_SEA_BLOB "$BUILD/sea-prep.blob" \
  --sentinel-fuse "$SEA_FUSE" >/dev/null

# ── 5. prove the packaging here, with the same blob ─────────────────────────
# The blob, the asset embedding and the entry point are the same bytes on every
# platform, so running it under a Windows runtime's *version* is a real test of
# what ships. That is why the binary below is the fetched one and not the build
# host's own node: injecting into the host's Node proves only that a blob works
# with the version that wrote it, which is exactly the mistake this build made
# once. The one thing this cannot prove is the Windows binary itself; CI runs
# the finished .exe on Windows for that (`.github/workflows/offline-till.yml`).
log "Self-testing the packaged form (host runtime, node $NODE_VERSION)"
SELFCHECK="$BUILD/softcora-pos-selfcheck"
cp "$HOST_NODE" "$SELFCHECK"
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

# ── 6. the payload the installer ships ──────────────────────────────────────
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

# The Windows runtime's provenance, so a shop can verify it independently - and
# so the one fact that decides whether this program starts at all is written
# down where it can be read back from an installed till: the version of the
# runtime, which is also the version that wrote the embedded blob. install.ps1
# logs this file into install.log, and support reads the runtime out of it.
{
  echo "runtime: node $NODE_VERSION for Windows x64, from npm package $TARGET_PKG"
  echo "sha256(node.exe): $(sha256sum "$NODE_EXE" | cut -d' ' -f1)"
  echo "verify against: https://nodejs.org/dist/  (SHASUMS256.txt)"
  echo
  echo "This program is that runtime with the till embedded in it."
  echo "The embedded blob was written by node $NODE_VERSION too, which is required:"
  echo "a single-executable blob is only readable by the exact version that wrote"
  echo "it, so a mismatch aborts the program at startup with a SeaDeserializer"
  echo "assertion. The build pins the version for both halves and checks it."
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

# ── 7. the installer: a real .exe, assembled from a 7-Zip SFX stub ──────────
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

# The permanent download link on the releases page serves this name
# (https://github.com/<owner>/<repo>/releases/download/latest/Afghan-China-Setup.exe),
# and a rename at upload time would leave the local checksums describing files
# that are not on disk. So the alias is produced here, byte-identical to the
# canonical installer, and both names are covered by sha256.txt.
cp "$DIST/SoftCora-POS-Setup.exe" "$DIST/Afghan-China-Setup.exe"

# ── 8. the portable alternative ─────────────────────────────────────────────
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

# ── 9. checksums ────────────────────────────────────────────────────────────
cd "$DIST"
sha256sum SoftCora-POS-Setup.exe Afghan-China-Setup.exe SoftCoraPOS-portable-win64.zip > sha256.txt
sha256sum "$BUILD/$EXE_NAME" > SoftCora-POS.exe.sha256

# ── 10. the release notes the publish step uploads ──────────────────────────
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
echo "  runtime   : node $NODE_VERSION ($TARGET_PKG), blob written and self-tested by $HOST_PKG $HOST_REPORTED"
echo "  installer : $DIST/SoftCora-POS-Setup.exe   ($(du -h "$DIST/SoftCora-POS-Setup.exe" | cut -f1))"
echo "  alias     : $DIST/Afghan-China-Setup.exe   (same bytes — the permanent-link name)"
echo "  portable  : $DIST/SoftCoraPOS-portable-win64.zip ($(du -h "$DIST/SoftCoraPOS-portable-win64.zip" | cut -f1))"
echo "  checksums : $(cat "$DIST/sha256.txt" | tr '\n' ' ')"
