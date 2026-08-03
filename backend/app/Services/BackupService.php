<?php

namespace App\Services;

use App\Models\BackupLog;
use App\Models\Company;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Automated Backup Service
 * Creates daily database backups and stores them to configured destination
 */
class BackupService
{
    private Company $company;

    public function __construct(Company $company)
    {
        $this->company = $company;
    }

    /**
     * Create a backup for this company
     */
    public function backup(): BackupLog
    {
        $log = BackupLog::create([
            'company_id' => $this->company->id,
            'backup_time' => now(),
            'status' => 'in_progress',
            'destination' => config('backup.destination', 'local'),
        ]);

        try {
            $startTime = microtime(true);

            // Create backup directory
            $backupDir = 'backups/' . $this->company->id . '/' . now()->format('Y-m-d');
            Storage::disk(config('backup.destination', 'local'))->makeDirectory($backupDir);

            // Backup database
            $filename = $backupDir . '/' . now()->format('Y-m-d_H-i-s') . '.sql';
            $this->backupDatabase($filename);

            // Backup file uploads
            $this->backupUploads($backupDir);

            $duration = microtime(true) - $startTime;

            $log->update([
                'status' => 'completed',
                'backup_file' => $filename,
                'duration_seconds' => (int)$duration,
                'records_backed_up' => $this->countRecords(),
            ]);

        } catch (\Exception $e) {
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $log;
    }

    /**
     * Backup the database
     */
    private function backupDatabase(string $path): void
    {
        $host = config('database.connections.mysql.host');
        $database = config('database.connections.mysql.database');
        $username = config('database.connections.mysql.username');
        $password = config('database.connections.mysql.password');
        $port = config('database.connections.mysql.port', 3306);

        // Create SQL dump
        $command = sprintf(
            'mysqldump --host=%s --port=%s --user=%s --password=%s %s',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($password),
            escapeshellarg($database)
        );

        $output = shell_exec($command);

        if ($output === null) {
            throw new \Exception('Failed to backup database');
        }

        // Compress the dump
        $compressed = gzcompress($output, 9);

        // Store the backup
        Storage::disk(config('backup.destination', 'local'))
            ->put($path, $compressed);
    }

    /**
     * Backup file uploads
     */
    private function backupUploads(string $backupDir): void
    {
        $uploadDirs = [
            'storage/app/private/products/',
            'storage/app/private/attachments/',
        ];

        foreach ($uploadDirs as $dir) {
            if (is_dir($dir)) {
                $this->backupDirectory($dir, $backupDir);
            }
        }
    }

    /**
     * Recursively backup a directory
     */
    private function backupDirectory(string $dir, string $backupDir): void
    {
        $files = scandir($dir);

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $dir . $file;
            $backupPath = $backupDir . '/' . basename($path);

            if (is_dir($path)) {
                $this->backupDirectory($path . '/', $backupDir);
            } else {
                $content = file_get_contents($path);
                Storage::disk(config('backup.destination', 'local'))->put($backupPath, $content);
            }
        }
    }

    /**
     * Count records in database
     */
    private function countRecords(): int
    {
        $tables = DB::select('SHOW TABLES');
        $count = 0;

        foreach ($tables as $table) {
            $tableName = array_values((array)$table)[0];
            $result = DB::selectOne("SELECT COUNT(*) as count FROM `$tableName`");
            $count += $result->count;
        }

        return $count;
    }

    /**
     * Restore a backup
     */
    public static function restore(BackupLog $log): bool
    {
        if (!$log->backup_file) {
            throw new \Exception('No backup file found');
        }

        try {
            $sql = gzuncompress(
                Storage::disk($log->destination)->get($log->backup_file)
            );

            // Execute SQL restore
            DB::unprepared($sql);

            return true;
        } catch (\Exception $e) {
            throw new \Exception('Failed to restore backup: ' . $e->getMessage());
        }
    }

    /**
     * List all backups for this company
     */
    public function listBackups(): array
    {
        $disk = config('backup.destination', 'local');
        $path = 'backups/' . $this->company->id;

        if (!Storage::disk($disk)->exists($path)) {
            return [];
        }

        return BackupLog::where('company_id', $this->company->id)
            ->orderBy('backup_time', 'desc')
            ->get()
            ->toArray();
    }

    /**
     * Delete old backups (keep last 30 days)
     */
    public function deleteOldBackups(int $daysToKeep = 30): int
    {
        $cutoffDate = now()->subDays($daysToKeep);

        $deleted = BackupLog::where('company_id', $this->company->id)
            ->where('backup_time', '<', $cutoffDate)
            ->delete();

        return $deleted;
    }
}
