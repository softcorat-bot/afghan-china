<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$basePath = dirname(__DIR__);

$app = Application::configure(basePath: $basePath)
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => \App\Http\Middleware\SetTenant::class,
            'branch' => \App\Http\Middleware\SetBranch::class,
            'super_admin' => \App\Http\Middleware\SuperAdmin::class,
            'platform_owner' => \App\Http\Middleware\PlatformOwner::class,
            // Offline POS: the installation authenticates with its own device
            // token (never a user password), and the fleet's admin surface is
            // guarded separately from the rest of the tenant app.
            'device_auth' => \App\Http\Middleware\DeviceAuth::class,
            'sync_admin' => \App\Http\Middleware\SyncAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

return $app;
