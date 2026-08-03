<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Driver-aware database backups. On PostgreSQL (the production source of truth)
 * exports/imports use pg_dump / pg_restore in custom format; on SQLite (dev)
 * the database file is copied. Backups are kept in storage/app/backups (newest
 * 14); a daily scheduled run lives in routes/console.php. Restores always take
 * a safety backup of current data first so the operation is reversible.
 */
class BackupController extends Controller
{
    public static function run(): string
    {
        Storage::makeDirectory('backups');
        $stamp = now()->format('Ymd-His');

        if (self::driver() === 'pgsql') {
            $name = "backup-{$stamp}.dump";
            $path = Storage::path('backups/'.$name);
            self::pg('pg_dump', ['--format=custom', '--no-owner', '--file='.$path]);
        } else {
            $source = database_path('database.sqlite');
            abort_unless(file_exists($source), 404, 'Database file not found.');
            $name = "backup-{$stamp}.sqlite";
            Storage::put('backups/'.$name, file_get_contents($source));
        }

        $files = collect(Storage::files('backups'))->sortDesc()->values();
        $files->slice((int) config('backup.keep', 14))->each(fn ($f) => Storage::delete($f));

        // A backup that only ever lives on the machine it is protecting is not
        // really a backup. Push a copy off the box — but never let that failure
        // destroy the local one, which has already succeeded by this point.
        self::sendOffsite($name);

        return $name;
    }

    /**
     * Copy the finished backup to the configured off-site disk and/or email it.
     * Both are optional and independent; each failure is logged and swallowed.
     */
    public static function sendOffsite(string $name): void
    {
        $localPath = 'backups/'.$name;

        if ($disk = config('backup.offsite_disk')) {
            try {
                $remoteDir = trim((string) config('backup.offsite_path', ''), '/');
                $remote = ($remoteDir ? $remoteDir.'/' : '').$name;

                // Streamed, so a large dump never has to fit in memory.
                Storage::disk($disk)->writeStream($remote, Storage::readStream($localPath));

                $keep = (int) config('backup.offsite_keep', 30);
                if ($keep > 0) {
                    collect(Storage::disk($disk)->files($remoteDir ?: '/'))
                        ->sortDesc()->values()->slice($keep)
                        ->each(fn ($f) => Storage::disk($disk)->delete($f));
                }

                ActivityLog::log('created', 'Backup', "Backup {$name} copied off-site to [{$disk}]");
            } catch (\Throwable $e) {
                report($e);
                ActivityLog::log('updated', 'Backup', "Off-site copy of {$name} FAILED: ".$e->getMessage());
            }
        }

        if ($to = config('backup.email_to')) {
            try {
                $bytes = Storage::size($localPath);
                $capMb = (int) config('backup.email_max_mb', 15);
                if ($bytes > $capMb * 1024 * 1024) {
                    ActivityLog::log('updated', 'Backup', "Backup {$name} too large to email (".round($bytes / 1048576, 1)." MB > {$capMb} MB)");

                    return;
                }

                \Illuminate\Support\Facades\Mail::raw(
                    "Afghan China MIS — automatic backup\nFile: {$name}\nTaken: ".now()->toDateTimeString(),
                    fn ($m) => $m->to($to)
                        ->subject("Afghan China MIS backup — {$name}")
                        ->attachData(Storage::get($localPath), $name)
                );

                ActivityLog::log('created', 'Backup', "Backup {$name} emailed to {$to}");
            } catch (\Throwable $e) {
                report($e);
                ActivityLog::log('updated', 'Backup', "Emailing {$name} FAILED: ".$e->getMessage());
            }
        }
    }

    public function download(): BinaryFileResponse
    {
        $name = static::run();

        return response()->download(Storage::path('backups/'.$name), $name);
    }

    public function index(): JsonResponse
    {
        $files = collect(Storage::files('backups'))->sortDesc()->values()->map(fn ($f) => [
            'name' => basename($f),
            'size' => Storage::size($f),
            'created_at' => date('Y-m-d H:i', Storage::lastModified($f)),
        ]);

        return response()->json($files);
    }

    /**
     * Restore from an uploaded backup. A safety backup of current data is taken
     * first, then the file is validated against the active driver before the
     * live database is replaced.
     */
    public function restore(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:512000'],
        ]);

        $tmp = $request->file('file')->getRealPath();
        $header = (string) file_get_contents($tmp, false, null, 0, 16);

        $safety = static::run();

        if (self::driver() === 'pgsql') {
            abort_unless(str_starts_with($header, 'PGDMP'), 422, 'That file is not a valid PostgreSQL backup (.dump).');
            self::pg('pg_restore', ['--clean', '--if-exists', '--no-owner', '--single-transaction', $tmp]);
        } else {
            abort_unless(str_starts_with($header, 'SQLite format 3'), 422, 'That file is not a valid database backup (.sqlite).');
            abort_unless(@copy($tmp, database_path('database.sqlite')), 500, 'Could not write the database file.');
        }

        ActivityLog::log('restored', 'Backup', "Database restored from an uploaded backup (safety copy: {$safety})");

        return response()->json([
            'message' => 'Database restored successfully.',
            'safety_backup' => $safety,
        ]);
    }

    private static function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    /**
     * Run a pg_dump/pg_restore command against the active pgsql connection with
     * credentials supplied out-of-band (PGPASSWORD) so they never hit the shell.
     */
    private static function pg(string $binary, array $args): void
    {
        $c = config('database.connections.'.config('database.default'));

        $base = [
            $binary,
            '--host='.($c['host'] ?? '127.0.0.1'),
            '--port='.($c['port'] ?? '5432'),
            '--username='.($c['username'] ?? 'postgres'),
            '--dbname='.($c['database'] ?? ''),
        ];

        $result = Process::env(['PGPASSWORD' => (string) ($c['password'] ?? '')])
            ->timeout(600)
            ->run(array_merge($base, $args));

        abort_unless($result->successful(), 500, 'Database operation failed: '.trim($result->errorOutput()));
    }
}
