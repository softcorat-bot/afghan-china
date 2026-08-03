<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::with(['roles', 'branches:id,name'])
            ->where('company_id', $request->user()->current_company)
            ->get();

        // The VIP seat and the Platform Owner are invisible to everyone else —
        // a Super Admin must not even learn the email behind them.
        if (! $this->seesConfidentialSeats($request->user())) {
            $users = $users->reject(
                fn ($u) => $u->isPlatformOwner() || $u->roles->contains('name', 'VIP')
            )->values();
        }

        return response()->json($users);
    }

    private function seesConfidentialSeats(User $viewer): bool
    {
        return $viewer->isPlatformOwner() || $viewer->can('main-cost');
    }

    /** 404 (not 403 — existence itself is the secret) for hidden seats. */
    private function guardConfidential(Request $request, User $target): void
    {
        if ($this->seesConfidentialSeats($request->user())) {
            return;
        }
        if ($target->isPlatformOwner() || $target->hasRole('VIP')) {
            abort(404);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'type' => ['nullable', 'string'],
            'roles' => ['array'],
            'branch_ids' => ['array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'type' => $data['type'] ?? null,
            'company_id' => $request->user()->current_company,
            'current_company' => $request->user()->current_company,
        ]);

        if ($request->user()->current_company) {
            $user->companies()->syncWithoutDetaching([$request->user()->current_company]);
        }
        $user->syncRoles($data['roles'] ?? []);
        if (array_key_exists('branch_ids', $data)) {
            $user->branches()->sync($data['branch_ids']);
        }

        ActivityLog::log('created', 'User', "Created user \"{$user->name}\"");

        return response()->json($user->load(['roles', 'branches:id,name']), 201);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $this->guardConfidential($request, $user);

        return response()->json($user->load('roles'));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->guardConfidential($request, $user);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:6'],
            'type' => ['nullable', 'string'],
            'roles' => ['array'],
            'branch_ids' => ['array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
        ]);

        $user->fill(collect($data)->except(['password', 'roles', 'branch_ids'])->toArray());
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        if (array_key_exists('roles', $data)) {
            $user->syncRoles($data['roles']);
        }
        if (array_key_exists('branch_ids', $data)) {
            $user->branches()->sync($data['branch_ids']);
        }

        ActivityLog::log('updated', 'User', "Updated user \"{$user->name}\"");

        return response()->json($user->load(['roles', 'branches:id,name']));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->guardConfidential($request, $user);

        $name = $user->name;
        $user->delete();

        ActivityLog::log('deleted', 'User', "Deleted user \"{$name}\"");

        return response()->json(['message' => 'Deleted.']);
    }
}
