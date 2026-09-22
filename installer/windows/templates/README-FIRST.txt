Afghan China - Offline Mode
============================

This PC runs the SAME Afghan China application as the online server,
with its own local database. It sells with no internet at all.

FIRST RUN (needs internet ONCE)
--------------------------------
1. Double-click "Afghan China" (Start Menu).
2. Sign in with your usual username and password.
3. Open: System -> Sync Center.
4. Register this PC with the activation code from your administrator
   (Central -> Settings -> Devices -> Register a till).
5. Ask the administrator, or run once in the app folder:
     php\php.exe backend\artisan offline:seed
   This downloads products, staff, customers and roles.
6. Press "Sync Now".

EVERY DAY
---------
- Sell normally. Internet or not, everything works.
- When internet is back: System -> Sync Center -> "Sync Now".
- Pending / Failed / Conflicts are all visible on that page.

WHERE THINGS LIVE
-----------------
- Program:      C:\Program Files\Afghan China Offline
- Your data:    C:\ProgramData\AfghanChina\data\offline.sqlite
- Backups:      C:\ProgramData\AfghanChina\data\backups
- Logs:         <program>\backend\storage\logs\offline.log

Uninstalling NEVER deletes your data unless you tick the box that says so.

IF SOMETHING BREAKS
-------------------
1. System -> Sync Center -> "Back up now" (if the app still opens).
2. Tell your administrator the error shown on the Sync Center page.
3. The log file above has the full story.
