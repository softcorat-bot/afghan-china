# Afghan China Offline — Windows installer

Builds **`Afghan-China-Offline-Setup.exe`**: the one-click install of Offline
Mode for a clean shop PC. No admin DB setup, no developer tools on the PC.

## What the user gets

| | |
|---|---|
| Program | `C:\Program Files\Afghan China Offline\` (PHP runtime, Laravel backend, bundled dashboard, launchers) |
| Data | `C:\ProgramData\AfghanChina\data\offline.sqlite` (+ `backups\`) — writable by normal users, survives upgrades and (by default) uninstall |
| Start | Start Menu → Afghan China (server + dashboard), optional autostart + desktop icon |
| App | `http://127.0.0.1:8080/app/` — the same dashboard, talking to the PC itself |

## Building

On Windows with PHP 8.3+, Composer, Node and pnpm installed:

```powershell
# 1. Assemble the payload (runtime + backend + built SPA)
powershell -ExecutionPolicy Bypass -File installer\windows\build-payload.ps1

# 2. Compile (Inno Setup 6)
ISCC.exe installer\windows\AfghanChinaOffline.iss
# -> installer\windows\dist\Afghan-China-Offline-Setup.exe
```

The PHP runtime is pinned (`-PhpVersion`, default `8.4.25`, overridable via
`AFGHANCHINA_PHP_VERSION`) and the builder refuses to continue if the downloaded
runtime reports a different version. The payload is linted (ASCII-only scripts)
and its own `pdo_sqlite` is smoke-tested before it can ship.

## What install does

1. Copies the program; creates the data dir with users-modify rights.
2. Asks for the **Central URL** (skippable; editable later in `backend\.env`).
3. Writes `backend\.env` from the template (**never overwrites an existing one**),
   generates the app key, runs `migrate --force`, points `app\config.js` at the
   local server.
4. On **upgrade** with an existing database: takes a `pre-upgrade` backup first.
5. On **uninstall**: asks whether to delete local data (default: keep; silent
   uninstalls always keep).

## CI

`.github/workflows/offline-installer.yml` builds the Setup.exe on `windows-latest`,
runs the backend offline test suite, then installs silently, runs `verify.ps1`
(payload present → runtime healthy → agent answers → server boots and serves API
+ dashboard), and proves uninstall preserves the database. The `.exe` + `sha256.txt`
are uploaded as artifacts.
