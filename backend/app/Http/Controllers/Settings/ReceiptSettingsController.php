<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The receipt layout. Everything a customer is handed — which lines print,
 * what the header and footer say, how wide the paper is — is stored per
 * company and edited in the app, so the owner never needs a developer to
 * change a phone number on the receipt.
 */
class ReceiptSettingsController extends Controller
{
    /** The shape of the settings, and what they mean when unset. */
    public const DEFAULTS = [
        'paper' => '80mm',            // 58mm | 80mm
        'font_size' => 12,            // px on the printed sheet
        'header_name' => '',          // blank = the company's own name
        'header_name_fa' => '',
        'tagline' => '',
        'address' => '',
        'phone' => '',
        'show_logo' => true,
        'show_cashier' => true,
        'show_customer' => true,
        'show_counter' => true,
        'show_unit_price' => true,
        'show_tax_line' => true,
        'show_discount_line' => true,
        'show_payment_lines' => true,
        'show_barcode' => true,
        'show_loyalty' => true,       // the badge/points the customer holds
        'show_branch' => true,        // which shop rang it
        'show_item_count' => true,    // "3 items · 7 units" — a quick check against the bag
        'show_saving' => true,        // what the customer saved against compare-at prices
        // Print the bill the moment a sale completes, with no button to press.
        // The browser still shows its print dialog unless Chrome is started
        // with --kiosk-printing; see docs/PROGRESS.md.
        'auto_print' => true,
        'footer' => 'Thank you for shopping with us',
        'footer_fa' => 'از خرید شما سپاسگزاریم',
        'note' => '',                 // returns policy, opening hours, …
    ];

    public function show(): JsonResponse
    {
        $company = Company::findOrFail(Tenant::id());

        return response()->json([
            'settings' => $this->merged($company),
            'defaults' => self::DEFAULTS,
            'company' => $company->only(['id', 'name_en', 'name_fa', 'address', 'phone', 'logo']),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        // Admin and the VIP seat only — the receipt carries the shop's name to
        // every customer, so a cashier cannot rewrite it. Gated here rather
        // than in middleware, matching the rest of the app.
        $user = $request->user();
        abort_unless(
            $user->isPlatformOwner()
                || $user->hasRole('Super Admin')
                || $user->hasRole('VIP')
                || $user->can('theme-edit'),
            403,
            'Only an administrator or the VIP seat can change the receipt layout.'
        );

        $data = $request->validate([
            'paper' => ['nullable', 'in:58mm,80mm'],
            'font_size' => ['nullable', 'integer', 'min:9', 'max:18'],
            'header_name' => ['nullable', 'string', 'max:120'],
            'header_name_fa' => ['nullable', 'string', 'max:120'],
            'tagline' => ['nullable', 'string', 'max:160'],
            'address' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:80'],
            'show_logo' => ['nullable', 'boolean'],
            'show_branch' => ['nullable', 'boolean'],
            'show_item_count' => ['nullable', 'boolean'],
            'show_saving' => ['nullable', 'boolean'],
            'auto_print' => ['nullable', 'boolean'],
            'show_cashier' => ['nullable', 'boolean'],
            'show_customer' => ['nullable', 'boolean'],
            'show_counter' => ['nullable', 'boolean'],
            'show_unit_price' => ['nullable', 'boolean'],
            'show_tax_line' => ['nullable', 'boolean'],
            'show_discount_line' => ['nullable', 'boolean'],
            'show_payment_lines' => ['nullable', 'boolean'],
            'show_barcode' => ['nullable', 'boolean'],
            'show_loyalty' => ['nullable', 'boolean'],
            'footer' => ['nullable', 'string', 'max:200'],
            'footer_fa' => ['nullable', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $company = Company::findOrFail(Tenant::id());
        // Only keys we know about are stored, so a stray field can never end up
        // in the layout and no key is silently dropped from an older save.
        $settings = array_merge($this->merged($company), array_intersect_key($data, self::DEFAULTS));
        $company->forceFill(['receipt_settings' => $settings])->save();

        ActivityLog::log('updated', 'Setting', 'Changed the receipt layout');

        return response()->json(['settings' => $settings]);
    }

    /** Stored values on top of the defaults, so a partial save is safe. */
    private function merged(Company $company): array
    {
        $stored = $company->receipt_settings;
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }

        return array_merge(self::DEFAULTS, is_array($stored) ? $stored : []);
    }
}
