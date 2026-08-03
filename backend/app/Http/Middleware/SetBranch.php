<?php

namespace App\Http\Middleware;

use App\Support\Branch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active branch for the request from the X-Branch-Id header (set by
 * the branch switcher) or the user's persisted current_branch, and exposes it to
 * the global BranchScope. Super admins / admins may view "all branches".
 */
class SetBranch
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $seesAll = $user->seesAllBranches();
            $accessible = $seesAll ? null : $user->accessibleBranchIds();

            $header = $request->header('X-Branch-Id');
            $selected = null;

            if ($header !== null && $header !== '') {
                $selected = $header === 'all' ? null : (int) $header;
            } elseif ($user->current_branch) {
                $selected = (int) $user->current_branch;
            }

            // A non-privileged user may only view a branch they belong to.
            if ($selected !== null && ! $seesAll && $accessible !== null && ! in_array($selected, $accessible, true)) {
                $selected = $accessible[0] ?? null;
            }

            // Non-privileged users are always pinned to a branch (never "all").
            if ($selected === null && ! $seesAll && ! empty($accessible)) {
                $selected = $accessible[0];
            }

            if ($selected !== null) {
                Branch::set($selected);
            }
        }

        $response = $next($request);

        Branch::clear();

        return $response;
    }
}
