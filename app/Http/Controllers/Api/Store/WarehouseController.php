<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Warehouse;
use App\Models\InventoryItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $warehouses = Warehouse::with('branches:id,name,code,is_main')
            ->orderBy('name')
            ->get();

        $warehouses->each(function (Warehouse $wh) {
            $inv = InventoryItem::where('warehouse_id', $wh->id);
            $wh->product_count = $inv->distinct('product_id')->count('product_id');
            $wh->total_stock   = (float) $inv->sum('quantity');
        });

        return $this->successResponse(['warehouses' => $warehouses]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->can('manage-branches')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'name'       => 'required|string|max:100',
            'code'       => 'nullable|string|max:20',
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => 'integer',
            'type'       => 'sometimes|in:storage,distribution,cold_storage,retail',
            'address'    => 'nullable|string|max:500',
            'phone'      => 'nullable|string|max:30',
            'manager'    => 'nullable|string|max:100',
        ]);

        $warehouse = Warehouse::create([
            'store_id'  => app('current_store_id'),
            'name'      => $validated['name'],
            'code'      => $validated['code'] ?? null,
            'type'      => $validated['type'] ?? 'storage',
            'address'   => $validated['address'] ?? null,
            'phone'     => $validated['phone'] ?? null,
            'manager'   => $validated['manager'] ?? null,
            'is_active' => true,
        ]);

        if (! empty($validated['branch_ids'])) {
            $warehouse->branches()->sync($validated['branch_ids']);
        }

        return $this->successResponse(
            ['warehouse' => $warehouse->load('branches:id,name,code,is_main')],
            'Warehouse created.',
            201
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('manage-branches')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $warehouse = Warehouse::findOrFail($id);

        $validated = $request->validate([
            'name'       => 'sometimes|string|max:100',
            'code'       => 'nullable|string|max:20',
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => 'integer',
            'type'       => 'nullable|in:storage,distribution,cold_storage,retail',
            'address'    => 'nullable|string|max:500',
            'phone'      => 'nullable|string|max:30',
            'manager'    => 'nullable|string|max:100',
            'is_active'  => 'boolean',
        ]);

        $warehouse->fill(collect($validated)->except('branch_ids')->toArray())->save();

        // Sync pivot if branch_ids was included in the request (even if empty)
        if ($request->has('branch_ids')) {
            $warehouse->branches()->sync($validated['branch_ids'] ?? []);
        }

        return $this->successResponse(
            ['warehouse' => $warehouse->fresh()->load('branches:id,name,code,is_main')],
            'Warehouse updated.'
        );
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('manage-branches')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $warehouse = Warehouse::findOrFail($id);

        $hasStock = InventoryItem::where('warehouse_id', $id)
            ->where('quantity', '>', 0)
            ->exists();

        if ($hasStock) {
            return $this->errorResponse(
                'This warehouse has stock on hand. Transfer it out before deleting.',
                422
            );
        }

        $warehouse->branches()->detach(); // clean up pivot
        $warehouse->delete();

        return $this->successResponse([], 'Warehouse deleted.');
    }
}
