<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Sync Center surface (local agent API) exists only on an Offline
 * installation. Online, these routes 404 as if they were never registered.
 */
class OfflineMode
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('offline.enabled')) {
            abort(404);
        }

        return $next($request);
    }
}
