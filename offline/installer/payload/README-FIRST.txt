SoftCora POS — offline point of sale
====================================

This setup installs a complete till on this computer. It needs no internet, no
PHP, no database server and nothing else installed: the program brings its own
runtime, and keeps its data in a folder of its own.

Install
-------
Double-click SoftCora-POS-Setup.exe, or run it from a USB stick. Windows may
show a SmartScreen notice because the file is not signed; choose "More info" →
"Run anyway". No administrator password is required.

After it finishes, the till opens in your browser:
    http://127.0.0.1:7817

First run
---------
The till opens on a setup panel, because it has no sign-in of its own yet:

1. Register this till (optional now, can be done later)
   - Server address:  https://your-server.example.com
   - Activation code: ask your administrator (Settings → Devices on the server)
   → the till receives its own device token. Until you do this it still sells;
     the sales wait inside the till and go up at the first Sync Now.

2. Create the first sign-in — the email and password your staff use on the
   server, so the same credentials work here.
   → the password is stored on this computer only as a hash, never in clear text.

Then: make a product (Sell → "+ Product"), or wait for the first sync to pull the
catalogue down. More sign-ins and re-registration live in the Settings tab.

Where things live
-----------------
    %LOCALAPPDATA%\SoftCoraPOS\app      the program
    %LOCALAPPDATA%\SoftCoraPOS\data     the till's database, device identity, backups
    %LOCALAPPDATA%\SoftCoraPOS\logs     the till's log

Updating
--------
Run the new setup over the old one. The data folder is never touched: unsynced
sales, the device identity and the outbox all survive an update, and the till
restarts afterwards.

Uninstalling
------------
Apps & features → SoftCora POS → Uninstall. Your data folder is kept on purpose
(sales that have not reached the server yet live there); delete it by hand once
everything is synced, or run the uninstaller with -RemoveData.

Support
-------
Installation and sync details, including how to back the till up, are in
data\..\app\README.md and in the project's docs/SYNC_ENGINE.md.
