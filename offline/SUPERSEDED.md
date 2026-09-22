# Superseded: the standalone Node till

This directory (`offline/`) is the **first-generation** offline till: a separate
Node.js POS with its own server, schema, business logic and UI.

It is **superseded by Offline Mode of the one Afghan China application**:

- same Laravel backend (`OFFLINE_MODE=true`), same Quasar dashboard,
- local SQLite via the same migrations,
- sync agent in `backend/app/Services/Offline/*` speaking the same Central
  protocol (`/api/v1/sync/*`),
- Sync Center UI at `/sync-center`,
- Windows installer built from `installer/windows/` (`Afghan-China-Offline-Setup.exe`).

Why it was replaced: a second POS means a second dashboard, duplicated pricing /
stock / permission logic, a subset schema, and every future feature built twice.
See `docs/OFFLINE-UNIFICATION-AUDIT.md` §4–5 for the full audit.

**The code here is kept for reference** (protocol knowledge, installer lessons)
but is no longer installed, shipped, or developed. Do not build on it; build on
Offline Mode (`docs/OFFLINE-MODE.md`).
