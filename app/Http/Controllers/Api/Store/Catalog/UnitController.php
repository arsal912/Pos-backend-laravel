<?php

namespace App\Http\Controllers\Api\Store\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $units = Unit::orderBy('name')->get();
        return $this->successResponse(['units' => $units]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->can('manage-settings')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'name'       => 'required|string|max:100',
            'short_code' => 'required|string|max:20',
            'is_decimal' => 'sometimes|boolean',
        ]);

        $unit = Unit::create($validated);

        return $this->successResponse(['unit' => $unit], 'Unit created.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('manage-settings')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $unit = Unit::findOrFail($id);
        $validated = $request->validate([
            'name'       => 'sometimes|string|max:100',
            'short_code' => 'sometimes|string|max:20',
            'is_decimal' => 'sometimes|boolean',
        ]);

        $unit->update($validated);

        return $this->successResponse(['unit' => $unit->fresh()], 'Unit updated.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('manage-settings')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $unit = Unit::withCount('products')->findOrFail($id);

        if ($unit->products_count > 0) {
            return $this->errorResponse('Cannot delete a unit in use by products.', 422);
        }

        $unit->delete();

        return $this->successResponse(null, 'Unit deleted.');
    }
}
