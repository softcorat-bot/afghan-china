<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

// Daily automatic database backup: local copy, pruned to the newest few, then
// pushed off-site if config/backup.php names a disk. Runnable by hand with
// `php artisan acsc:backup`.
Schedule::command('acsc:backup')->dailyAt('01:00')->withoutOverlapping();

// Offline Mode: automatic sync when the shop wants it. OFFLINE_AUTO_SYNC_MINUTES=0
// (the default) means manual "Sync Now" only — automatic sync never sneaks on.
if (config('offline.enabled') && (int) config('offline.auto_sync_minutes', 0) > 0) {
    Schedule::command('offline:sync --reason=auto')->withoutOverlapping()
        ->cron('*/'.(int) config('offline.auto_sync_minutes', 0).' * * * *');
}

