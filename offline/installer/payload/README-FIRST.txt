SoftCora POS - offline point of sale
====================================

This setup installs a complete till on this computer. It needs no internet, no
PHP, no database server and nothing else installed: the program brings its own
runtime, and keeps its data in a folder of its own.

Install
-------
Double-click SoftCora-POS-Setup.exe, or run it from a USB stick. Windows may
show a SmartScreen notice because the file is not signed; choose "More info"
and then "Run anyway". No administrator password is required.

Setup writes every step to a log as it goes:

    %LOCALAPPDATA%\SoftCoraPOS\logs\install.log

If it stops, that file says why - send it to your administrator. Nothing is
deleted when an install fails, and the data folder is never touched.

After it finishes, the till opens in your browser:

    http://127.0.0.1:7817

First run
---------
The till opens on a setup panel, because it has no sign-in of its own yet:

1. Register this till (optional now, can be done later)
   - Server address:  https://your-server.example.com
   - Activation code: ask your administrator (Settings -> Devices on the server)
   -> the till receives its own device token. Until you do this it still sells;
      the sales wait inside the till and go up at the first Sync Now.

2. Create the first sign-in - the email and password your staff use on the
   server, so the same credentials work here.
   -> the password is stored on this computer only as a hash, never in clear
      text.

Then: make a product (Sell -> "+ Product"), or wait for the first sync to pull
the catalogue down. More sign-ins and re-registration live in the Settings tab.

Where things live
-----------------
    %LOCALAPPDATA%\SoftCoraPOS\app      the program
    %LOCALAPPDATA%\SoftCoraPOS\data     the till's database, device identity, backups
    %LOCALAPPDATA%\SoftCoraPOS\logs     install.log, till.log and the till's own logs

Updating
--------
Run the new setup over the old one. The data folder is never touched: unsynced
sales, the device identity and the outbox all survive an update, and the till
restarts afterwards. The port already in use on this PC is kept, so a
bookmarked till screen keeps working.

An update checks itself before it replaces anything: it proves the new program
runs on this computer, and asks the existing database whether it is safe to
continue. If either answer is no, setup stops and leaves the working install
alone.

Checking an installation
------------------------
Start menu -> SoftCora POS -> Health check, or in PowerShell:

    powershell -ExecutionPolicy Bypass -File "$env:LOCALAPPDATA\SoftCoraPOS\app\verify.ps1"

It reports the version, the database, the device identity, whether the till is
answering, how many records are still waiting to sync, and the last backup.

Uninstalling
------------
Apps & features -> SoftCora POS -> Uninstall. Your data folder is kept on
purpose (sales that have not reached the server yet live there); delete it by
hand once everything is synced, or run the uninstaller with -RemoveData. If
anything is still unsynced, the uninstaller says how many records would be lost
and asks for the word DELETE before removing them.

Support
-------
Installation and sync details, including how to back the till up, are in
app\README.md and in the project's docs/SYNC_ENGINE.md.
