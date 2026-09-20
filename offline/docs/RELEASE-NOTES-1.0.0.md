## SoftCora POS 1.0.0 — the offline Windows till

First packaged release of the offline till: a Windows program that keeps selling
when the internet does not. Sign-in, barcode scan, sales, payments, receipts,
returns, customers, stock movements, drawer sessions, reports and backups all
run against a local SQLite database. Sync hands the work over to the central
server; it never makes selling possible.

> **Re-published twice on 2026-09-20 — download 1.0.0 again if your copy is
> older than this page.** Two different packaging faults each stopped setup on a
> real PC while every check in the build pipeline passed:
>
> 1. **Build 1 — the installer scripts were misread.** They were UTF-8 without a
>    byte-order mark and contained typographic dashes. Windows PowerShell 5.1
>    reads such a file with the machine's ANSI code page, where an em dash
>    decodes into a character PowerShell accepts as a *string delimiter* — so
>    `install.ps1` stopped parsing and setup ended with `Unexpected token ')' in
>    expression or statement.` Nothing was installed and no data was touched.
> 2. **Build 2 — the program aborted at startup.** It was packaged with the
>    build machine's Node 22 while the till ships a Node 26 runtime. A
>    single-executable blob is only readable by the exact version that wrote it,
>    so `SoftCora-POS.exe` died before it read anything of the shop's:
>    `Assertion failed: (format_value) <= (static_cast<uint8_t>(ModuleFormat::kModule))`
>    (in `SeaDeserializer::Read`). Setup stopped with *"SoftCora-POS.exe did not
>    run on this PC"*, changed nothing, and left the data folder alone.
>
> **If you downloaded 1.0.0 before this page was last published, download it
> again**: the bytes and the checksums below are different. This build is also
> the first one that CI *ran on Windows* — unpacked, started, installed and
> health-checked — before it was published. The till itself has not changed; both
> faults were in how the installer and its program were packaged.
>
> Because the version did not change, the `v1.0.0` tag still points at the commit
> that shipped the first broken build; the assets on this release page were
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
| `node.exe` (Windows x64) | npm `node-win-x64` v26.9.0 — sha256 `8490398f5e0082772dfb0ae5a6ebdff98a97696a20cb9778b4f82eec79b6d0a1`, verified against the registry's published integrity hash. The embedded blob is written by a runtime of that same version, and the build stops if the two disagree |
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

* `npm test` — **18/18 end-to-end checks passed** (selling before a drawer
  session exists; 20 offline sales, 5 customers, 3 returns, 10 stock movements
  and 2 cash sessions; a restart; a dead server; activation; a full sync;
  repeated syncing with no duplicates; a lost response; a rejected change that
  keeps its place; incremental pulls; backup and restore; two tills never
  colliding; the states the till reports; setup with no terminal; logs split by
  channel with no secrets inside; receipts in text and ESC/POS; and the screen
  referring only to elements that exist). The suite is also run under
  **node 26.9.0**, the runtime this installer carries.
* **14/14 installer checks**, including three that were added because of the two
  faults above — and each of which is proven to *fail* when its fault is put
  back: a typographic dash in a payload script; a SEA blob written or self-tested
  by the build host's own Node; and a publish workflow that does not wait for the
  Windows check.
* `installer/check-payload.mjs` on the packed payload, read back out of the
  finished `SoftCora-POS-Setup.exe`: ASCII with zero non-ASCII bytes, UTF-8
  marked `.ps1`, unmarked `.cmd`, CRLF throughout, quoting balanced read as
  UTF-8 *and* as cp1252, no PowerShell 7-only syntax, and every batch label
  resolved — including in the two `.cmd` launchers `install.ps1` generates.
* The build fetches **both halves of the program at one pinned version**
  (node 26.9.0): the Windows runtime, and a runtime for the build host. The host
  runtime must report the pinned version; the Windows binary's version is read
  out of its own version resource; a cached runtime carries its version in its
  file name, so a bump cannot reuse the old one. Then the packaged blob is
  self-tested under that same version: it boots on port 7899, answers
  `/api/device`, serves the embedded screen (`/`, `/app.js`, `/styles.css`, each
  checked by HTTP status and content), creates its database and runs
  `--cli status`.
* **The built installer is unpacked and run on a real Windows machine in CI,
  before anything is published** (`installer/verify-windows.ps1`): its program
  runs `--cli status` and `--cli verify` in a throw-away data folder, the till
  starts and serves its screen and API, `install.cmd` installs it into
  `%LOCALAPPDATA%`, `install.log` is read back with no `ERROR` lines, the
  installed bytes are compared with the payload, the shop's data folder is
  checked untouched, `verify.ps1` reports a healthy installation, and the
  installed version matches the payload. The publishing workflow runs exactly
  this, as a separate job, between building and uploading.
* Windows PowerShell 5.1 parses every payload script and the verifier on that
  runner, runs `verify.ps1` on a PC with nothing installed (exit 1, and it says
  so) and runs `install.ps1` against a payload with no program in it (exit 1, no
  `app\` or `data\` created, reason in `install.log`).
* The installer's PE header, SFX layout and archive CRCs were checked, and the
  portable zip's CRCs were verified.

### Not yet verified

What CI cannot do is use the till as a shop does, and the parts of the
acceptance test in `offline/docs/WINDOWS-INSTALLER.md` that need a person at a
machine are still yours to run:

* the SmartScreen warning on a clean PC (**More info → Run anyway** — the file is
  not code-signed), and the shortcuts and *Apps & features* entry as a user sees
  them (step 1);
* registering against a live server (3), selling with the cable unplugged and the
  sync that follows (4–9), an update over unsynced sales keeping the same port
  (10), uninstall (11, 11a) and backup/restore onto a second machine (12);
* a real 80 mm printer, a power cut mid-sale, and two devices over the shop LAN.

Everything up to and including a successful install, its log and its health
check is now covered on every change — including the two steps that failed in
builds 1 and 2, which is why this page says to download again rather than to
trust a checksum you saved.

Built from commit `{{COMMIT}}`.
