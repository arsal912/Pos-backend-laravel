<?php

namespace App\Http\Controllers\Api\Store\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->can('view-suppliers')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $query = Supplier::withCount('purchaseOrders');

        if ($search = $request->input('search')) {
            $query->where(fn ($q) =>
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('company', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
            );
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        return $this->paginatedResponse($query->orderBy('name')->paginate($request->input('per_page', 20)));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('view-suppliers')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        return $this->successResponse(['supplier' => Supplier::findOrFail($id)]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->can('manage-suppliers')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'company'         => 'nullable|string|max:255',
            'email'           => 'nullable|email',
            'phone'           => 'nullable|string|max:50',
            'address'         => 'nullable|string',
            'city'            => 'nullable|string|max:100',
            'country'         => 'nullable|string|max:100',
            'tax_number'      => 'nullable|string|max:100',
            'opening_balance' => 'sometimes|numeric',
            'is_active'       => 'sometimes|boolean',
            'notes'           => 'nullable|string',
        ]);

        $supplier = Supplier::create($validated);

        return $this->successResponse(['supplier' => $supplier], 'Supplier created.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('manage-suppliers')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $supplier = Supplier::findOrFail($id);
        $validated = $request->validate([
            'name'            => 'sometimes|string|max:255',
            'company'         => 'nullable|string|max:255',
            'email'           => 'nullable|email',
            'phone'           => 'nullable|string|max:50',
            'address'         => 'nullable|string',
            'city'            => 'nullable|string|max:100',
            'country'         => 'nullable|string|max:100',
            'tax_number'      => 'nullable|string|max:100',
            'opening_balance' => 'sometimes|numeric',
            'is_active'       => 'sometimes|boolean',
            'notes'           => 'nullable|string',
        ]);

        $supplier->update($validated);

        return $this->successResponse(['supplier' => $supplier->fresh()], 'Supplier updated.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('manage-suppliers')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        Supplier::findOrFail($id)->delete();

        return $this->successResponse(null, 'Supplier deleted.');
    }
}
