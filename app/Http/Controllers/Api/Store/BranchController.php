<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Branch;
use App\Models\InventoryItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $branches = Branch::with('warehouses:id,name,code,type,is_active')
            ->withCount('users')
            ->orderByDesc('is_main')
            ->orderBy('name')
            ->get();

        $branches->each(function (Branch $branch) {
            $inv = InventoryItem::where('branch_id', $branch->id);
            $branch->product_count = $inv->distinct('product_id')->count('product_id');
            $branch->total_stock   = (float) $inv->sum('quantity');
        });

        return $this->successResponse(['branches' => $branches]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->can('manage-branches')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'name'          => 'required|string|max:100',
            'code'          => 'nullable|string|max:20',
            'address'       => 'nullable|string|max:500',
            'phone'         => 'nullable|string|max:30',
            'email'         => 'nullable|email|max:150',
            'is_main'       => 'boolean',
            'warehouse_ids' => 'nullable|array',
            'warehouse_ids.*' => 'integer',
        ]);

        $branch = Branch::create([
            ...collect($validated)->except('warehouse_ids')->toArray(),
            'store_id'  => app('current_store_id'),
            'is_active' => true,
        ]);

        if (! empty($validated['warehouse_ids'])) {
            $branch->warehouses()->sync($validated['warehouse_ids']);
        }

        return $this->successResponse(
            ['branch' => $branch->load('warehouses:id,name,code,type,is_active')],
            'Branch created.',
            201
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('manage-branches')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $branch = Branch::findOrFail($id);

        $validated = $request->validate([
            'name'          => 'sometimes|string|max:100',
            'code'          => 'nullable|string|max:20',
            'address'       => 'nullable|string|max:500',
            'phone'         => 'nullable|string|max:30',
            'email'         => 'nullable|email|max:150',
            'is_active'     => 'boolean',
            'is_main'       => 'boolean',
            'warehouse_ids' => 'nullable|array',
            'warehouse_ids.*' => 'integer',
        ]);

        $branch->fill(collect($validated)->except('warehouse_ids')->toArray())->save();

        if ($request->has('warehouse_ids')) {
            $branch->warehouses()->sync($validated['warehouse_ids'] ?? []);
        }

        return $this->successResponse(
            ['branch' => $branch->fresh()->load('warehouses:id,name,code,type,is_active')],
            'Branch updated.'
        );
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('manage-branches')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $branch = Branch::findOrFail($id);

        if ($branch->is_main) {
            return $this->errorResponse('Cannot delete the main branch.', 422);
        }

        $hasStock = InventoryItem::where('branch_id', $id)->where('quantity', '>', 0)->exists();
        if ($hasStock) {
            return $this->errorResponse('This branch has stock on hand. Transfer it out before deleting.', 422);
        }

        $branch->warehouses()->detach(); // clean up pivot
        $branch->delete();

        return $this->successResponse([], 'Branch deleted.');
    }
}
