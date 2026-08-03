<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ProductCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $cats = ProductCategory::withCount('products')->orderBy('sort')->orderBy('name')->get();

        return response()->json($cats);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $cat = ProductCategory::create($data);

        ActivityLog::log('created', 'ProductCategory', "Added category \"{$cat->name}\"");

        return response()->json($cat, 201);
    }

    public function update(Request $request, ProductCategory $category): JsonResponse
    {
        $category->update($this->validated($request));

        ActivityLog::log('updated', 'ProductCategory', "Updated category \"{$category->name}\"");

        return response()->json($category);
    }

    public function destroy(ProductCategory $category): JsonResponse
    {
        $name = $category->name;
        $category->delete();

        ActivityLog::log('deleted', 'ProductCategory', "Deleted category \"{$name}\"");

        return response()->json(['message' => 'Deleted.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'name_fa' => ['nullable', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'image' => ['nullable', 'string'],
            'active' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'integer'],
        ]);
    }
}
