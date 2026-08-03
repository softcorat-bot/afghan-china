<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One lookup behind every scanner in the ERP.
 *
 * Whatever the scanner reads — the manufacturer's EAN, a supplier label, the
 * SKU, an internal code, or one of the free-form extra codes — resolves through
 * here, so a barcode that works at the POS works identically on Purchases,
 * Transfers, Returns and anywhere else products are listed.
 *
 * The answer is deliberately shaped as a verdict rather than a bare row:
 * `one` (act on it), `many` (let the human choose), `none` (say so plainly).
 */
class ProductLookupController extends Controller
{
    /** Exact-match fields, in the order a scanner is most likely to hit them. */
    private const CODE_FIELDS = ['barcode', 'barcode2', 'internal_code', 'sku'];

    public function scan(Request $request): JsonResponse
    {
        $code = trim((string) $request->query('code', $request->query('barcode', '')));

        if ($code === '') {
            return response()->json(['status' => 'none', 'code' => '', 'products' => []]);
        }

        // Only sellable stock unless the caller says otherwise: a Purchase Order
        // legitimately needs to find a draft or archived product.
        $includeInactive = $request->boolean('all');

        $matches = $this->query($includeInactive)
            ->where(function ($w) use ($code) {
                foreach (self::CODE_FIELDS as $field) {
                    if (\App\Support\Schema::has('products', $field)) {
                        $w->orWhere($field, $code);
                    }
                }
                // Future-proof slot: any additional code carried on the product.
                if (\App\Support\Schema::has('products', 'extra_codes')) {
                    $w->orWhere('extra_codes', 'like', '%"'.$code.'"%');
                }
            })
            ->limit(25)
            ->get();

        // Nothing matched a code exactly — fall back to a name/partial search so
        // a half-read barcode or a typed fragment still gets the cashier
        // somewhere useful instead of a dead end.
        $fuzzy = false;
        if ($matches->isEmpty() && mb_strlen($code) >= 2) {
            $like = '%'.$code.'%';
            $matches = $this->query($includeInactive)
                ->where(function ($w) use ($like) {
                    $w->where('name', 'like', $like)
                        ->orWhere('name_fa', 'like', $like)
                        ->orWhere('sku', 'like', $like)
                        ->orWhere('barcode', 'like', $like);
                })
                ->limit(25)
                ->get();
            $fuzzy = $matches->isNotEmpty();
        }

        return response()->json([
            'status' => $matches->isEmpty() ? 'none' : ($matches->count() === 1 ? 'one' : 'many'),
            'code' => $code,
            'fuzzy' => $fuzzy,
            'count' => $matches->count(),
            'products' => $matches->values(),
        ]);
    }

    private function query(bool $includeInactive)
    {
        $q = Product::query()->select([
            'id', 'name', 'name_fa', 'sku', 'barcode', 'category_id', 'brand', 'unit',
            'sale_price', 'wholesale_price', 'compare_at_price', 'tax_rate',
            'track_inventory', 'stock_qty', 'warehouse_qty', 'min_stock', 'status', 'active', 'image',
        ]);

        foreach (['barcode2', 'internal_code'] as $extra) {
            if (\App\Support\Schema::has('products', $extra)) {
                $q->addSelect($extra);
            }
        }

        if (! $includeInactive) {
            $q->where('status', 'active')->where('active', true);
        }

        return $q->with('category:id,name');
    }
}
