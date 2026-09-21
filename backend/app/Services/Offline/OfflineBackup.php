<?php

namespace App\Services\Offline;

use Illuminate\Support\Facades\DB;

/**
 * SQLite backup/restore for the Offline installation.
 *
 * Backup uses VACUUM INTO — a transactionally-consistent snapshot taken by
 * SQLite itself, so there is no torn copy and no need to stop the app or chase
 * -wal/-shm files. The device identity rides alongside every backup.
 *
 * Restore validates the file header, drops connections, swaps the file and
 * removes the journal sidecars so the database re-opens cleanly in WAL mode.
 */
class OfflineBackup
{
    public static function dir(): string
    {
        $db = (string) config('database.connections.sqlite.database');

        if ($db === '' || $db === ':memory:') {
            throw new \RuntimeException('No SQLite file database is configured — nothing to back up.');
        }

        $dir = dirname($db).DIRECTORY_SEPARATOR.'backups';

        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $dir;
    }

    /** @return array{file:string, size:int, kept:int} */
    public static function run(?string $label = null): array
    {
        $db = (string) config('database.connections.sqlite.database');

        if (! is_file($db)) {
            throw new \RuntimeException('Database file not found: '.$db);
        }

        $name = 'offline-'.now()->format('Ymd-His').($label ? '-'.preg_replace('/[^a-z0-9-_]+/i', '', $label) : '').'.sqlite';
        $file = static::dir().DIRECTORY_SEPARATOR.$name;

        $escaped = str_replace("'", "''", $file);
        DB::connection()->getPdo()->exec("VACUUM INTO '{$escaped}'");

        // The identity is tiny and losing it bricks syncing: keep it with the backup.
        if (is_file(DeviceIdentity::file())) {
            copy(DeviceIdentity::file(), $file.'.device.json');
            @chmod($file.'.device.json', 0600);
        }

        $kept = static::prune();

        \Illuminate\Support\Facades\Log::channel('offline')->info('backup created', ['file' => $file]);

        return ['file' => $file, 'size' => filesize($file), 'kept' => $kept];
    }

    /** @return array<int, array{file:string, name:string, size:int, created_at:string, has_identity:bool}> */
    public static function list(): array
    {
        $files = glob(static::dir().DIRECTORY_SEPARATOR.'offline-*.sqlite') ?: [];
        rsort($files);

        return array_map(fn ($f) => [
            'file' => $f,
            'name' => basename($f),
            'size' => filesize($f),
            'created_at' => date('c', filemtime($f)),
            'has_identity' => is_file($f.'.device.json'),
        ], $files);
    }

    public static function restore(string $file): array
    {
        $real = realpath($file) ?: $file;

        if (! is_file($real)) {
            throw new \RuntimeException('Backup file not found: '.$file);
        }

        $head = (string) file_get_contents($real, false, null, 0, 16);

        if ($head !== "SQLite format 3\0") {
            throw new \RuntimeException('Refusing to restore: '.$file.' is not a SQLite database.');
        }

        $db = (string) config('database.connections.sqlite.database');

        // Safety first: snapshot the current database before replacing it.
        if (is_file($db)) {
            static::run('pre-restore');
        }

        DB::purge();

        foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
            if ($suffix !== '' && is_file($db.$suffix)) {
                @unlink($db.$suffix);
            }
        }

        if (! copy($real, $db)) {
            throw new \RuntimeException('Could not write the database file. Check disk space and permissions.');
        }

        if (is_file($real.'.device.json')) {
            DeviceIdentity::save(json_decode((string) file_get_contents($real.'.device.json'), true) ?: []);
        }

        // Re-open and prove it is a database we can read.
        DB::reconnect()->getPdo()->query('SELECT count(*) FROM sqlite_master')->fetchColumn();

        \Illuminate\Support\Facades\Log::channel('offline')->warning('database restored', ['from' => $real]);

        return ['file' => $db, 'size' => filesize($db)];
    }

    private static function prune(): int
    {
        $keep = max(1, (int) config('offline.keep_backups', 14));
        $files = glob(static::dir().DIRECTORY_SEPARATOR.'offline-*.sqlite') ?: [];
        rsort($files);

        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
            @unlink($old.'.device.json');
        }

        return min(count($files), $keep);
    }
}
