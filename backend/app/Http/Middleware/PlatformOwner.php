<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for platform-critical operations. Only the immutable Platform Owner
 * (VIP Root) may pass — never a tenant administrator, regardless of role or
 * the is_super_admin flag.
 */
class PlatformOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            $request->user()?->isPlatformOwner(),
            403,
            'Reserved for the Platform Owner.'
        );

        return $next($request);
    }
}
