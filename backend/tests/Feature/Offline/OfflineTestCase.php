<?php

namespace Tests\Feature\Offline;

use App\Models\Company;
use App\Models\User;
use App\Providers\OfflineServiceProvider;
use App\Providers\SyncServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class OfflineTestCase extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        // TEMPORARY CI DEBUG PROBE: PHPUnit only reports "Premature end of PHP
        // process" when the runner dies mid-test. This prints the real fatal
        // (or proves a clean exit/die) via the shutdown handler. Remove once green.
        if (! defined('OFFLINE_SHUTDOWN_PROBE')) {
            define('OFFLINE_SHUTDOWN_PROBE', true);
            register_shutdown_function(function () {
                $e = error_get_last();
                $peak = (int) (memory_get_peak_usage(true) / 1024 / 1024);
                $limit = ini_get('memory_limit');
                $line = 'OFFLINE-SHUTDOWN-PROBE peak='.$peak.'M limit='.$limit.' error='.($e ? ($e['message'].' @ '.$e['file'].':'.$e['line']) : '(none - process ended via exit/die, or segfault killed handlers)');
                @file_put_contents(getcwd().'/offline-shutdown-probe.txt', $line."\n");
                fwrite(STDERR, "\n".$line."\n");
            });
        }

        $this->company = Company::create(['name_en' => 'Offline Test Co']);
    }

    /**
     * Flip this test into Offline Mode. The app booted with OFFLINE_MODE=false,
     * so the observers are (re-)registered by hand on a fresh dispatcher —
     * exactly the listeners a real offline boot would have, no duplicates.
     */
    protected function enableOfflineMode(): void
    {
        config()->set('offline.enabled', true);

        Model::unsetEventDispatcher();
        Model::setEventDispatcher($this->app['events']);

        (new SyncServiceProvider($this->app))->boot();
        (new OfflineServiceProvider($this->app))->boot();
    }

    protected function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_super_admin' => true])->save();

        return $user;
    }
}
