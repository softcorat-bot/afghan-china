<?php

namespace App\Console\Commands;

use App\Services\Offline\OfflineSyncException;
use App\Services\Offline\SyncRunner;
use Illuminate\Console\Command;

class OfflineSyncCommand extends Command
{
    protected $signature = 'offline:sync
        {--push : push local changes (default: full cycle)}
        {--pull : pull central changes (default: full cycle)}
        {--tables= : comma-separated pull tables (default: server decides)}
        {--reason=manual : why this sync is running (logged + shown)}';

    protected $description = 'Run one offline synchronization cycle against Central (push, pull, ack).';

    public function handle(): int
    {
        set_time_limit(0);

        $push = $this->option('push') || ! $this->option('pull');
        $pull = $this->option('pull') || ! $this->option('push');
        $tables = $this->option('tables')
            ? array_filter(array_map('trim', explode(',', (string) $this->option('tables'))))
            : [];

        try {
            $summary = SyncRunner::make()->run([
                'push' => $push,
                'pull' => $pull,
                'tables' => $tables,
                'reason' => 'cli:'.$this->option('reason'),
            ]);
        } catch (OfflineSyncException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $p = $summary['push'];
        $this->info("Push: {$p['applied']} applied, {$p['duplicates']} duplicates, {$p['conflicts']} conflicts, {$p['rejected']} rejected, {$p['errors']} errors.");

        $q = $summary['pull'];
        $this->info("Pull: {$q['applied']} applied, {$q['failed']} failed, {$q['conflicts']} conflicts. Cursor {$summary['cursor_before']} → {$summary['cursor_after']}.");

        $failed = $p['rejected'] + $p['errors'] + $q['failed'];

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
