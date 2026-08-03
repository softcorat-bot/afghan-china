<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Product::with('category:id,name')
            ->when($request->filled('search'), function ($qq) use ($request) {
                $s = '%'.$request->string('search').'%';
                $qq->where(fn ($w) => $w->where('name', 'like', $s)
                    ->orWhere('sku', 'like', $s)
                    ->orWhere('barcode', 'like', $s)
                    ->orWhere('brand', 'like', $s));
            })
            ->when($request->filled('category_id'), fn ($qq) => $qq->where('category_id', $request->integer('category_id')))
            ->when($request->filled('status'), fn ($qq) => $qq->where('status', $request->string('status')))
            ->when($request->boolean('low_stock'), fn ($qq) => $qq->whereColumn('stock_qty', '<=', 'min_stock')->where('track_inventory', true))
            ->orderBy('name');

        return response()->json($q->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $product = Product::create($data);

        ActivityLog::log('created', 'Product', "Added product \"{$product->name}\"");

        return response()->json($product->load('category:id,name'), 201);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json($product->load('category:id,name'));
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $product->update($this->validated($request, $product->id));

        ActivityLog::log('updated', 'Product', "Updated product \"{$product->name}\"");

        return response()->json($product->load('category:id,name'));
    }

    public function destroy(Product $product): JsonResponse
    {
        $name = $product->name;
        $product->delete();

        ActivityLog::log('deleted', 'Product', "Deleted product \"{$name}\"");

        return response()->json(['message' => 'Deleted.']);
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'name_fa' => ['nullable', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'brand' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,draft,archived'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'compare_at_price' => ['nullable', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'track_inventory' => ['nullable', 'boolean'],
            'stock_qty' => ['nullable', 'numeric'],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'image' => ['nullable', 'string'],
            'tags' => ['nullable', 'array'],
        ]);
    }
}
