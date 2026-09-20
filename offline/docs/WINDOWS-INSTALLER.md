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

0. **checks the installer scripts in version control** before it downloads or
   compiles anything (`installer/check-payload.mjs`) — ASCII, CRLF, byte-order
   marks, balanced quoting read both ways, no PowerShell 7-only syntax, and the
   real PowerShell parser when `pwsh` is installed. See *Why the payload is
   ASCII* below: this is the step that would have caught a shipped installer
   that could not parse on any Windows PC;
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
5. assembles the payload, then normalises and re-checks it as it will ship:
   CRLF everywhere, ASCII everywhere, no mark on `.cmd` (cmd.exe prints one), a
   UTF-8 mark on every `.ps1` (so PowerShell 5.1 cannot fall back to the ANSI
   code page);
6. packs it with LZMA2 and glues it after the stub and its configuration;
7. writes the portable zip and `sha256.txt`;
8. writes `dist/RELEASE-NOTES.md` from `docs/RELEASE-NOTES-<version>.md`, filling in
   this build's sizes, checksums and commit. The bundle carries a build timestamp, so
   it is not bit-reproducible and the checksums cannot be maintained by hand; the build
   fails if the notes ask for a value it does not provide.

## Why the payload is ASCII — and what happens when it is not

The first packaged release installed nowhere. On a real till it stopped with:

```
At C:\Users\farha\AppData\Local\Temp\7ZipSfx.001\install.ps1:152 char:100
+ ...  in your browser (http://127.0.0.1:' -NoNewline; Write-Host "$Port)."
+                                                                       ~
Unexpected token ')' in expression or statement.
At ...\install.ps1:152 char:102
The string is missing the terminator: ".
```

Line 152 was correct. The cause was three lines earlier:

```powershell
Write-Step "Done — data folder kept at $DataDir"      # that is an em dash
```

Windows PowerShell 5.1 — the `powershell.exe` every Windows 10/11 ships — reads
a `.ps1` that has **no byte-order mark** with the machine's **ANSI code page**,
not as UTF-8. On a Western PC that is cp1252, where an em dash's three UTF-8
bytes (`E2 80 94`) decode to `â`, `€`, `U+201D`. PowerShell accepts `U+201D`
(RIGHT DOUBLE QUOTATION MARK) as a string delimiter, so that string closed after
`Done `, the trailing `"` opened a new one, and the new string ran on until the
next `"` it could find — which was inside line 152. Everything between was
parsed as code, and the error was reported where the runaway string happened to
end.

The same trap is waiting in `→` (`U+2192` → `â†’`, ending in `U+2019`, a right
*single* quote) and in the box-drawing characters used for ruled comments.
Nothing on a Linux or macOS build machine shows any of this: the files parse
there, and every editor renders them as written.

So the payload obeys three rules, and they are checked rather than remembered:

| Rule | Why | Enforced by |
| --- | --- | --- |
| `.ps1`, `.cmd` and `.txt` in the payload are pure ASCII | ASCII is the one encoding cmd.exe, PowerShell 5.1 and the SFX stub all read the same way, on any code page | `check-payload.mjs`, step 0 of the build, CI |
| Every `.ps1` that ships carries a UTF-8 byte-order mark | If a non-ASCII character ever gets back in, PowerShell reads the file as UTF-8 and the script still parses | the build's `--fix --bom` pass, verified with `--require-bom` |
| The quoting must balance read as UTF-8 **and** read as cp1252 | A script that only parses one way is a script that breaks on some PC | `check-payload.mjs`, plus `pwsh` on the Linux runner and Windows PowerShell 5.1 itself on the Windows runner |

The typographic characters are still available where they are read by something
that decodes UTF-8 properly: the till's screen, its receipts, `README.md` and
every Markdown document. Only the files a Windows host reads with its own code
page are restricted. `node installer/check-payload.mjs installer/payload --fix`
spells the usual offenders out (`—` → `-`, `→` → `->`, `…` → `...`, smart quotes
→ ASCII quotes, box drawing → `-`) and leaves anything it does not recognise
alone rather than guessing at a language.

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
2. The setup unpacks into a temporary folder and runs `install.cmd`, which finds
   `powershell.exe`, shows progress in its own window and then starts the till.
   Everything it does is appended to `logs\install.log` as it goes, so a failure
   on a shop PC is one file to send rather than a photograph of a window.
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
%LOCALAPPDATA%\SoftCoraPOS\logs     install.log, till.log and the till's own channelled logs
```

The installer deliberately:

* installs per user, so a shop till on a shared PC needs no administrator;
* never asks for a firewall rule — the till listens on `127.0.0.1` only, and
  talks outward to the server, which no Windows firewall blocks;
* **checks before it changes anything** — the packaged program is run once
  against a throw-away data folder to prove it works on that PC, and an existing
  database is asked whether the update is safe (`--cli verify`, the same gate the
  till applies to itself). Either answer being "no" stops the install with the
  existing files untouched;
* compares the copied program with the payload it came from (SHA-256), so an
  antivirus that alters the executable is caught at install time rather than at
  the first sale;
* **keeps the port this PC already uses** on an update, unless one is given
  explicitly — a bookmarked till screen must not move because a setup was run
  again. The choice is recorded under `HKCU\Software\SoftCora\POS`;
* **never touches the data folder** — unsynced sales, the outbox and the device
  identity survive every update; `uninstall.ps1` keeps that folder too unless it
  is explicitly asked for `-RemoveData`, and when something is still unsynced it
  reports how many records would be lost and asks for the word `DELETE`;
* reports in exit codes a script can drive: `0` installed, `3` installed but not
  launched (`-NoLaunch`), `1` failed with the reason in `install.log`.

## Checking an installation

**Start menu → SoftCora POS → Health check**, or from a terminal:

```powershell
powershell -ExecutionPolicy Bypass -File "$env:LOCALAPPDATA\SoftCoraPOS\app\verify.ps1"
powershell -ExecutionPolicy Bypass -File "$env:LOCALAPPDATA\SoftCoraPOS\app\verify.ps1" -Json
```

It reports the installed version, the program's path, the database and when it
was last written, the device id and whether it is registered, whether the till is
answering on the port this install chose, the till's own sync status
(`SoftCora-POS.exe --cli status`: state, pending, failed, conflicts, last sync
and what it uploaded), the newest backup, and the log files that exist. Anything
wrong is listed under *Problems found*. It changes nothing — a missing database
is reported as missing rather than created.

Exit codes: `0` installed and healthy, `1` not installed, `2` installed with
problems listed. `-Json` prints the same report as one object, which is what a
support script or a fleet check should use.

"Pending" is not a fault while a shop is offline; those sales are stored locally
and are sent by the next **Sync Now**.

## How all of this is checked without a Windows PC in the loop

Three independent gates, all in `.github/workflows/offline-till.yml`:

| Where | What it proves |
| --- | --- |
| `ubuntu-latest` | `node installer/check-payload.mjs installer/payload` — ASCII, CRLF, marks, quoting balanced in both readings, no PowerShell 7-only syntax, batch labels resolve. `pwsh` is installed on the runners, so the real PowerShell parser reads every `.ps1` twice: as written, and as cp1252 would. |
| `ubuntu-latest` | `node test/e2e.mjs` (18 checks) and `node test/installer.mjs` (11 checks) — the engine, plus the installer's contracts, including proof that the payload check *fails* on the exact bug it was written for. |
| `windows-latest` | Windows PowerShell 5.1 itself parses every payload script with `ParseFile`, which applies the host's own reading rules; then `verify.ps1` is *run* on a PC with nothing installed (must exit 1 and say so), and `install.ps1` is *run* against a payload with no program in it (must exit 1, must not create `app\` or `data\`, and must leave the reason in `install.log`). |

The Windows job is the one that matters: it executes the scripts on the host that
executes them in a shop. Both runs are read-only or fail on purpose, so neither
can leave a half-installed till behind.

Locally, the same gates are `cd offline && npm test && npm run check:installer`.

## The Windows acceptance test

Everything up to the Windows loader has been proven in the build environment
(the PE header, the SFX layout, the archive's CRCs, and the blob running as a
packaged executable on a Linux host). What cannot be proven there is Windows
itself, so this is the test to run on a real machine — it is the installer part
of the project's acceptance checklist:

| # | Step | Expected |
| --- | --- | --- |
| 1 | Install on a clean Windows 10/11 x64 machine with no PHP/Node/WAMP | finishes without an administrator prompt; the till opens |
| 1a | Read `logs\install.log` after that install | the packaged program was checked, folders created, program copied with a matching SHA-256, shortcuts and registration written, no `ERROR` line |
| 1b | Unzip the portable zip, delete `app\SoftCora-POS.exe`, run `install.cmd` | stops with *The installer payload is incomplete*, creates no `app\` or `data\`, and the reason is in `install.log` |
| 2 | `verify.ps1` (or **Start menu → SoftCora POS → Health check**) | version, database, device id, till answering on its port, no problems listed, exit 0 |
| 3 | On the setup panel, register with the server address and an activation code | device appears as *Active* under Settings → Devices on the server |
| 4 | Create the first sign-in on the setup panel, sign out, sign in with the network cable unplugged | sign-in works; the password is never stored in clear text |
| 5 | Unplug the network; sell 20 items, add 5 customers, do 3 returns, make stock movements, open/close 2 drawers | every sale completes; Sync Status reads *Offline* with a pending count |
| 6 | Close the till (or reboot) and reopen it | all 20 sales, the customers and both drawers are still there |
| 7 | Reconnect; press **Sync Now** | *Uploading n/N*, then *Downloaded n/N*, then a summary; pending drops to 0 |
| 8 | Press **Sync Now** again twice | no new sales on the server |
| 9 | Insert a product on the server, press **Sync Now** | the product appears in the till's catalogue |
| 10 | Run the setup again over the installed copy | version updated, sales and pending queue intact (`verify.ps1` before and after), and the till still opens on the same port |
| 11 | Uninstall from Apps & features | asks first; program gone, data folder still there with its sales |
| 11a | Uninstall again with `-RemoveData` while a record is still pending | reports how many records would be lost and refuses without the word `DELETE` |
| 12 | Back up (`Settings → Backup`), restore on a second machine | identical sales, customers, drawer sessions and pending queue |

Steps 5–12 are the same scenarios the automated offline test already exercises
on Linux (`cd offline && npm test`), which is why the expected results are
specific. Steps 1a, 1b, 2 and 11a are the installer's own behaviour, and the
parts of them that can run without a till database are exercised on every pull
request by the `windows-latest` job described above.

## Notes on the moving parts

* **Why a 7-Zip SFX and not an MSI/NSIS?** The SFX stub is the only installer
  bootstrap reachable from this environment that runs without a build toolchain,
  and it needs no administrator rights, no registry install and no driver. It is
  also auditable: `7za l SoftCora-POS-Setup.exe` lists and CRCs the payload.
* **Encoding and line endings matter more than they look.** `cmd.exe` mis-parses
  LF-only `.cmd` files and prints a byte-order mark; Windows PowerShell 5.1 reads
  a mark-less `.ps1` with the ANSI code page. Both are enforced by
  `installer/check-payload.mjs` at both ends of the build, and `.gitattributes`
  pins the payload to CRLF so no developer's `core.autocrlf` can undo it. See
  *Why the payload is ASCII* above for the failure this prevents.
* **The launchers are generated, not copied.** `install.ps1` writes
  `SoftCora POS.cmd` and `start-till.cmd` into `app\` with this PC's port, data
  folder and log folder baked in, as ASCII with CRLF. The copies in the payload
  are what runs when the portable zip is used by hand, and they resolve the same
  locations from `%LOCALAPPDATA%` — one PC, one database, whichever way the till
  is started.
* **The setup deletes its temporary folder when it returns**, so
  `install.cmd` launches the *installed* launcher, never the temporary copy.
* **Updating** replaces `app\` only. The installer asks the database first
  (`--cli verify` → `assertUpgradeSafe`), and the till additionally refuses to
  start if an update would have swapped out its database or lost its device
  identity.
