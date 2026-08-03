<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * VIP Access Security Controller
 * Restricts access to super admin users and their credentials
 * Regular admins cannot view or modify super admin accounts
 */
class VipAccessController extends Controller
{
    /**
     * Get list of users (filtered: regular admins see only non-super-admin users)
     */
    public function getUsers(Request $request): JsonResponse
    {
        $user = auth()->user();
        $query = User::where('company_id', Tenant::id());

        // If user is NOT a super admin, exclude super admin users
        if (!$user->is_super_admin) {
            $query->where('is_super_admin', false);
        }

        $users = $query->select([
            'id', 'name', 'email', 'phone', 'active',
            // Never return password hash or super_admin flag to non-super-admins
            $user->is_super_admin ? 'is_super_admin' : \DB::raw("NULL as is_super_admin"),
        ])->get();

        return response()->json($users);
    }

    /**
     * Get single user (with credential protection)
     */
    public function getUser(User $user): JsonResponse
    {
        abort_unless($user->company_id === Tenant::id(), 403);

        $authUser = auth()->user();

        // Protect super admin users from regular admins
        if ($user->is_super_admin && !$authUser->is_super_admin) {
            abort(403, 'You do not have permission to view VIP account details.');
        }

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'active' => $user->active,
            'is_super_admin' => $user->is_super_admin,
            // Never return password hash
        ]);
    }

    /**
     * Update user (with VIP protection)
     */
    public function updateUser(Request $request, User $user): JsonResponse
    {
        abort_unless($user->company_id === Tenant::id(), 403);

        $authUser = auth()->user();

        // Protect super admin users
        if ($user->is_super_admin && !$authUser->is_super_admin) {
            abort(403, 'You cannot modify VIP accounts.');
        }

        // Regular admins cannot promote anyone to super admin
        if ($request->filled('is_super_admin') && !$authUser->is_super_admin) {
            abort(403, 'You do not have permission to modify VIP status.');
        }

        $data = $request->validate([
            'name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'language' => 'nullable|in:en,fa,ps,zh',
            'active' => 'nullable|boolean',
            // Password requires special confirmation
            'password' => 'nullable|string|min:8|confirmed',
        ]);

        // Remove null values
        $data = array_filter($data, fn ($v) => $v !== null);

        if (isset($data['password'])) {
            $data['password'] = bcrypt($data['password']);
        }

        $user->update($data);

        return response()->json([
            'message' => 'User updated successfully',
            'user' => $user->only(['id', 'name', 'email', 'phone', 'active', 'is_super_admin']),
        ]);
    }

    /**
     * Delete user (with VIP protection)
     */
    public function deleteUser(User $user): JsonResponse
    {
        abort_unless($user->company_id === Tenant::id(), 403);

        $authUser = auth()->user();

        // Protect super admin users from deletion by regular admins
        if ($user->is_super_admin && !$authUser->is_super_admin) {
            abort(403, 'You cannot delete VIP accounts.');
        }

        // Prevent self-deletion
        if ($authUser->id === $user->id) {
            abort(403, 'You cannot delete your own account.');
        }

        $user->delete();

        return response()->json(['message' => 'User deleted successfully']);
    }

    /**
     * Check current user's VIP status
     */
    public function checkVipStatus(): JsonResponse
    {
        $user = auth()->user();

        return response()->json([
            'is_vip' => $user->is_super_admin,
            'role' => $user->is_super_admin ? 'super_admin' : 'admin',
            'can_manage_vip' => $user->is_super_admin,
            'can_view_vip_data' => $user->is_super_admin,
        ]);
    }

    /**
     * Set VIP password (super admin only, with confirmation)
     */
    public function setSuperAdminPassword(Request $request, User $user): JsonResponse
    {
        abort_unless(auth()->user()->is_super_admin, 403);
        abort_unless($user->is_super_admin, 403);
        abort_unless($user->company_id === Tenant::id(), 403);

        $data = $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        // Verify current password
        abort_unless(\Hash::check($data['current_password'], auth()->user()->password), 403, 'Current password is incorrect.');

        $user->update(['password' => bcrypt($data['new_password'])]);

        return response()->json(['message' => 'VIP password updated successfully']);
    }
}
