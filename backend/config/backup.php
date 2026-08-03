<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where the nightly backup is written locally
    |--------------------------------------------------------------------------
    | Always kept on the server itself. This is the copy the Backups screen
    | lists and lets an admin download.
    */

    'destination' => env('BACKUP_DISK', 'local'),

    /** How many local copies to keep before the oldest is pruned. */
    'keep' => (int) env('BACKUP_KEEP', 14),

    /*
    |--------------------------------------------------------------------------
    | Off-site copy — the answer to "a fire takes the server AND the backups"
    |--------------------------------------------------------------------------
    | Set BACKUP_OFFSITE_DISK to any disk configured in config/filesystems.php
    | (s3, an FTP/SFTP box, a mounted network drive, Dropbox…) and every nightly
    | backup is pushed there after the local copy succeeds. Leave it empty and
    | the feature stays off — the local backup is unaffected either way.
    |
    | Nothing here is provider-specific on purpose: the shop can move from a
    | USB share to S3 by changing one .env line, with no code change.
    */

    'offsite_disk' => env('BACKUP_OFFSITE_DISK'),

    /** Folder inside the off-site disk. */
    'offsite_path' => env('BACKUP_OFFSITE_PATH', 'afghan-china-backups'),

    /** How many off-site copies to keep (0 = keep everything). */
    'offsite_keep' => (int) env('BACKUP_OFFSITE_KEEP', 30),

    /*
    |--------------------------------------------------------------------------
    | Email the backup
    |--------------------------------------------------------------------------
    | A second, independent destination — useful when there is no cloud
    | account at all. Only sensible for the SQLite file while it is small;
    | anything over the cap below is reported but not attached.
    */

    'email_to' => env('BACKUP_EMAIL_TO'),

    /** Skip the attachment above this size (MB) so mail never bounces. */
    'email_max_mb' => (int) env('BACKUP_EMAIL_MAX_MB', 15),

];
