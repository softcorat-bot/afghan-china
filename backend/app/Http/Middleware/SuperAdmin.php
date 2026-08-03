<?php
namespace App\Http\Middleware;
use Closure;
class SuperAdmin {
    public function handle($request, Closure $next) {
        abort_unless($request->user()?->is_super_admin, 403, 'Super admin only');
        return $next($request);
    }
}
