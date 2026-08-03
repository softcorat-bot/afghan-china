<?php

namespace App\Console\Commands;

use App\Http\Controllers\BackupController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * The nightly backup, also runnable by hand — so an owner can prove the
 * off-site copy works instead of discovering at 3am that it never did.
 */
class RunBackup extends Command
{
    protected $signature = 'acsc:backup';

    protected $description = 'Take a database backup and push it off-site (see config/backup.php)';

    public function handle(): int
    {
        $this->info('Taking backup…');

        try {
            $name = BackupController::run();
        } catch (\Throwable $e) {
            $this->error('Backup FAILED: '.$e->getMessage());

            return self::FAILURE;
        }

        $size = round(Storage::size('backups/'.$name) / 1048576, 2);
        $this->line("  ✔ local   storage/app/backups/{$name} ({$size} MB)");

        if ($disk = config('backup.offsite_disk')) {
            $this->line("  ✔ offsite disk [{$disk}] → ".trim((string) config('backup.offsite_path'), '/')."/{$name}");
        } else {
            $this->warn('  ! no off-site copy — set BACKUP_OFFSITE_DISK in .env so a lost server does not take the backups with it');
        }

        if ($to = config('backup.email_to')) {
            $this->line("  ✔ emailed to {$to}");
        }

        return self::SUCCESS;
    }
}
