# The Windows till — how `SoftCora-POS-Setup.exe` is built and checked

The offline till ships as **one file the shop double-clicks**. It installs per
user (no administrator password), and it needs none of WAMP, XAMPP, PHP,
Node.js, Composer, npm or Git — the program carries its own runtime and its own
database engine.

```
offline/dist/SoftCora-POS-Setup.exe            24 MB   the installer
offline/dist/Afghan-China-Setup.exe            24 MB   the same bytes — the name the
                                                       permanent download link serves
offline/dist/SoftCoraPOS-portable-win64.zip    37 MB   the same till, copy-and-run
offline/dist/sha256.txt                                checksums of all of the above
offline/dist/RELEASE-NOTES.md                          this version's notes, values filled in
```

Both are produced by `offline/installer/build-windows.sh` and are **not** in
version control (see `.gitignore`): rebuild them instead of committing them.

The permanent download link —
`https://github.com/softcorat-bot/afghan-china/releases/download/latest/Afghan-China-Setup.exe`
— is a rolling release whose tag the publish workflow moves to the newest build
and whose `Afghan-China-Setup.exe` asset it clobbers with that build's
installer. The versioned releases (`v1.0.0`, …) keep the canonical
`SoftCora-POS-Setup.exe` name and never change behind a release's back.

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
| `node.exe` (Windows x64) | npm package `node-win-x64`, at the version pinned in `build-windows.sh` (v26.9.0) — sha256 recorded in `app/RUNTIME.txt` |
| the runtime that writes and self-tests the blob | npm package `node-linux-x64` / `node-win-x64` **at that same version** |
| SFX stub | npm package `maker-7z-sfx` (`7zsd_All_x64.sfx`) |
| bundler / injector / packer | npm packages `esbuild`, `postject`, `7zip-bin` |

## Building it

```bash
bash offline/installer/build-windows.sh          # add --skip-runtime-download to reuse the cached runtime
```

The only prerequisite is Node 22.5+ on `PATH` and a network that can reach
`registry.npmjs.org`. Everything else the build needs — `esbuild`, `postject`,
`7zip-bin`, the 7-Zip SFX stub and **both runtimes** — is fetched on demand into
`offline/build/tooling` and `offline/build/cache`, so a fresh checkout builds
with one command and nothing is installed by hand. Two details worth knowing:

* The SFX stub is fetched as a bare tarball rather than through
  `npm install maker-7z-sfx`: that package depends on `@electron-forge`, whose
  tree reaches a `git+https://codeload.github.com/...` dependency that cannot be
  verified on every machine.
* Both runtimes come from the npm registry (`node-win-x64` and
  `node-<host>-<arch>`), at the version pinned in the script; each tarball's
  published integrity hash is checked before it is used, the Windows binary's
  version is read back out of its own version resource, and the build stops if
  either does not match the pin.

The script is deliberately loud and fails closed:

0. **checks the installer scripts in version control** before it downloads or
   compiles anything (`installer/check-payload.mjs`) — ASCII, CRLF, byte-order
   marks, balanced quoting read both ways, no PowerShell 7-only syntax, and the
   real PowerShell parser when `pwsh` is installed. See *Why the payload is
   ASCII* below: this is the step that would have caught a shipped installer
   that could not parse on any Windows PC;
1. **fetches the two runtimes of the pinned version** — the Windows one the till
   ships as, and one for this build host — and asserts that the host runtime
   reports the pinned version. The build host's own Node never writes or runs
   the blob. See *The blob and the runtime* below: this is the step that would
   have caught an installer that aborted on every Windows PC;
2. fetches any missing build tool, then bundles `src/win-main.mjs` into
   `offline/build/till.cjs` (esbuild, CJS — a packaged executable loads its entry
   point as CommonJS, so the sources avoid top-level `await`);
3. builds the SEA preparation blob **with the pinned runtime**, embedding
   `public/index.html`, `app.js` and `styles.css` as assets;
4. injects it into the cached `node.exe` (`postject`, with the `NODE_SEA_FUSE`
   sentinel);
5. **self-tests that exact blob here, under the pinned version** — the same blob
   is injected into the host runtime *of the shipped version* and has to boot on
   port 7899, answer `/api/device`, serve the embedded screen (`/`, `/app.js`,
   `/styles.css`, each checked by HTTP status and content), create its database
   and run `--cli status`. What this cannot prove is the Windows binary itself;
   `installer/verify-windows.ps1` does that, in CI, on Windows;
6. assembles the payload, then normalises and re-checks it as it will ship:
   CRLF everywhere, ASCII everywhere, no mark on `.cmd` (cmd.exe prints one), a
   UTF-8 mark on every `.ps1` (so PowerShell 5.1 cannot fall back to the ANSI
   code page);
7. packs it with LZMA2 and glues it after the stub and its configuration;
8. writes the portable zip and `sha256.txt`;
9. writes `dist/RELEASE-NOTES.md` from `docs/RELEASE-NOTES-<version>.md`, filling in
   this build's sizes, checksums and commit. The bundle carries a build timestamp, so
   it is not bit-reproducible and the checksums cannot be maintained by hand; the build
   fails if the notes ask for a value it does not provide.

Moving the pinned runtime is a one-line change:

```bash
SOFTCORA_NODE_VERSION=26.9.0 bash offline/installer/build-windows.sh   # or edit NODE_VERSION
```

## Why the payload is ASCII — and what happens when it is not

The first packaged release installed nowhere. On a real till it stopped with:

```
At C:\Users\<name>\AppData\Local\Temp\7ZipSfx.001\install.ps1:152 char:100
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

## A child's exit code is not always readable — and what happens when it is read as failure

The third packaging fault, found by the acceptance job above on its first run:

```
the packaged program runs: it exited without reporting a code;
output: { "device_id": "SC-POS-57BBA2", "state": "offline", ... }
```

The program had **answered correctly**. The installer stopped on the next line
anyway, with `SoftCora-POS.exe did not run on this PC (exit unknown)` — and on a
shop's PC the same hole printed `(exit )`, an empty pair of brackets, which is
what the first field report from the till showed.

`Start-Process -PassThru` returns a process object that, in Windows PowerShell
5.1, does not report `ExitCode` — it comes back empty even after
`WaitForExit($ms)` has returned true, and no amount of re-reading fills it in.
`install.ps1` compared it with zero (`$null -ne 0` is true), concluded that the
program had failed, and refused to install a program that worked.

What the code does now, in `install.ps1`, `verify.ps1` and
`installer/verify-windows.ps1`:

| Rule | Why |
| --- | --- |
| Run the program through `System.Diagnostics.Process`, never `Start-Process -PassThru` | The .NET object reports the exit code it was given |
| Read stdout and stderr asynchronously while the program runs, then `WaitForExit($ms)` followed by `WaitForExit()` | Reading a redirected pipe only after exit can deadlock; the parameterless wait lets the readers finish |
| Treat success as *the program answering* (`--cli status` JSON), and a blank code as unknown rather than failed | A program that is blocked, quarantined or built against the wrong runtime prints nothing at all — it still fails the check |
| Only a code that was read *and* is not zero is a problem (`verify.ps1`) | The health check used to report a healthy install as a problem for the same reason |

`offline/test/installer.mjs` keeps all four (15/15), and the check in
`windows-installer` — which is what found this — is the only one that can: it is
the only place the real `.exe` is executed.

## The blob and the runtime must be the same Node version — and what happens when they are not

The second release of 1.0.0 also installed nowhere, and again the machine that
built it could not see why. On a real till, setup stopped with:

```
SoftCora-POS.exe did not run on this PC (exit ).  #  C:\Users\farha\AppData\Local\Temp\7ZipSfx.000\app\SoftCora-POS.exe[14272]:
#  SeaResource __cdecl node::sea::(anonymous namespace)::SeaDeserializer::Read(void) at src\node_sea.cc:172
#  Assertion failed: (format_value) <= (static_cast<uint8_t>(ModuleFormat::kModule))
```

That is Node refusing to read its own executable. A single-executable blob is an
**internal, version-specific serialization**: `node --experimental-sea-config`
writes a format field, and the runtime that reads it validates that field and
aborts the process when it finds something it did not write. The build was made
on a Linux runner whose `node` was **v22**, while the `node.exe` it was injected
into comes from `node-win-x64` **v26.9.0** — so the two halves never matched.

Nothing in the pipeline could have noticed, which is the real defect:

* the build's self-test injected the blob into *the same local Node that wrote
  it* (`cp "$NODE" "$SELFCHECK"`) — a blob always agrees with the version that
  produced it, so the test proved only that the blob was not corrupt;
* the payload checker reads the installer scripts, not the program;
* every build on the machine passed, and every tilde of it failed on a PC.

The build now pins one version and uses it for both halves:

| Rule | Why | Enforced by |
| --- | --- | --- |
| One pinned `NODE_VERSION` in `build-windows.sh` supplies both the Windows runtime and the runtime that writes the blob | A blob is readable only by the exact version that wrote it | `NODE_VERSION="${SOFTCORA_NODE_VERSION:-26.9.0}"`; the runtime is fetched at that version, never as `latest` |
| Nothing on the build host writes or runs the blob | The host's `node` is a build tool, not the shop's runtime | the blob is prepared with `$HOST_NODE` (fetched, pinned) and the self-test copies `$HOST_NODE`; `test/installer.mjs` fails if `"$NODE" --experimental-sea-config` or `cp "$NODE" "$SELFCHECK"` ever comes back |
| Both halves are asked their version | A file name is not a version | the host runtime must answer `process.versions.node` with the pin; the Windows binary's `FileVersion` is read out of its version resource, since it cannot be run here |
| Cached runtimes carry the version in their name | A bump must not reuse the previous runtime | `build/cache/node-v<version>-win-x64.exe`, `build/cache/node-v<version>-<host>-<arch>` |
| The built installer is run on Windows before it can ship | Only Windows can prove a Windows `.exe` starts | `installer/verify-windows.ps1`, run by the `windows-installer` job in `.github/workflows/offline-till.yml` and, as a gate, by `.github/workflows/publish-release-assets.yml` |

`installer/verify-windows.ps1` is also useful by hand on a bench PC — it unpacks
the setup file, runs the program, starts the till, installs it, and reads
`install.log` and `verify.ps1` back:

```powershell
powershell -ExecutionPolicy Bypass -File offline\installer\verify-windows.ps1 -Installer .\SoftCora-POS-Setup.exe -Install
```

And if it ever fails again, `install.log` now says why by itself: the payload's
`RUNTIME.txt` line (which names the runtime the program carries) is logged before
the check, the program's own output is logged line by line, a missing exit code
is reported as `unknown` instead of an empty pair of brackets, and an abort
inside its own runtime is called what it is — a build that must be replaced, not
retried.

**Reading a red run.** The first run of the `windows-installer` job failed on the
runner with nothing but `Process completed with exit code 1` in the run's
annotations, and its log could not be downloaded at all — a failure nobody can
act on. So the verifier now reports itself three ways: every failed check becomes
a workflow annotation (visible on the run and through the API), the whole report
is the step summary, and `-Report <path>` writes it to a file, which the job keeps
as the `windows-verification` artifact. It also reports a *thrown* error like a
failed check, naming the line it came from, and it never merges a native command's
stderr into the pipeline (`Start-Process` and two files instead): under
`$ErrorActionPreference = 'Stop'`, Windows PowerShell 5.1 can turn that into a
terminating error that ends the script with no report at all.

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
different host from `api.github.com` and is blocked on some build networks, so
releases are normally cut by `.github/workflows/publish-release-assets.yml` —
which now runs as three jobs and **publishes nothing until the built installer
has been run on Windows**: `build` (ubuntu) → `verify-windows` (windows-latest,
`installer/verify-windows.ps1`) → `publish`.

Step 5 is why the packaging is trustworthy at all: the blob, the asset embedding
and the entry point are the same bytes on every platform, so running them under
the shipped runtime's *version* tests the real thing. The one thing it cannot
test is the Windows binary — which is exactly what `verify-windows.ps1` does, on
Windows, before a release. Earlier this section claimed the Linux self-test was
enough "without a Windows machine in the loop"; that claim shipped two
installers that no Windows PC could run.

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

## How all of this is checked

Four gates, all in `.github/workflows/offline-till.yml`:

| Where | What it proves |
| --- | --- |
| `ubuntu-latest` | `node installer/check-payload.mjs installer/payload` — ASCII, CRLF, marks, quoting balanced in both readings, no PowerShell 7-only syntax, batch labels resolve. `pwsh` is installed on the runners, so the real PowerShell parser reads every `.ps1` twice: as written, and as cp1252 would. |
| `ubuntu-latest` | `node test/e2e.mjs` (18 checks) and `node test/installer.mjs` (14 checks) — the engine, plus the installer's contracts, including proof that each guard *fails* on the bug it was written for: the code-page misread, a blob written by the wrong Node version, and a publish workflow that does not wait for the Windows check. |
| `ubuntu-latest` | `bash offline/installer/build-windows.sh` — the real deliverables, built once and handed to the job below as an artifact. The build fetches the pinned runtime, prepares the blob with it, self-tests the packaged blob under it, and stops if the two halves disagree. |
| `windows-latest` | Windows PowerShell 5.1 itself parses every payload script with `ParseFile`, which applies the host's own reading rules; then `verify.ps1` is *run* on a PC with nothing installed (must exit 1 and say so), and `install.ps1` is *run* against a payload with no program in it (must exit 1, must not create `app\` or `data\`, and must leave the reason in `install.log`). |
| `windows-latest` | `installer/verify-windows.ps1` on **the installer this run built**: unpacked with 7-Zip, its program run (`--cli status`, `--cli verify`), the till started and its API and screen fetched over HTTP, then `install.cmd` run for real — `install.log` free of errors, the installed bytes equal to the payload, the shop's data folder untouched, and `verify.ps1` reporting a healthy installation. |

The last two jobs are the ones that matter: they execute the scripts, and the
program, on the host that runs them in a shop. This is the coverage that both
field failures were missing — and it is the reason `publish-release-assets.yml`
now builds, verifies on Windows, and only then uploads, as three separate jobs.

Locally, the same gates are `cd offline && npm test && npm run check:installer`
plus `bash installer/build-windows.sh`; the Windows half needs Windows (or the
CI runner).

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
specific. Steps 1, 1a, 1b and 2 are the installer's own behaviour, and they are
now exercised on every pull request by the two `windows-latest` jobs described
above: the installer is unpacked, its program is run, the till is started and
asked for its screen, `install.cmd` is run into `%LOCALAPPDATA%`, and
`install.log` and `verify.ps1` are read back afterwards. What still needs a
person at a machine: the SmartScreen prompt on a clean PC, the shortcuts and
Apps & Features entry as a user sees them, autostart, uninstall (steps 11 and
11a), and everything that needs a real till, a printer or a shop network.

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
