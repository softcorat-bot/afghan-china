<?php

namespace App\Http\Middleware;

use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active company from the authenticated user and exposes it to
 * the global CompanyScope for the duration of the request.
 */
class SetTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->current_company) {
            Tenant::set((int) $user->current_company);
        }

        $response = $next($request);

        Tenant::clear();

        return $response;
    }
}
