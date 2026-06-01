<?php

namespace App\Http\Controllers\Api\Store\Crm;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Customer;
use App\Models\CustomerGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CustomerGroupController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $groups = CustomerGroup::withCount('customers')->active()->get();
        return $this->successResponse(['groups' => $groups]);
    }

    public function show(int $id): JsonResponse
    {
        $group = CustomerGroup::withCount('customers')->findOrFail($id);
        return $this->successResponse(['group' => $group]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->can('manage-customer-groups')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'name'                     => 'required|string|max:255',
            'description'              => 'nullable|string',
            'default_discount_percent' => 'nullable|numeric|min:0|max:100',
            'earns_loyalty_points'     => 'sometimes|boolean',
            'is_default'               => 'sometimes|boolean',
            'color'                    => 'sometimes|string|max:20',
            'is_active'                => 'sometimes|boolean',
            'sort_order'               => 'sometimes|integer',
        ]);

        $validated['slug'] = Str::slug($validated['name']);
        $group = CustomerGroup::create($validated);

        if ($group->is_default) {
            $group->setAsDefault();
        }

        return $this->successResponse(['group' => $group], 'Group created.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('manage-customer-groups')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $group = CustomerGroup::findOrFail($id);
        $validated = $request->validate([
            'name'                     => 'sometimes|string|max:255',
            'description'              => 'nullable|string',
            'default_discount_percent' => 'nullable|numeric|min:0|max:100',
            'earns_loyalty_points'     => 'sometimes|boolean',
            'is_default'               => 'sometimes|boolean',
            'color'                    => 'sometimes|string|max:20',
            'is_active'                => 'sometimes|boolean',
            'sort_order'               => 'sometimes|integer',
        ]);

        $group->update($validated);

        if ($group->is_default) {
            $group->setAsDefault();
        }

        return $this->successResponse(['group' => $group->fresh()], 'Group updated.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('manage-customer-groups')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $group = CustomerGroup::withCount('customers')->findOrFail($id);

        if ($group->customers_count > 0) {
            return $this->errorResponse('Cannot delete a group that has customers. Move them first.', 422);
        }

        $group->delete();
        return $this->successResponse(null, 'Group deleted.');
    }

    public function customers(Request $request, int $id): JsonResponse
    {
        CustomerGroup::findOrFail($id);

        $customers = Customer::where('customer_group_id', $id)
            ->when($request->input('search'), fn ($q, $s) => $q->search($s))
            ->paginate($request->input('per_page', 20));

        return $this->paginatedResponse($customers);
    }

    public function bulkAssign(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('manage-customer-groups')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'customer_ids'   => 'required|array',
            'customer_ids.*' => 'integer|exists:customers,id',
        ]);

        CustomerGroup::findOrFail($id);
        Customer::whereIn('id', $validated['customer_ids'])->update(['customer_group_id' => $id]);

        return $this->successResponse(null, count($validated['customer_ids']) . ' customers assigned to group.');
    }
}
