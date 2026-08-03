<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Counter PIN sign-in — the register terminal door.
 *
 * The cashier taps their tile and types a 4–6 digit PIN instead of an
 * email + password. Only staff who (a) hold a PIN, (b) are active and
 * (c) can actually sell at the POS are ever listed, so the terminal
 * screen never exposes back-office accounts. PINs are hashed; the routes
 * are rate-limited; an owner can disable the terminal entirely with
 * POS_PIN_LOGIN=false in .env.
 */
class PinController extends Controller
{
    private function enabled(): bool
    {
        return (bool) config('platform.pin_login', true);
    }

    /** Tiles for the terminal: who can sign in here. */
    public function staff(): JsonResponse
    {
        abort_unless($this->enabled(), 404);

        $users = User::whereNotNull('pin')->where('active', true)
            ->orderBy('name')->get(['id', 'name'])
            ->filter(fn (User $u) => $u->can('pos-sell'))
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'initials' => $this->initials($u->name),
            ])->values();

        return response()->json($users);
    }

    /** Exchange a tile + PIN for an API token. */
    public function login(Request $request): JsonResponse
    {
        abort_unless($this->enabled(), 404);

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'pin' => ['required', 'string', 'min:4', 'max:8'],
        ]);

        $user = User::where('id', $data['user_id'])->where('active', true)->first();

        if (! $user || ! $user->pin || ! Hash::check($data['pin'], $user->pin) || ! $user->can('pos-sell')) {
            throw ValidationException::withMessages(['pin' => ['Wrong PIN.']]);
        }

        $token = $user->createToken('pos-terminal')->plainTextToken;

        return (new AuthController)->sessionPayload($user, $token);
    }

    /** Set / clear a staff PIN (admin side, or your own). */
    public function set(Request $request, User $user): JsonResponse
    {
        $me = $request->user();
        $allowed = $me->id === $user->id
            || (bool) $me->is_super_admin || $me->hasRole('Super Admin')
            || $me->isPlatformOwner() || $me->can('user-edit');
        abort_unless($allowed, 403, 'Not allowed to change this PIN.');

        $data = $request->validate([
            'pin' => ['nullable', 'string', 'min:4', 'max:8', 'regex:/^[0-9]+$/'],
        ]);

        $user->forceFill([
            'pin' => $data['pin'] ? Hash::make($data['pin']) : null,
            'pin_set_at' => $data['pin'] ? now() : null,
        ])->save();

        ActivityLog::log('updated', 'User', ($data['pin'] ? 'Set' : 'Cleared')." counter PIN for \"{$user->name}\"");

        return response()->json(['id' => $user->id, 'has_pin' => $data['pin'] !== null]);
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));
        $first = mb_substr($parts[0] ?? '?', 0, 1);
        $second = isset($parts[1]) ? mb_substr($parts[1], 0, 1) : '';

        return mb_strtoupper($first.$second);
    }
}
