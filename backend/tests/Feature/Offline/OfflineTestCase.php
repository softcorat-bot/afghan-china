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
