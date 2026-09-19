<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the offline-POS administration surface (Settings → Devices,
 * Synchronization and Conflicts).
 *
 * Only a super admin, the platform owner or a user holding one of the abilities
 * named on the route may pass. This is what keeps "who may accept a device's
 * version of yesterday's takings" an explicit, auditable decision.
 */
class SyncAdmin
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $user = $request->user();

        abort_unless($user, 401, 'Unauthenticated.');

        if ((bool) $user->is_super_admin || $user->isPlatformOwner()) {
            return $next($request);
        }

        foreach ($abilities as $ability) {
            if ($user->can($ability)) {
                return $next($request);
            }
        }

        abort(403, 'You are not allowed to manage offline devices or sync conflicts.');
    }
}
