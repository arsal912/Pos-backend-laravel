<?php

namespace App\Http\Controllers\Api\Store\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BrandController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $brands = Brand::withCount('products')->orderBy('name')->get();
        return $this->successResponse(['brands' => $brands]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->can('create-products')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'name'      => 'required|string|max:255',
            'is_active' => 'sometimes|boolean',
        ]);

        $validated['slug'] = Str::slug($validated['name']);
        $brand = Brand::create($validated);

        return $this->successResponse(['brand' => $brand], 'Brand created.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('edit-products')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $brand = Brand::findOrFail($id);
        $validated = $request->validate([
            'name'      => 'sometimes|string|max:255',
            'is_active' => 'sometimes|boolean',
        ]);

        if (isset($validated['name'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $brand->update($validated);

        return $this->successResponse(['brand' => $brand->fresh()], 'Brand updated.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('delete-products')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $brand = Brand::withCount('products')->findOrFail($id);

        if ($brand->products_count > 0) {
            return $this->errorResponse('Cannot delete a brand that has products.', 422);
        }

        $brand->delete();

        return $this->successResponse(null, 'Brand deleted.');
    }
}
