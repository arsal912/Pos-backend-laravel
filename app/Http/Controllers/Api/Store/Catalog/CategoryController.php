<?php

namespace App\Http\Controllers\Api\Store\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $categories = Category::withCount('products')
            ->with('children')
            ->roots()
            ->orderBy('sort_order')
            ->get();

        return $this->successResponse(['categories' => $categories]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->can('create-products')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'parent_id'   => 'nullable|exists:categories,id',
            'description' => 'nullable|string',
            'is_active'   => 'sometimes|boolean',
            'sort_order'  => 'sometimes|integer',
        ]);

        $validated['slug'] = $this->uniqueSlug($validated['name']);
        $category = Category::create($validated);

        return $this->successResponse(['category' => $category], 'Category created.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('edit-products')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $category = Category::findOrFail($id);
        $validated = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'parent_id'   => 'nullable|exists:categories,id',
            'description' => 'nullable|string',
            'is_active'   => 'sometimes|boolean',
            'sort_order'  => 'sometimes|integer',
        ]);

        if (isset($validated['name']) && $validated['name'] !== $category->name) {
            $validated['slug'] = $this->uniqueSlug($validated['name'], $category->id);
        }

        $category->update($validated);

        return $this->successResponse(['category' => $category->fresh('children')], 'Category updated.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('delete-products')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $category = Category::withCount('products')->findOrFail($id);

        if ($category->products_count > 0) {
            return $this->errorResponse('Cannot delete a category that has products.', 422);
        }

        $category->delete();

        return $this->successResponse(null, 'Category deleted.');
    }

    private function uniqueSlug(string $name, ?int $excludeId = null): string
    {
        $slug  = Str::slug($name);
        $query = Category::where('slug', 'like', $slug . '%');
        if ($excludeId) $query->where('id', '!=', $excludeId);
        $count = $query->count();
        return $count ? $slug . '-' . ($count + 1) : $slug;
    }
}
