<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * System-wide search. Only queries entities the user may list. Returns
 * grouped, navigable hits. Module entities (products, customers, sales…)
 * register their own groups here as their modules ship.
 */
class SearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['groups' => []]);
        }

        $user = $request->user();
        $like = '%'.$q.'%';
        $groups = [];

        $add = function (string $type, string $icon, $rows) use (&$groups) {
            if ($rows->isNotEmpty()) {
                $groups[] = ['type' => $type, 'icon' => $icon, 'items' => $rows->values()];
            }
        };

        if ($user->can('user-list')) {
            $add('Users', 'manage_accounts', User::query()
                ->where('company_id', $user->current_company)
                ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like))
                ->limit(6)->get()
                ->map(fn ($r) => ['label' => $r->name, 'sub' => $r->email ?? '', 'to' => '/users']));
        }

        if ($user->can('branch-list')) {
            $add('Branches', 'store', Branch::query()
                ->where('name', 'like', $like)
                ->limit(6)->get()
                ->map(fn ($r) => ['label' => $r->name, 'sub' => $r->active ? 'active' : 'inactive', 'to' => '/branches']));
        }

        return response()->json(['groups' => $groups]);
    }
}
