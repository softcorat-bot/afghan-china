<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recycle bin over every soft-deleting module: browse what was deleted,
 * restore it, or destroy it forever. Company-scoped throughout; models
 * without a CompanyScope global scope are filtered manually.
 *
 * Reserved to the Super Admin (and the Platform Owner) — restore and
 * permanent destruction are not delegated through the role grid.
 */
class TrashController extends Controller
{
    private function authorizeSuperAdmin(Request $request): void
    {
        $user = $request->user();
        $allowed = $user && (
            (bool) $user->is_super_admin
            || $user->hasRole('Super Admin')
            || $user->isPlatformOwner()
        );

        abort_unless($allowed, 403, 'Reserved to the Super Admin.');
    }

    /** type => [model, label column(s), icon, module name for the activity log] */
    protected array $types = [
        'products' => [Product::class, 'Product', 'inventory_2'],
        'categories' => [ProductCategory::class, 'ProductCategory', 'category'],
        'customers' => [Customer::class, 'Customer', 'groups'],
        'suppliers' => [Supplier::class, 'Supplier', 'local_shipping'],
        'sales' => [Sale::class, 'Sale', 'sell'],
        'purchases' => [Purchase::class, 'Purchase', 'shopping_cart'],
        'users' => [User::class, 'User', 'manage_accounts'],
        'branches' => [Branch::class, 'Branch', 'store'],
        'currencies' => [Currency::class, 'Currency', 'attach_money'],
    ];

    private function query(string $type)
    {
        abort_unless(isset($this->types[$type]), 404, 'Unknown trash type.');
        [$model] = $this->types[$type];

        // onlyTrashed() drops just the soft-delete scope; the CompanyScope
        // stays for tenant models. Users/branches carry no global company
        // scope, so pin them to the current company explicitly.
        $q = $model::withoutGlobalScopes()->onlyTrashed()->where('company_id', Tenant::id());

        return $q;
    }

    private function label($row, string $type): array
    {
        return match ($type) {
            'products' => [$row->name, $row->sku ?: $row->barcode],
            'categories' => [$row->name, null],
            'customers' => [$row->name, $row->phone],
            'suppliers' => [$row->name, $row->phone],
            'sales' => [$row->invoice_no, number_format((float) $row->total, 2).' AFN'],
            'purchases' => [$row->reference ?? ('#'.$row->id), number_format((float) $row->total, 2).' AFN'],
            'users' => [$row->name, $row->email],
            'branches' => [$row->name, null],
            'currencies' => [$row->code, $row->name],
        };
    }

    public function counts(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $out = [];
        foreach (array_keys($this->types) as $type) {
            $out[$type] = $this->query($type)->count();
        }

        return response()->json($out);
    }

    public function index(Request $request, string $type): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $rows = $this->query($type)->orderByDesc('deleted_at')->limit(300)->get();

        return response()->json($rows->map(function ($row) use ($type) {
            [$label, $sub] = $this->label($row, $type);

            return [
                'id' => $row->id,
                'label' => $label,
                'sub' => $sub,
                'deleted_at' => optional($row->deleted_at)->toDateTimeString(),
            ];
        }));
    }

    public function restore(Request $request, string $type, int $id): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $row = $this->query($type)->where('id', $id)->firstOrFail();
        $row->restore();

        [$label] = $this->label($row, $type);
        ActivityLog::log('restored', $this->types[$type][1], "Restored {$this->types[$type][1]} \"{$label}\" from trash");

        return response()->json(['message' => 'Restored.']);
    }

    public function destroy(Request $request, string $type, int $id): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $row = $this->query($type)->where('id', $id)->firstOrFail();
        [$label] = $this->label($row, $type);

        try {
            $row->forceDelete();
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Cannot permanently delete — other records still reference it.'], 422);
        }

        ActivityLog::log('deleted', $this->types[$type][1], "Permanently deleted {$this->types[$type][1]} \"{$label}\"");

        return response()->json(['message' => 'Permanently deleted.']);
    }
}
