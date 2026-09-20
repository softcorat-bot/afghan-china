# Release — building and shipping SoftCora-POS-Setup.exe

## 1. Pre-flight

- [ ] `cd offline && npm test` → **18/18** end-to-end checks and **15/15**
      installer checks
- [ ] `cd offline && npm run check:installer` → the Windows payload is ASCII,
      CRLF, marked correctly and parses in both readings. CI runs the same check
      on Linux *and* has Windows PowerShell 5.1 parse and run the scripts
      (`.github/workflows/offline-till.yml`); a release must not be cut with it
      red. See `offline/docs/WINDOWS-INSTALLER.md` → *Why the payload is ASCII*.
- [ ] `cd frontend && npx quasar build` → clean (fleet pages in `dist/spa/assets/`)
- [ ] Backend untouched? Still run `php artisan migrate --force` + PermissionSeeder
      on the server side when deploying the sync surface for the first time
      (migrations `2026_09_19_*` add `pos_devices`, `sync_*`, identity columns).
- [ ] Bump versions together (they travel as one release):
      - `offline/installer/payload/VERSION.txt`  (`SoftCora POS 1.0.0`)
      - create `offline/docs/RELEASE-NOTES-<version>.md` (template with the
        `{{SETUP_SHA256}}`-style placeholders the build fills in)

## 2. Build (any OS — the pipeline cross-builds Windows from Linux/macOS)

```bash
offline/installer/build-windows.sh
```

Produces in `offline/dist/` (git-ignored):

| artifact | what it is |
|---|---|
| `SoftCora-POS-Setup.exe` | the installer: 7-Zip SFX → per-user PowerShell installer |
| `Afghan-China-Setup.exe` | the same installer bytes under the shop-facing name the permanent download link serves |
| `SoftCoraPOS-portable-win64.zip` | the same till, unzip-and-run |
| `SoftCora-POS.exe.sha256`, `sha256.txt` | checksums for verification |
| `RELEASE-NOTES.md` | the versioned notes with this build's checksums filled in |

The pipeline itself: fetch the **pinned** Node runtime and a runtime of that same
version for the build host → esbuild bundle → Node SEA blob (screen embedded as
assets) **written by the pinned runtime** → postject into `node-win-x64` →
**self-test of the same blob on the build host, under the pinned version** (boot,
serve screen, create DB, run CLI) → pack. Tools (`esbuild`, `postject`,
`7zip-bin`, the SFX stub, both runtimes) are fetched from the npm registry with
integrity verification; nothing else is needed on the build machine beyond
Node ≥ 22.5, bash and python3.

⚠️ **The blob and the binary that reads it must be the same Node version.** A SEA
blob is an internal, version-specific serialization: the runtime validates the
format field it finds and aborts the process on a mismatch, so a build made with
the build host's own Node produced an `.exe` that aborted on every Windows PC
while every check here passed. The version is pinned in
`installer/build-windows.sh` (`NODE_VERSION`, `SOFTCORA_NODE_VERSION`), both
halves are verified against it, and — because only Windows can prove a Windows
binary — the built installer is run on `windows-latest` before anything is
published. See `offline/docs/WINDOWS-INSTALLER.md` → *The blob and the runtime*.

## 3. Verify the build

1. **Self-test** — already done by the pipeline (see above); a packaging break
   fails the build rather than shipping.
2. **Payload check** — also already done by the pipeline, twice: the scripts in
   version control before anything is compiled, and the payload as packed
   (`--require-crlf --require-bom`). To re-check an artifact that already exists:
   ```bash
   7za x -o/tmp/unpacked offline/dist/SoftCora-POS-Setup.exe -y
   cd offline && node installer/check-payload.mjs /tmp/unpacked --require-crlf --require-bom
   ```
3. **Run the built installer on Windows** — also already done by the pipeline,
   and by CI on every pull request. `installer/verify-windows.ps1` unpacks the
   setup file, runs the program inside it, starts the till and fetches its screen
   and API, installs it into `%LOCALAPPDATA%`, and reads `install.log` and
   `verify.ps1` back:
   ```powershell
   powershell -ExecutionPolicy Bypass -File offline\installer\verify-windows.ps1 `
       -Installer offline\dist\SoftCora-POS-Setup.exe -Install
   ```
   `publish-release-assets.yml` runs exactly this between building and uploading,
   so a build whose program does not start cannot reach a release page. When it
   goes red, read the run's annotations and the `windows-verification` artifact
   (not just the log): every failed check is annotated with what it expected and
   what it saw, and `-Report` keeps the whole report as a file.
4. Checksums recorded (`sha256.txt`) — paste them into the release notes issue.
5. **Windows bench verification** (VM or a bench PC, once per release — the parts
   a runner cannot do: SmartScreen, shortcuts as a user sees them, uninstall,
   and everything that needs a real till or a printer):
   - Install on a *clean* user profile → sell → close → unplug network
     (`netsh interface set interface "Wi-Fi" admin=disabled`) → sell →
     reconnect → auto-sync → server shows the sales exactly once.
   - Install over the previous release with unsynced sales on disk → queue and
     identity intact (`verify.ps1`), same port, `logs\install.log` free of
     `ERROR` lines.
5. Ship: attach both artifacts + `sha256.txt` to a GitHub release. From a
   machine that can reach `uploads.github.com`:

```bash
gh release create v1.0.0 offline/dist/SoftCora-POS-Setup.exe \
   offline/dist/SoftCoraPOS-portable-win64.zip offline/dist/sha256.txt \
   --notes-file offline/dist/RELEASE-NOTES.md
```

   This repository is developed in sandboxes that *cannot* reach the upload
   host, so publishing normally goes through
   `.github/workflows/publish-release-assets.yml`, which rebuilds on GitHub's
   runners, verifies its own checksums and attaches them:

```bash
gh workflow run publish-release-assets.yml --ref main -f tag=v1.0.0
```

   Two inputs, two questions: **`ref`** is what to *build* (empty means the
   dispatched branch), **`tag`** is which *versioned* release to attach the
   canonical assets to (empty means the repository's latest release; the value
   `latest` skips the versioned upload entirely). A `v*` tag push runs it
   automatically with both pointing at the tag. Uploads use `--clobber` and the
   notes are rewritten from that same build, so the assets and the checksums on
   a release page always belong to one run.

### The permanent `latest` link

Every run of the publish workflow also refreshes the **rolling `latest`
release**, whatever else it published:

```
https://github.com/softcorat-bot/afghan-china/releases/download/latest/Afghan-China-Setup.exe
```

The workflow moves the `latest` tag to the commit it built, uploads
`Afghan-China-Setup.exe` (byte-identical to that build's
`SoftCora-POS-Setup.exe`), the portable zip and `sha256.txt` with `--clobber`,
and rewrites the release notes with the URL, the commit and the checksums. The
versioned releases (`v1.0.0`, `v1.1.0`, …) keep their own canonical assets and
are never touched by this. Nothing else is needed to keep the link honest: any
future installer build that goes through the workflow updates it.

## 3a. Re-cutting a release — same version, fixed bytes

For a packaging bug that never worked on a real PC, the version is not the
problem, so it does not move. What has to change is the bytes and the story:

1. Fix on a branch. `npm test` (18/18 + 15/15) and `npm run check:installer`
   green, plus whatever new check would have caught the bug — a re-cut without
   a new guard invites the same re-cut.
2. Rewrite `offline/docs/RELEASE-NOTES-<version>.md` for the re-cut: what was
   wrong, in the words the failure produced; that the assets were re-published;
   which acceptance steps are now covered by CI and which still need a bench PC.
   A shop that has the broken file must be able to tell the two apart by
   checksum, so the notes say plainly that the checksums changed.
3. Merge, then rebuild from `main` and clobber:
   ```bash
   gh workflow run publish-release-assets.yml --ref main -f tag=v1.0.0
   ```
   Never point `ref` at the release tag when re-cutting — that rebuilds the
   broken commit and re-uploads it.
4. The tag still points at the commit that shipped. Leave it and let the notes
   carry provenance (they end with the commit they were built from), or move it
   deliberately — a tag push republishes on its own:
   ```bash
   git tag -f v1.0.0 <fixed-commit> && git push -f origin v1.0.0
   ```
5. Tell everyone who has the old file, by the channel they got it from, that
   the checksums changed and to download again.

1.0.0 has been re-cut three times, for three different packaging faults — a
payload Windows PowerShell misread (build 1), a SEA blob written by the wrong
Node version (build 2), and an installer that could not read a working program's
exit code (build 3: Windows PowerShell 5.1 leaves `ExitCode` empty on a process
object from `Start-Process -PassThru`, and `install.ps1` read that as "the
program failed"). The notes carry all three stories; the version did not move for
any of them, because the till itself never changed. Build 2 and build 3 are also
the reason the pipeline now *runs the built installer on Windows* before it
publishes anything: neither fault was visible to any check that did not execute
the real `.exe`.

## 4. Versioning & compatibility rules (don't break these)

- **Additive-only local schema.** Bump `SCHEMA_VERSION` in `offline/src/db.mjs`
  with an idempotent upgrade keyed on the current meta value. Never drop.
- **Protocol changes are opt-in.** New push payload fields must be
  ignorable by an old server; new server responses ignorable by an old till.
  A breaking protocol idea = new endpoint version, not a flag day.
- **The queue is sacred.** A release that could lose `sync_queue` rows on
  upgrade is not a release (guard: `backup.mjs → assertUpgradeSafe`,
  `verify.ps1`, upgrade e2e).
- **Frontend/server deploy order:** server (backend) first, then tills. An old
  till talking to a new server keeps working; the reverse must never be required.
- **The shipped Node runtime and the blob move together, or not at all.** The
  version in `installer/build-windows.sh` (`NODE_VERSION`) supplies both the
  `node.exe` in the installer and the runtime that writes and self-tests the SEA
  blob. Changing one half alone produces an installer that aborts at startup on
  every PC, with a `SeaDeserializer` assertion as the only clue. Bump the pin,
  rebuild, and let CI's Windows job run the result.

## 5. Known packaging notes

- The installer is an **unsigned** 7-Zip SFX — SmartScreen/AV reputation:
  expected on new builds, improves with age; code-signing (e.g. osslsigncode
  against the SFX stub, or a signed Electron-style wrapper later) is the
  tracked improvement. Checksums in `sha256.txt` are the interim integrity story.
- The Windows runtime comes from the verified npm package `node-win-x64`, at
  the version pinned in `build-windows.sh` (integrity-checked at build, version
  read back out of its own version resource; see `RUNTIME.txt` inside the
  payload). The blob is written by a runtime of that same version — see §2.
- The app embeds no secrets; the only secret a till owns is minted during
  activation on the customer's machine.

## 6. Release checklist summary

```
npm test (18/18 + 15/15)  →  npm run check:installer  →  quasar build
→  VERSION.txt + release notes  →  build-windows.sh  →  verify artifacts +
checksums  →  verify-windows.ps1 (CI does this before publishing)  →
Windows bench test for the parts a runner cannot do  →  gh release (workflow)  →
announce + TROUBLESHOOTING.md pointer
```
