<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * How the register behaves. Every cashier reads these; only an administrator
 * or the VIP seat may change them, because they govern how money is taken at
 * the counter.
 */
class PosSettingsController extends Controller
{
    /** The shape of the settings, and what they mean when unset. */
    public const DEFAULTS = [
        // Start the next sale on its own after a completed one.
        'auto_next' => true,
        'auto_next_seconds' => 8,
        // The "cash received" box on the receipt, with its one-touch notes.
        // There is exactly one of these in the register: the one-tap cash
        // button records the exact bill, and what the customer actually handed
        // over is worked out afterwards, here.
        'show_receipt_change' => true,
        // Add a walk-in customer without leaving the register.
        'show_quick_customer' => true,
        // Tap a line's quantity for a numeric pad.
        'show_qty_keypad' => true,
        // Ask before emptying a bill that has lines on it.
        'confirm_clear_bill' => true,
        // Start with the barcode field armed for scanning.
        'default_scan_mode' => false,
        // Product pictures in the grid (off is faster on a weak machine).
        'show_product_images' => true,
        // The stock number on each product tile.
        'show_stock_badges' => true,
        // Let a cashier sell an item that has run out.
        'allow_negative_stock' => false,
        // The banknotes the tender pad offers, largest last.
        'note_denominations' => '10,20,50,100,500,1000',
    ];

    public function show(): JsonResponse
    {
        $company = Company::findOrFail(Tenant::id());

        return response()->json([
            'settings' => $this->merged($company),
            'defaults' => self::DEFAULTS,
            'can_edit' => $this->mayEdit(request()->user()),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($this->mayEdit($request->user()), 403,
            'Only an administrator or the VIP seat can change the register settings.');

        $data = $request->validate([
            'auto_next' => ['nullable', 'boolean'],
            'auto_next_seconds' => ['nullable', 'integer', 'min:3', 'max:60'],
            'show_receipt_change' => ['nullable', 'boolean'],
            'show_quick_customer' => ['nullable', 'boolean'],
            'show_qty_keypad' => ['nullable', 'boolean'],
            'confirm_clear_bill' => ['nullable', 'boolean'],
            'default_scan_mode' => ['nullable', 'boolean'],
            'show_product_images' => ['nullable', 'boolean'],
            'show_stock_badges' => ['nullable', 'boolean'],
            'allow_negative_stock' => ['nullable', 'boolean'],
            // "10,20,50" — at least one positive whole number.
            'note_denominations' => ['nullable', 'string', 'regex:/^\s*\d+\s*(,\s*\d+\s*)*$/'],
        ]);

        $company = Company::findOrFail(Tenant::id());
        // Only keys we know about are stored, so a stray field can never end up
        // in the register's behaviour and no key is dropped from an older save.
        $settings = array_merge($this->merged($company), array_intersect_key($data, self::DEFAULTS));

        // Normalize the note list once, here, rather than in every consumer.
        if (isset($settings['note_denominations'])) {
            $notes = collect(explode(',', (string) $settings['note_denominations']))
                ->map(fn ($n) => (int) trim($n))->filter(fn ($n) => $n > 0)
                ->unique()->sort()->values();
            $settings['note_denominations'] = $notes->isEmpty()
                ? self::DEFAULTS['note_denominations']
                : $notes->implode(',');
        }

        $company->forceFill(['pos_settings' => $settings])->save();

        ActivityLog::log('updated', 'Setting', 'Changed the register settings');

        return response()->json(['settings' => $settings]);
    }

    private function mayEdit(?\App\Models\User $user): bool
    {
        return (bool) ($user && ($user->isPlatformOwner()
            || $user->hasRole('Super Admin')
            || $user->hasRole('VIP')
            || $user->can('theme-edit')));
    }

    /** Stored values on top of the defaults, so a partial save is safe. */
    private function merged(Company $company): array
    {
        $stored = $company->pos_settings;
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }

        return array_merge(self::DEFAULTS, is_array($stored) ? $stored : []);
    }
}
