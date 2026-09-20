# Release — building and shipping SoftCora-POS-Setup.exe

## 1. Pre-flight

- [ ] `cd offline && npm test` → **18/18** end-to-end checks and **11/11**
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
| `SoftCoraPOS-portable-win64.zip` | the same till, unzip-and-run |
| `SoftCora-POS.exe.sha256`, `sha256.txt` | checksums for verification |
| `RELEASE-NOTES.md` | the versioned notes with this build's checksums filled in |

The pipeline itself: esbuild bundle → Node SEA blob (screen embedded as assets)
→ postject into `node-win-x64` → **self-test of the same blob on the build host**
(boot, serve screen, create DB, run CLI) → pack. Tools (`esbuild`, `postject`,
`7zip-bin`, the SFX stub, the Windows runtime) are fetched from the npm registry
with integrity verification; nothing else is needed on the build machine beyond
Node ≥ 22.5, bash and python3.

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
3. Checksums recorded (`sha256.txt`) — paste them into the release notes issue.
4. **Windows verification** (VM or a bench PC, once per release):
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
   dispatched branch), **`tag`** is which *release* to attach it to (`latest`
   means the repository's latest). A `v*` tag push runs it automatically with
   both pointing at the tag. Uploads use `--clobber` and the notes are rewritten
   from that same build, so the assets and the checksums on a release page
   always belong to one run.

## 3a. Re-cutting a release — same version, fixed bytes

For a packaging bug that never worked on a real PC, the version is not the
problem, so it does not move. What has to change is the bytes and the story:

1. Fix on a branch. `npm test` (18/18 + 11/11) and `npm run check:installer`
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

## 5. Known packaging notes

- The installer is an **unsigned** 7-Zip SFX — SmartScreen/AV reputation:
  expected on new builds, improves with age; code-signing (e.g. osslsigncode
  against the SFX stub, or a signed Electron-style wrapper later) is the
  tracked improvement. Checksums in `sha256.txt` are the interim integrity story.
- The Windows runtime comes from the verified npm package `node-win-x64`
  (integrity-checked at build; see `RUNTIME.txt` inside the payload).
- The app embeds no secrets; the only secret a till owns is minted during
  activation on the customer's machine.

## 6. Release checklist summary

```
npm test (18/18 + 11/11)  →  npm run check:installer  →  quasar build
→  VERSION.txt + release notes  →  build-windows.sh  →  verify artifacts +
checksums  →  Windows bench test  →  gh release  →  announce +
TROUBLESHOOTING.md pointer
```
