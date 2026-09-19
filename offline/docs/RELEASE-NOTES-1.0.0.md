## SoftCora POS 1.0.0 — the offline Windows till

First packaged release of the offline till: a Windows program that keeps selling
when the internet does not. Sign-in, barcode scan, sales, payments, receipts,
returns, customers, stock movements, drawer sessions, reports and backups all
run against a local SQLite database. Sync hands the work over to the central
server; it never makes selling possible.

### Downloads

| File | Size | SHA-256 |
| --- | --- | --- |
| `SoftCora-POS-Setup.exe` | {{SETUP_SIZE}} | `{{SETUP_SHA256}}` |
| `SoftCoraPOS-portable-win64.zip` | {{ZIP_SIZE}} | `{{ZIP_SHA256}}` |

`sha256.txt` holds both checksums and is attached to this release; it is
authoritative. The build stamps a timestamp into the bundle, so it is not
bit-reproducible — these values belong to *these* artifacts, and a rebuild
produces different ones. Verify before installing:

```powershell
certutil -hashfile SoftCora-POS-Setup.exe SHA256
```

### Requirements

Windows 10/11 x64. No WAMP, XAMPP, PHP, Node.js, Composer, npm or Git — the
program carries its own runtime and its own database engine. No administrator
password is asked for: it installs per user into
`%LOCALAPPDATA%\SoftCoraPOS`.

### Install

1. Double-click `SoftCora-POS-Setup.exe` (from a USB stick is fine). The file is
   **not code-signed**, so SmartScreen warns — choose **More info → Run anyway**.
2. Setup unpacks, runs `install.cmd`, and starts the till.
3. The till opens at <http://127.0.0.1:7817> on a **setup panel**: create the
   first staff sign-in there, and optionally register the till with the server
   using an administrator's activation code. Registration can be skipped — the
   till sells offline and the sales wait for the first **Sync Now**.

Prefer copy-and-run? Unpack `SoftCoraPOS-portable-win64.zip` and run
`app\SoftCora POS.cmd`.

```
%LOCALAPPDATA%\SoftCoraPOS\app      the program (replaced on update)
%LOCALAPPDATA%\SoftCoraPOS\data     database, device identity, backups (never replaced)
%LOCALAPPDATA%\SoftCoraPOS\logs     till.log
```

Updates replace `app\` only. Unsynced sales, the outbox and the device identity
survive every update, and the till refuses to start if an update would have
swapped out its database or lost its identity (`--cli verify`). `uninstall.ps1`
keeps the data folder unless explicitly given `-RemoveData`.

### What is inside

The till is a Node **single executable application**: the whole server (HTTP,
SQLite, sync engine, till screen) is bundled into one CommonJS file, turned into
a preparation blob, and injected into a stock `node.exe`. Nothing is interpreted
from disk at run time, so an installed till cannot be broken by a missing file
or a half-finished update.

| Piece | Provenance |
| --- | --- |
| `node.exe` (Windows x64) | npm `node-win-x64` v26.9.0 — sha256 `8490398f5e0082772dfb0ae5a6ebdff98a97696a20cb9778b4f82eec79b6d0a1`, verified against the registry's published integrity hash |
| SFX stub | npm `maker-7z-sfx` 1.0.3 (`7zsd_All_x64.sfx`) |
| bundler / injector / packer | npm `esbuild`, `postject`, `7zip-bin` |

The installer is a 7-Zip SFX: a real x64 PE program, its configuration, then the
LZMA2 archive — 11 files, `app\SoftCora-POS.exe` being the {{EXE_SIZE}} till.
`app\RUNTIME.txt` records the runtime's checksum so a shop can verify it
independently against <https://nodejs.org/dist/>.

### Checking an installation

```powershell
powershell -ExecutionPolicy Bypass -File "$env:LOCALAPPDATA\SoftCoraPOS\app\verify.ps1"
```

It prints the database path, the device id, registration state, the till's own
sync status (pending, failed, conflicts, last sync, cursor) and the last backup
date. A non-zero **Pending** count is not a fault while a shop is offline.

### Verified for this release

* `npm test` — **16/16 end-to-end checks passed**, covering 20 offline sales,
  5 customers, 3 returns, stock movements and two drawer sessions; a restart; a
  dead server; activation; a full sync; repeated syncing with no duplicates; a
  lost response; a rejected change that keeps its place; incremental pulls;
  backup and restore; and two tills selling at once.
* The build self-tests the exact packaged blob on Linux: it boots on port 7899,
  answers `/api/device`, serves the embedded screen (`/`, `/app.js`,
  `/styles.css`, each checked by HTTP status and content), creates its database
  and runs `--cli status`.
* The installer's PE header, SFX layout and archive CRCs were checked, and the
  portable zip's CRCs were verified.

### Not yet verified

Nothing in this release has been run on a real Windows machine. The 12-step
acceptance test in `offline/docs/WINDOWS-INSTALLER.md` — SmartScreen, install
without an administrator prompt, `verify.ps1`, unplugged-cable selling and
uninstall — still needs a physical Windows 10/11 x64 till to be run against.
Built from commit `{{COMMIT}}`.
