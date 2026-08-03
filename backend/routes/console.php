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

