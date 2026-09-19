<?php

namespace App\Services\Sync;

use Illuminate\Support\Facades\DB;

/**
 * The single monotonic counter behind incremental sync.
 *
 * Every row that changes gets the next value, so a device can ask for
 * "everything after 41,208" and receive a handful of rows instead of the whole
 * catalogue. On PostgreSQL the database owns the sequence (so writes that never
 * pass through PHP still enter the stream); elsewhere a locked row in
 * `sync_sequences` plays the same role.
 */
class SyncSequence
{
    /** @var array<string, bool> */
    private static array $pgSequenceReady = [];

    public function next(string $name = 'global'): int
    {
        $connection = DB::connection();

        if ($connection->getDriverName() === 'pgsql') {
            $this->ensurePgSequence();

            return (int) $connection->selectOne("select nextval('sync_seq_global') as seq")->seq;
        }

        return (int) $connection->transaction(function () use ($name) {
            $row = DB::table('sync_sequences')->where('name', $name)->lockForUpdate()->first();

            if (! $row) {
                DB::table('sync_sequences')->insert([
                    'name' => $name, 'value' => 0, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $row = DB::table('sync_sequences')->where('name', $name)->lockForUpdate()->first();
            }

            $next = (int) $row->value + 1;
            DB::table('sync_sequences')->where('name', $name)->update(['value' => $next, 'updated_at' => now()]);

            return $next;
        });
    }

    public function current(string $name = 'global'): int
    {
        $connection = DB::connection();

        if ($connection->getDriverName() === 'pgsql') {
            $this->ensurePgSequence();

            return (int) ($connection->selectOne("select last_value from sync_seq_global")->last_value ?? 0);
        }

        return (int) (DB::table('sync_sequences')->where('name', $name)->value('value') ?? 0);
    }

    private function ensurePgSequence(): void
    {
        if (self::$pgSequenceReady['v'] ?? false) {
            return;
        }

        DB::statement('CREATE SEQUENCE IF NOT EXISTS sync_seq_global');
        self::$pgSequenceReady['v'] = true;
    }
}
