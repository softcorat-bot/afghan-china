<?php

use App\Providers\AppServiceProvider;
use App\Providers\OfflineServiceProvider;
use App\Providers\SyncServiceProvider;

return [
    AppServiceProvider::class,
    SyncServiceProvider::class,
    OfflineServiceProvider::class,
];
