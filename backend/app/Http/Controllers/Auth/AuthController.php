<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        // Token-only auth (no session): validate credentials manually so this
        // works under Electron file:// where there is no stateful session.
        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        return $this->userPayload($user, $user->createToken('desktop')->plainTextToken);
    }

    public function logout(Request $request): JsonResponse
    {
        // Revoke the API token used for this request (if any)
        $user = $request->user();
        if ($user) {
            $token = $user->currentAccessToken();
            if ($token) {
                $token->delete();
            }
        }

        return response()->json(['message' => 'Logged out.']);
    }

    public function user(Request $request): JsonResponse
    {
        return $this->userPayload($request->user());
    }

    public function check(Request $request): JsonResponse
    {
        return response()->json(['auth' => $request->user() !== null]);
    }

    /** Same session payload, reusable by the PIN terminal. */
    public function sessionPayload(User $user, ?string $token = null): JsonResponse
    {
        return $this->userPayload($user, $token);
    }

    protected function userPayload(?User $authUser = null, ?string $token = null): JsonResponse
    {
        $user = ($authUser ?? Auth::user())->load('company', 'companies', 'branches:id,name');

        $seesAll = $user->seesAllBranches();
        $branchOptions = $seesAll
            ? \App\Models\Branch::query()->where('active', true)->orderBy('name')->get(['id', 'name'])
            : $user->branches()->get(['branches.id', 'branches.name']);

        $payload = [
            'user' => $user,
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'roles' => $user->getRoleNames(),
            'branches' => $branchOptions,
            'current_branch' => $user->current_branch,
            'sees_all_branches' => $seesAll,
            'is_platform_owner' => $user->isPlatformOwner(),
        ];

        if ($token !== null) {
            $payload['token'] = $token;
        }

        return response()->json($payload);
    }
}
