<?php

namespace App\Console\Commands;

use App\Models\OfflineMeta;
use App\Services\Offline\CentralClient;
use App\Services\Offline\DeviceIdentity;
use App\Services\Offline\OfflineSyncException;
use Illuminate\Console\Command;

class OfflineRegisterCommand extends Command
{
    protected $signature = 'offline:register
        {--code= : one-time activation code from Central (Settings → Devices)}
        {--device= : device id for this installation (generated when omitted)}
        {--name= : friendly till name shown in Central}';

    protected $description = 'Register this offline installation with Central (one-time activation code).';

    public function handle(): int
    {
        $code = $this->option('code') ?: $this->ask('Activation code');
        $deviceId = $this->option('device') ?: DeviceIdentity::load()['device_id'] ?: DeviceIdentity::suggest();

        if (! $code) {
            $this->error('An activation code is required. An administrator creates one in Central under Settings → Devices.');

            return self::FAILURE;
        }

        try {
            $answer = CentralClient::fromIdentity()->register($deviceId, $code, $this->option('name'));
        } catch (OfflineSyncException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        DeviceIdentity::save([
            'device_id' => $answer['device']['device_id'] ?? $deviceId,
            'device_token' => $answer['device_token'] ?? null,
            'company_id' => $answer['company_id'] ?? null,
            'branch_id' => $answer['branch_id'] ?? null,
            'name' => $answer['device']['name'] ?? $this->option('name'),
        ]);

        OfflineMeta::set('device.company_id', $answer['company_id'] ?? null);
        OfflineMeta::set('device.branch_id', $answer['branch_id'] ?? null);

        $this->info('Registered as '.($answer['device']['device_id'] ?? $deviceId).'. Next: php artisan offline:seed');

        return self::SUCCESS;
    }
}
