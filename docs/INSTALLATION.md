# Installation — SoftCora POS till (Windows)

> For the deeper packaging details see `offline/docs/WINDOWS-INSTALLER.md`.
> This page is the operator-facing path: get a till selling.

## 1. What you need

- A Windows 10/11 PC (x64). **No admin rights required.**
- Nothing else. No WAMP/XAMPP, no PHP, no Node.js, no Composer, no Git.
  Everything the till needs is inside the installer.

## 2. Install

1. Copy **`SoftCora-POS-Setup.exe`** to the till PC.
2. Run it. Windows SmartScreen may warn on a new, unsigned build —
   **More info → Run anyway** (signing is a planned improvement, see RELEASE.md).
3. The installer (a self-extracting archive) extracts and runs the per-user
   installer, which:
   - installs the program to `%LOCALAPPDATA%\SoftCoraPOS\app`
   - creates `%LOCALAPPDATA%\SoftCoraPOS\data` (database, identity, backups)
     and `…\logs` — **created only if missing, never overwritten**
   - creates **Desktop** and **Start Menu** shortcuts ("SoftCora POS")
   - registers the app in *Settings → Apps* for clean uninstall
4. Launch **SoftCora POS** from the desktop. The till service starts and the
   till screen opens in the browser at `http://127.0.0.1:7817`.

## 3. First-run setup (2–5 minutes)

A brand-new till opens on the **setup panel** instead of a login box.

1. *(Optional but recommended)* **Register this till** — so it can sync:
   - On the server, an administrator opens **Settings → Devices → Register a
     till**, names it ("Shop 1 — Counter A"), and copies the one-time
     **activation code** (displayed once).
   - On the till, enter the **server address** (e.g. `https://pos.yourshop.af`)
     and the activation code. The till exchanges the code for a device token
     and pulls the catalogue, customers and settings.
   - Registration can also wait — the till sells with no server at all. Do it
     later under *Settings → Connect this till*.
2. **Create the first sign-in** — the cashier/manager who will use this till
   (email + password or a 4–6 digit PIN). Only hashes are stored.
3. Sign in. Done — the till is selling.

More staff: any already-cached manager adds them under *Settings → Who can sign
in*, or they sign in once while online (their credential is cached hashed).

## 4. Connect the receipt printer

*Settings → Receipt printer*:

- **Any printer (default, "Browser print dialog")** — install the printer in
  Windows normally; receipts open in an 80 mm window and print with one
  Ctrl+P-free click. Recommended for Dari/Pashto receipts.
- **Silent thermal printing ("Silent thermal printer")** —
  1. Windows: printer's Properties → **Sharing** → share as e.g. `POS80`.
  2. Till settings: mode *Silent thermal printer (Windows)*, share name `POS80`.
  3. *Print test receipt* to verify; tick *open the cash drawer* if the drawer
     is wired through the printer (RJ-11).

## 5. Updating

Run the newer `SoftCora-POS-Setup.exe` over the top. The installer:

- stops the running till, replaces **program files only**, restarts it;
- **never touches `data\`** — database, device identity, unsynced (pending)
  sales and settings survive every update;
- upgrades the database schema automatically on first start (additive,
  version-guarded — see OFFLINE_DATABASE.md).

Verify afterwards: the till comes up with last sale present and
`Pending`/`Failed` sync counts unchanged. `app\verify.ps1` does the same check
from PowerShell.

## 6. Uninstall

*Settings → Apps → SoftCora POS → Uninstall* (or `app\uninstall.ps1`).
The uninstaller removes program files and shortcuts; **`data\` is kept unless
you choose otherwise**, because it may hold unsynced sales. If you decommission
the till: sync first (green "Synced"), then uninstall, then revoke the device in
web admin → *Settings → Devices*.

## 7. Portable alternative

`SoftCoraPOS-portable-win64.zip` contains the same till without installation:
unzip anywhere, run `SoftCora POS.cmd`. Data lives beside it in `data\` —
intended for troubleshooting and evaluation, not the shop floor.

## 8. Central server (for completeness)

The web POS + Laravel API install is unchanged (`README.md`, `UPDATE.bat`,
cPanel notes in `docs/DEPLOY_CPANEL.md`). The only server-side requirement the
till adds is the sync surface that is already merged
(migrations `2026_09_19_*`, routes `/api/v1/sync/*`) — i.e. `migrate` once.
