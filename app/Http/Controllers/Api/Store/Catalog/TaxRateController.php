<?php

namespace App\Http\Controllers\Api\Store\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\TaxRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaxRateController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $rates = TaxRate::orderBy('name')->get();
        return $this->successResponse(['tax_rates' => $rates]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->can('manage-settings')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'name'         => 'required|string|max:100',
            'rate'         => 'required|numeric|min:0|max:100',
            'is_inclusive' => 'sometimes|boolean',
            'is_active'    => 'sometimes|boolean',
        ]);

        $taxRate = TaxRate::create($validated);

        return $this->successResponse(['tax_rate' => $taxRate], 'Tax rate created.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('manage-settings')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $taxRate = TaxRate::findOrFail($id);
        $validated = $request->validate([
            'name'         => 'sometimes|string|max:100',
            'rate'         => 'sometimes|numeric|min:0|max:100',
            'is_inclusive' => 'sometimes|boolean',
            'is_active'    => 'sometimes|boolean',
        ]);

        $taxRate->update($validated);

        return $this->successResponse(['tax_rate' => $taxRate->fresh()], 'Tax rate updated.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('manage-settings')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $taxRate = TaxRate::withCount('products')->findOrFail($id);

        if ($taxRate->products_count > 0) {
            return $this->errorResponse('Cannot delete a tax rate in use by products.', 422);
        }

        $taxRate->delete();

        return $this->successResponse(null, 'Tax rate deleted.');
    }
}
