<?php

namespace App\Http\Controllers\Role;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        // Users-per-role from the pivot (spatie's users() relation doesn't
        // resolve inside withCount, so count it directly).
        $userCounts = \Illuminate\Support\Facades\DB::table('model_has_roles')
            ->selectRaw('role_id, count(*) as c')
            ->groupBy('role_id')
            ->pluck('c', 'role_id');

        $roles = Role::with('permissions:id,name')
            ->orderBy('name')
            ->get()
            ->map(function (Role $role) use ($userCounts) {
                // Distinct modules this role can touch (from the entity-action names).
                $modules = $role->permissions
                    ->map(fn ($p) => str_contains($p->name, '-') ? explode('-', $p->name)[0] : $p->name)
                    ->unique()->values();

                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'permissions' => $role->permissions,
                    'permissions_count' => $role->permissions->count(),
                    'users_count' => (int) ($userCounts[$role->id] ?? 0),
                    'modules_count' => $modules->count(),
                ];
            });

        return response()->json($roles);
    }

    public function permissions(Request $request): JsonResponse
    {
        // The main-cost permissions are the owner's private financial layer —
        // they must not even be visible in the role grid unless the caller
        // is the Platform Owner.
        $q = Permission::query();
        if (! $request->user()?->isPlatformOwner()) {
            $q->whereNotIn('name', ['main-cost', 'main-cost-edit']);
        }

        return response()->json($q->get(['id', 'name']));
    }

    /** Only the Platform Owner may grant or revoke the main-cost permissions. */
    private function sanitizePermissions(Request $request, array $permissions, ?Role $role = null): array
    {
        if ($request->user()?->isPlatformOwner()) {
            return $permissions;
        }

        $guarded = ['main-cost', 'main-cost-edit'];
        $permissions = array_values(array_diff($permissions, $guarded));
        foreach ($guarded as $perm) {
            if ($role && $role->hasPermissionTo($perm)) {
                $permissions[] = $perm; // keep an owner-granted permission intact
            }
        }

        return $permissions;
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'unique:roles,name'],
            'permissions' => ['array'],
        ]);

        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
        $role->syncPermissions($this->sanitizePermissions($request, $data['permissions'] ?? []));

        ActivityLog::log('created', 'Role', "Created role \"{$role->name}\"");

        return response()->json($role->load('permissions:id,name'), 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', Rule::unique('roles', 'name')->ignore($role->id)],
            'permissions' => ['array'],
        ]);

        if (isset($data['name'])) {
            $role->update(['name' => $data['name']]);
        }
        if (array_key_exists('permissions', $data)) {
            $role->syncPermissions($this->sanitizePermissions($request, $data['permissions'], $role));
        }

        ActivityLog::log('updated', 'Role', "Updated role \"{$role->name}\"");

        return response()->json($role->load('permissions:id,name'));
    }

    public function destroy(Role $role): JsonResponse
    {
        $name = $role->name;
        $role->delete();

        ActivityLog::log('deleted', 'Role', "Deleted role \"{$name}\"");

        return response()->json(['message' => 'Deleted.']);
    }
}
