<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\BackupService;
use Illuminate\Console\Command;

class DailyBackup extends Command
{
    protected $signature = 'backup:daily {--company=}';
    protected $description = 'Create daily backups for all companies';

    public function handle()
    {
        $companies = $this->option('company')
            ? Company::where('id', $this->option('company'))->get()
            : Company::where('active', true)->get();

        $this->info("Starting daily backup for " . $companies->count() . " company(ies)...");

        foreach ($companies as $company) {
            try {
                $this->info("Backing up {$company->name}...");
                $backup = new BackupService($company);
                $log = $backup->backup();

                $this->line("✓ Backup completed: {$log->backup_file} ({$log->backup_size} bytes)");
            } catch (\Exception $e) {
                $this->error("✗ Backup failed for {$company->name}: {$e->getMessage()}");
            }
        }

        $this->info('Daily backup completed.');
    }
}
