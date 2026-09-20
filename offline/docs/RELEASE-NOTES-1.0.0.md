## SoftCora POS 1.0.0 — the offline Windows till

First packaged release of the offline till: a Windows program that keeps selling
when the internet does not. Sign-in, barcode scan, sales, payments, receipts,
returns, customers, stock movements, drawer sessions, reports and backups all
run against a local SQLite database. Sync hands the work over to the central
server; it never makes selling possible.

> **Re-published 2026-09-20.** The first build tagged 1.0.0 did not install: its
> installer scripts were UTF-8 without a byte-order mark and contained
> typographic dashes, which Windows PowerShell 5.1 reads with the machine's ANSI
> code page — where an em dash decodes into a character PowerShell accepts as a
> *string delimiter*, so `install.ps1` stopped parsing and setup ended with
> `Unexpected token ')' in expression or statement.` Nothing was installed and no
> data was touched. **If you downloaded 1.0.0 before that date, download it
> again**: the bytes and the checksums below are different. The till itself is
> unchanged; the payload is now ASCII, every shipped `.ps1` carries a UTF-8
> mark, and both are enforced by the build and by CI — including a job that has
> Windows PowerShell 5.1 parse and run the scripts itself.
>
> Because the version did not change, the `v1.0.0` tag still points at the
> commit that shipped the broken build; the assets on this release page were
> re-published from the commit named at the foot of these notes.

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
2. Setup unpacks, runs `install.cmd`, and starts the till. It checks before it
   changes anything: that the packaged program really runs on this PC, that the
   disk has room, and — on an update — that the existing database is safe to
   carry over. If any answer is no, it stops, changes nothing, and says why in
   `logs\install.log`.
3. The till opens at <http://127.0.0.1:7817> on a **setup panel**: create the
   first staff sign-in there, and optionally register the till with the server
   using an administrator's activation code. Registration can be skipped — the
   till sells offline and the sales wait for the first **Sync Now**.

Prefer copy-and-run? Unpack `SoftCoraPOS-portable-win64.zip` and run
`app\SoftCora POS.cmd`.

```
%LOCALAPPDATA%\SoftCoraPOS\app      the program (replaced on update)
%LOCALAPPDATA%\SoftCoraPOS\data     database, device identity, backups (never replaced)
%LOCALAPPDATA%\SoftCoraPOS\logs     install.log, till.log and the till's own logs
```

Updates replace `app\` only, and keep the port this PC already uses, so a
bookmarked till screen does not move. Unsynced sales, the outbox and the device
identity survive every update, and the till refuses to start if an update would
have swapped out its database or lost its identity (`--cli verify`).
`uninstall.ps1` asks first, keeps the data folder unless explicitly given
`-RemoveData`, and — when deleting that folder would lose records that have not
reached the server — asks for the word `DELETE` before touching them.

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

**Start menu → SoftCora POS → Health check**, or:

```powershell
powershell -ExecutionPolicy Bypass -File "$env:LOCALAPPDATA\SoftCoraPOS\app\verify.ps1"
powershell -ExecutionPolicy Bypass -File "$env:LOCALAPPDATA\SoftCoraPOS\app\verify.ps1" -Json
```

It reports the installed version, the program's path, the database and when it
was last written, the device id and registration state, whether the till is
answering on the port this install chose, the till's own sync status (pending,
failed, conflicts, last sync and what it uploaded) and the newest backup — with
anything wrong listed under *Problems found*. It changes nothing. Exit codes:
`0` healthy, `1` not installed, `2` installed with problems. A non-zero
**Pending** count is not a fault while a shop is offline.

### Verified for this release

* `npm test` — **18/18 end-to-end checks passed**: selling before a drawer
  session exists; 20 offline sales, 5 customers, 3 returns, 10 stock movements
  and 2 cash sessions; a restart; a dead server; activation; a full sync;
  repeated syncing with no duplicates; a lost response; a rejected change that
  keeps its place; incremental pulls; backup and restore; two tills never
  colliding; the states the till reports; setup with no terminal; logs split by
  channel with no secrets inside; receipts in text and ESC/POS; and the screen
  referring only to elements that exist. Plus **11/11 installer checks**.
* `installer/check-payload.mjs` on the packed payload, read back out of the
  finished `SoftCora-POS-Setup.exe`: ASCII with zero non-ASCII bytes, UTF-8
  marked `.ps1`, unmarked `.cmd`, CRLF throughout, quoting balanced read as
  UTF-8 *and* as cp1252, no PowerShell 7-only syntax, and every batch label
  resolved — including in the two `.cmd` launchers `install.ps1` generates.
* Windows PowerShell 5.1 parses every payload script in CI (`windows-latest`),
  runs `verify.ps1` on a PC with nothing installed (exit 1, and it says so) and
  runs `install.ps1` against a payload with no program in it (exit 1, no `app\`
  or `data\` created, reason in `install.log`).
* The build self-tests the exact packaged blob on Linux: it boots on port 7899,
  answers `/api/device`, serves the embedded screen (`/`, `/app.js`,
  `/styles.css`, each checked by HTTP status and content), creates its database
  and runs `--cli status`.
* The installer's PE header, SFX layout and archive CRCs were checked, and the
  portable zip's CRCs were verified.

### Not yet verified

No Windows machine has yet run this build all the way through. What CI does run
on a real `windows-latest` machine is the half that failed last time: Windows
PowerShell 5.1 parses every payload script, `verify.ps1` runs where nothing is
installed (exit 1, and it says so), and `install.ps1` runs against a payload with
no program in it — step **1b** of the acceptance test in
`offline/docs/WINDOWS-INSTALLER.md`: exit 1, no `app\` or `data\` created, reason
in `install.log`.

Still needing a physical Windows 10/11 x64 till: a **successful** install from
this `.exe` and its log (steps 1, 1a), `verify.ps1` afterwards (step 2),
registration against a live server (3), unplugged-cable selling and the sync
that follows it (4–9), an update over unsynced sales keeping the port (10),
uninstall (11, 11a) and backup/restore onto a second machine (12). Steps 1, 1a
and 2 are the ones 1.0.0's first build failed, so run them on the bench PC
before a till goes on a counter.

Built from commit `{{COMMIT}}`.
