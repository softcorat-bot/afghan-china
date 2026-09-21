<?php

namespace App\Console\Commands;

use App\Models\OfflineMeta;
use App\Services\Offline\CentralClient;
use App\Services\Offline\DeviceIdentity;
use App\Services\Offline\OfflineSyncException;
use App\Services\Offline\PullApplier;
use Illuminate\Console\Command;

/**
 * First-time baseline: company/RBAC context + the full sequenced stream from
 * cursor 0, applied with Central's ids preserved. Run once per installation
 * (after offline:register); afterwards `offline:sync` continues incrementally.
 */
class OfflineSeedCommand extends Command
{
    protected $signature = 'offline:seed {--force : re-run even if this installation was already seeded}';

    protected $description = 'Seed a fresh offline database from Central (baseline snapshot + full pull).';

    public function handle(): int
    {
        set_time_limit(0);

        if (! config('offline.enabled')) {
            $this->error('OFFLINE_MODE is not enabled.');

            return self::FAILURE;
        }

        if (! DeviceIdentity::isRegistered()) {
            $this->error('Not registered. Run: php artisan offline:register --code XXXX-XXXX');

            return self::FAILURE;
        }

        if (OfflineMeta::get('sync.seeded_at') && ! $this->option('force')) {
            $this->error('Already seeded at '.OfflineMeta::get('sync.seeded_at').'. Use --force to re-seed (existing local data is kept; Central rows win per uuid).');

            return self::FAILURE;
        }

        $central = CentralClient::fromIdentity();
        $applier = new PullApplier;

        try {
            $this->info('Fetching company + roles context…');
            $applier->applyContext($central->context());

            $cursor = 0;
            $total = 0;

            do {
                $response = $central->pull($cursor);
                $page = $applier->applyPage($response['data'] ?? [], $response['deleted'] ?? [], $cursor);
                $total += count($page['applied']);
                $cursor = (int) $page['cursor'];
                OfflineMeta::set('sync.cursor', $cursor);

                $this->info("…{$total} rows (cursor {$cursor})".(count($page['failed']) ? ' — '.count($page['failed']).' failed, see log' : ''));

                try {
                    $central->ack($cursor, $page['applied'], $page['failed']);
                } catch (OfflineSyncException $e) {
                    $this->warn('ack failed (progress kept locally): '.$e->getMessage());
                }
            } while ($response['has_more'] ?? false);
        } catch (OfflineSyncException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        OfflineMeta::set('sync.seeded_at', now()->toIso8601String());
        $this->info("Seeded {$total} rows. Staff can now sign in offline with their usual passwords.");

        return self::SUCCESS;
    }
}
