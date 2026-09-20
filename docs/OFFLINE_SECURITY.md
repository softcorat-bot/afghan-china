# Offline Security Model — SoftCora POS till (as implemented)

## 1. Credentials stored on the till

| Secret | Form on disk | Notes |
|---|---|---|
| staff password | scrypt hash (`scrypt$N$salt$hash`, N=16384, per-user random salt) | Never plaintext, never reversible. Verified with `crypto.timingSafeEqual`. |
| staff PIN | same scrypt format, separate column | Password vs PIN type is explicit; a PIN never authorizes what only a password may. |
| session token | only its **hash** lives in `sessions.token_hash`; the bearer itself exists in the browser/js memory | Sessions expire after 12 h and are device-scoped |
| device server token | `data\device.json`, file mode **0600** (owner-only) | Issued once at activation; `token/rotate` endpoint exists; replacing the file = new registration, old one revocable from the web admin |
| activation code | **never stored anywhere** — the server holds only `sha256(code)` (`activation_code_hash`) and the code is displayed once | Guessing is bounded: short code + server-side expiry (default 14 days) + one use |

There are no development credentials in the build. The seeded demo logins of the
**web** system stay in the server seeders and never reach the till.

## 2. Offline sign-in rules

- Wrong password/PIN is refused offline (hash comparison, no fallback).
- Unknown user is refused; first online sign-in caches the user (roles +
  permissions + hash) for future offline sessions.
- Roles/permissions are cached and enforced by the till UI/API; anything not
  cached is simply unavailable offline (fail closed).
- Revocation: disabling a user centrally removes them on the next pull; a lost
  device is neutralized by *Devices → Disable/Revoke* (the sync service answers
  401/403 `device_token_invalid` / `device_blocked`, the till logs it to
  `security.log` and flags the state instead of looping).
- Password changes happen online; the new hash replaces the old cache at the
  next online sign-in or staff re-provision.

## 3. The local API (127.0.0.1:7817)

- Binds `127.0.0.1` in the installed launcher (`start-till.cmd`); `0.0.0.0` only
  when the operator sets `SOFTCORA_HOST` deliberately (shop-LAN display).
- Everything mutating requires a valid session token (Authorization: Bearer) —
  the single exception is the narrow first-run lane (`/api/setup`, first
  `/api/staff`, `/api/device/register`) which closes forever once a user exists
  (`server.mjs: staffCount guard`).
- Input: JSON body parsing with size/body errors contained; all SQL is
  parameterized through prepared statements (no string-built SQL anywhere in
  `offline/src`); static file serving rejects `..` traversal.
- CORS: the local API answers cross-origin because the till screen may be opened
  from the shop's own browser/preview host. It still requires auth; binds to a
  loopback address; and carries no cookies (XSRF n/a) — token-only.
- No TLS on 127.0.0.1 (loopback is the OS's own boundary); server traffic is
  HTTPS by configuration — `server_url` must be `https://` in production
  (`SyncClient` rejects nothing, so this is an operator checklist item).

## 4. Sync transport security

- Every sync call carries `x-device-id` + `Authorization: Bearer <device token>`;
  the server's `device_auth` middleware verifies the token hash, device status
  and expiry, and rejects disabled/revoked devices distinctly.
- Device tokens are single-rotating secrets; a leak means revoking one device,
  not the fleet.
- Payloads contain no passwords/hashes; customer PII is limited to what sales
  need (name/phone), per the cached-reference model.

## 5. Web admin authorization

The device fleet, sync monitor and Conflict Center routes sit behind Sanctum +
the `sync_admin` middleware (super admin / platform owner, or explicit
`device-list`, `manage-devices`, `sync-conflict-*`, `resolve-sync-conflicts`,
`override-financial-sync-conflicts` — all seeded in `PermissionSeeder`; the new
Quasar pages at `/pos-devices`, `/sync-monitor`, `/sync-conflicts` respect the
same abilities). Every admin action on a device or conflict is written to the
activity log.

## 6. Logging without leaking

`logs\security.log` records sign-ins (ok/refused), sign-outs, registrations,
staff changes, device blocks — **never** the secrets themselves. Passwords,
tokens and PINs are scrubbed by the logger's redactor before touching disk, and
the e2e suite asserts a known password does not appear in any log file.

## 7. Physical/machine-level guidance

- The till runs as the shop's Windows user; `device.json` 0600 + per-user
  install means another Windows account cannot read the token.
- Database file: contain it by OS file permissions, not obscurity. A stolen
  laptop cannot be unlocked without a staff credential; disable the device from
  the web admin to cut its sync.
- BitLocker on the till PC is recommended; screen-lock policy is shop policy.
- Antivirus: the installer is an unsigned 7-Zip SFX — expect SmartScreen
  reputation prompts on fresh builds; code signing is the tracked next step
  (see RELEASE.md).
- The printer share must point at a **local** share name; the till refuses
  UNC paths it did not configure (`print.mjs` uses the configured value only).

## 8. Explicit non-goals (stated so nobody assumes)

- No disk encryption inside the app (OS-level: BitLocker).
- No remote wipe from the server (disable/revoke is the supported kill switch).
- The till's embedded screen is not served over HTTPS (loopback only, by design).
