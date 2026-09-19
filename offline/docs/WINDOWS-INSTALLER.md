# The Windows till — how `SoftCora-POS-Setup.exe` is built and checked

The offline till ships as **one file the shop double-clicks**. It installs per
user (no administrator password), and it needs none of WAMP, XAMPP, PHP,
Node.js, Composer, npm or Git — the program carries its own runtime and its own
database engine.

```
offline/dist/SoftCora-POS-Setup.exe            24 MB   the installer
offline/dist/SoftCoraPOS-portable-win64.zip    37 MB   the same till, copy-and-run
offline/dist/sha256.txt                                checksums of both
offline/dist/RELEASE-NOTES.md                          this version's notes, values filled in
```

Both are produced by `offline/installer/build-windows.sh` and are **not** in
version control (see `.gitignore`): rebuild them instead of committing them.

---

## What is inside the installer

```
SoftCora-POS-Setup.exe
├── 7zSD SFX stub                    a Windows x64 program (PE), 7-Zip 16.02's
│                                    self-extracting installer module
├── ;!@Install@!UTF-8! configuration Title, progress, and RunProgram="install.cmd"
└── SoftCora-POS.exe                 the till: Node 26 for Windows with the
    (99 MB, LZMA2)                   application and the screen embedded inside it
    plus install.cmd / install.ps1 / uninstall.ps1 / verify.ps1 / README-FIRST.txt
```

The till itself is built as a Node **single executable application**: the whole
server (HTTP, SQLite, sync engine, the till screen) is bundled into one CommonJS
file, turned into a preparation blob by `node --experimental-sea-config`, and
injected into a stock `node.exe` by `postject`. Nothing is interpreted from disk
at run time, so an installed till cannot be broken by a missing file or a
half-finished update.

| Piece | Where it comes from |
| --- | --- |
| `node.exe` (Windows x64) | npm package `node-win-x64` (v26.9.0) — sha256 recorded in `app/RUNTIME.txt` |
| SFX stub | npm package `maker-7z-sfx` (`7zsd_All_x64.sfx`) |
| bundler / injector / packer | npm packages `esbuild`, `postject`, `7zip-bin` |

## Building it

```bash
bash offline/installer/build-windows.sh          # add --skip-runtime-download to reuse the cached runtime
```

The only prerequisite is Node 22.5+ on `PATH` and a network that can reach
`registry.npmjs.org`. Everything else the build needs — `esbuild`, `postject`,
`7zip-bin`, the 7-Zip SFX stub and the Windows runtime itself — is fetched on
demand into `offline/build/tooling` and `offline/build/cache`, so a fresh
checkout builds with one command and nothing is installed by hand. Two details
worth knowing:

* The SFX stub is fetched as a bare tarball rather than through
  `npm install maker-7z-sfx`: that package depends on `@electron-forge`, whose
  tree reaches a `git+https://codeload.github.com/...` dependency that cannot be
  verified on every machine.
* The Windows runtime comes from the npm package `node-win-x64`, and its
  published integrity hash is checked before the tarball is used.

The script is deliberately loud and fails closed:

1. fetches any missing build tool, then bundles `src/win-main.mjs` into
   `offline/build/till.cjs` (esbuild, CJS — a packaged executable loads its entry
   point as CommonJS, so the sources avoid top-level `await`);
2. builds the SEA preparation blob, embedding `public/index.html`, `app.js` and
   `styles.css` as assets;
3. injects it into the cached `node.exe` (`postject`, with the `NODE_SEA_FUSE`
   sentinel);
4. **self-tests that exact blob on Linux** — it injects the same blob into the
   Linux `node` binary and asserts that the packaged form boots on port 7899,
   answers `/api/device`, serves the embedded screen (`/`, `/app.js`,
   `/styles.css`, each checked by HTTP status and content), creates its database
   and runs `--cli status`;
5. assembles the payload and converts every `.cmd`/`.ps1`/`.txt` to CRLF;
6. packs it with LZMA2 and glues it after the stub and its configuration;
7. writes the portable zip and `sha256.txt`;
8. writes `dist/RELEASE-NOTES.md` from `docs/RELEASE-NOTES-<version>.md`, filling in
   this build's sizes, checksums and commit. The bundle carries a build timestamp, so
   it is not bit-reproducible and the checksums cannot be maintained by hand; the build
   fails if the notes ask for a value it does not provide.

## Publishing it as a release

Everything the release needs is in `offline/dist/` after a build:

```bash
gh release create v1.0.0 \
  offline/dist/SoftCora-POS-Setup.exe \
  offline/dist/SoftCoraPOS-portable-win64.zip \
  offline/dist/sha256.txt \
  --title "SoftCora POS 1.0.0" \
  --notes-file offline/dist/RELEASE-NOTES.md
```

Cut the release from the same build whose checksums the notes carry — rebuilding
regenerates both. Release assets upload to `uploads.github.com`, which is a
different host from `api.github.com` and is blocked on some build networks.

Step 4 is the reason the packaging is trustworthy without a Windows machine in
the loop: the blob, the asset embedding and the entry point are the same bytes
on both platforms — only the host binary differs.

## Installing it (what the shop sees)

1. Double-click `SoftCora-POS-Setup.exe` (from a USB stick is fine). SmartScreen
   warns about an unsigned file; **More info → Run anyway**. No administrator
   password is asked for.
2. The setup unpacks into a temporary folder and runs `install.cmd`, which shows
   progress in its own window and then starts the till.
3. The till opens at <http://127.0.0.1:7817> and is listed in **Apps & features**
   as *SoftCora POS*.

   Because the database is new, it opens on the **setup panel**: the first staff
   sign-in is created there (stored as a hash), and the panel offers to register
   the till with the server using the administrator's activation code. Skipping
   registration is allowed — the till sells, and the sales wait until the first
   **Sync Now**. Both are reachable later under *Settings → Connect this till*
   and *Settings → Who can sign in*.

```
%LOCALAPPDATA%\SoftCoraPOS\app      the program (replaced on update)
%LOCALAPPDATA%\SoftCoraPOS\data     database, device identity, backups (never replaced)
%LOCALAPPDATA%\SoftCoraPOS\logs     till.log
```

The installer deliberately:

* installs per user, so a shop till on a shared PC needs no administrator;
* never asks for a firewall rule — the till listens on `127.0.0.1` only, and
  talks outward to the server, which no Windows firewall blocks;
* **never touches the data folder** — unsynced sales, the outbox and the device
  identity survive every update; `uninstall.ps1` keeps that folder too unless it
  is explicitly asked for `-RemoveData`.

## Checking an installation

```powershell
powershell -ExecutionPolicy Bypass -File "$env:LOCALAPPDATA\SoftCoraPOS\app\verify.ps1"
```

It prints the database path, the device id, whether the device is registered,
and the till's own sync status (`SoftCora-POS.exe --cli status`: pending,
failed, conflicts, last sync, cursor) — plus the date of the last backup.
"Pending" is not a fault while a shop is offline; those sales are stored locally
and are sent by the next **Sync Now**.

## The Windows acceptance test

Everything up to the Windows loader has been proven in the build environment
(the PE header, the SFX layout, the archive's CRCs, and the blob running as a
packaged executable on a Linux host). What cannot be proven there is Windows
itself, so this is the test to run on a real machine — it is the installer part
of the project's acceptance checklist:

| # | Step | Expected |
| --- | --- | --- |
| 1 | Install on a clean Windows 10/11 x64 machine with no PHP/Node/WAMP | finishes without an administrator prompt; the till opens |
| 2 | `verify.ps1` | database found, device id shown, no errors |
| 3 | On the setup panel, register with the server address and an activation code | device appears as *Active* under Settings → Devices on the server |
| 4 | Create the first sign-in on the setup panel, sign out, sign in with the network cable unplugged | sign-in works; the password is never stored in clear text |
| 5 | Unplug the network; sell 20 items, add 5 customers, do 3 returns, make stock movements, open/close 2 drawers | every sale completes; Sync Status reads *Offline* with a pending count |
| 6 | Close the till (or reboot) and reopen it | all 20 sales, the customers and both drawers are still there |
| 7 | Reconnect; press **Sync Now** | *Uploading n/N*, then *Downloaded n/N*, then a summary; pending drops to 0 |
| 8 | Press **Sync Now** again twice | no new sales on the server |
| 9 | Insert a product on the server, press **Sync Now** | the product appears in the till's catalogue |
| 10 | Run the setup again over the installed copy | version updated, sales and pending queue intact (`verify.ps1` before and after) |
| 11 | Uninstall from Apps & features | program gone, data folder still there with its sales |
| 12 | Back up (`Settings → Backup`), restore on a second machine | identical sales, customers, drawer sessions and pending queue |

Steps 5–12 are the same scenarios the automated offline test already exercises
on Linux (`cd offline && npm test`), which is why the expected results are
specific.

## Notes on the moving parts

* **Why a 7-Zip SFX and not an MSI/NSIS?** The SFX stub is the only installer
  bootstrap reachable from this environment that runs without a build toolchain,
  and it needs no administrator rights, no registry install and no driver. It is
  also auditable: `7za l SoftCora-POS-Setup.exe` lists and CRCs the payload.
* **Line endings matter.** `cmd.exe` mis-parses LF-only `.cmd` files (labels and
  parenthesised blocks), so the build normalises the payload to CRLF.
* **The setup deletes its temporary folder when it returns**, so
  `install.cmd` launches the *installed* launcher, never the temporary copy.
* **Updating** replaces `app\` only. The till additionally refuses to start if an
  update would have swapped out its database or lost its device identity
  (`assertUpgradeSafe`).
