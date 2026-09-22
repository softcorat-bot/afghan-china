<?php

namespace App\Console\Commands;

use App\Models\OfflineMeta;
use App\Models\OfflineOutbox;
use App\Models\SyncConflict;
use App\Services\Offline\CentralClient;
use App\Services\Offline\DeviceIdentity;
use App\Services\Offline\OfflineSyncException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class OfflineStatusCommand extends Command
{
    protected $signature = 'offline:status {--probe : also ask Central if this device is reachable}';

    protected $description = 'Show offline sync state: device, cursor, outbox counts, last run.';

    public function handle(): int
    {
        $identity = DeviceIdentity::load();

        $this->line('Mode:         '.(config('offline.enabled') ? 'OFFLINE' : 'online'));
        $this->line('Device:       '.($identity['device_id'] ?? '(none)').(DeviceIdentity::isRegistered() ? ' (registered)' : ' (NOT registered)'));
        $this->line('Central:      '.(config('offline.central_url') ?: '(not set)'));
        $this->line('Cursor:       '.OfflineMeta::get('sync.cursor', 0));
        $this->line('Seeded:       '.(OfflineMeta::get('sync.seeded_at') ?: 'never'));
        $this->line('Last run:     '.(OfflineMeta::get('sync.last_run_at') ?: 'never'));
        $this->line('Last clean:   '.(OfflineMeta::get('sync.last_ok_at') ?: 'never'));
        $this->line('Last error:   '.(OfflineMeta::get('sync.last_error') ?: '—'));

        $counts = OfflineOutbox::query()->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');
        $n = fn ($status) => (int) ($counts[$status] ?? 0);
        $this->line('Outbox:       pending='.$n(OfflineOutbox::STATUS_PENDING)
            .' processing='.$n(OfflineOutbox::STATUS_PROCESSING)
            .' synced='.$n(OfflineOutbox::STATUS_SYNCED)
            .' failed='.$n(OfflineOutbox::STATUS_FAILED)
            .' conflict='.$n(OfflineOutbox::STATUS_CONFLICT));
        $this->line('Conflicts:    '.SyncConflict::withoutGlobalScopes()->where('status', SyncConflict::STATUS_PENDING)->count().' pending locally');

        if ($this->option('probe')) {
            try {
                $status = CentralClient::fromIdentity()->status(8);
                $this->info('Central:      reachable (server_seq='.($status['server_seq'] ?? '?').', central conflicts pending='.($status['conflicts_pending'] ?? '?').')');
            } catch (OfflineSyncException $e) {
                $this->warn('Central:      '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
