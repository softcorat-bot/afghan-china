<?php

namespace App\Console\Commands;

use App\Services\Offline\OfflineBackup;
use Illuminate\Console\Command;

class OfflineBackupCommand extends Command
{
    protected $signature = 'offline:backup {--label= : short label baked into the file name} {--restore= : restore this backup file instead of creating one}';

    protected $description = 'Back up (or restore) the offline SQLite database.';

    public function handle(): int
    {
        if ($file = $this->option('restore')) {
            if (! $this->confirm("Replace the current database with {$file}? A pre-restore snapshot is taken first.", false)) {
                return self::FAILURE;
            }

            try {
                $result = OfflineBackup::restore($file);
            } catch (\Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $this->info('Restored '.$result['file'].' ('.$this->bytes($result['size']).')');

            return self::SUCCESS;
        }

        try {
            $result = OfflineBackup::run($this->option('label') ?: null);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Backup: '.$result['file'].' ('.$this->bytes($result['size']).', keeping '.$result['kept'].')');

        return self::SUCCESS;
    }

    private function bytes(int $n): string
    {
        return $n > 1048576 ? round($n / 1048576, 1).' MB' : round($n / 1024, 1).' KB';
    }
}
