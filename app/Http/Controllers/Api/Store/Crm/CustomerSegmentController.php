<?php

namespace App\Http\Controllers\Api\Store\Crm;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Customer;
use App\Models\CustomerSegment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerSegmentController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $segments = CustomerSegment::where('is_active', true)->orderBy('name')->get();
        return $this->successResponse(['segments' => $segments]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->can('manage-customers')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'rules'       => 'required|array|min:1',
            'is_active'   => 'sometimes|boolean',
        ]);

        $validated['created_by'] = auth()->id();
        $segment = CustomerSegment::create($validated);

        // Update cached count
        $segment->update(['customer_count_cached' => $segment->applyRules()->count()]);

        return $this->successResponse(['segment' => $segment->fresh()], 'Segment created.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('manage-customers')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $segment = CustomerSegment::findOrFail($id);
        $validated = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'rules'       => 'sometimes|array|min:1',
            'is_active'   => 'sometimes|boolean',
        ]);

        $segment->update($validated);

        if (array_key_exists('rules', $validated)) {
            $segment->update(['customer_count_cached' => $segment->applyRules()->count()]);
        }

        return $this->successResponse(['segment' => $segment->fresh()], 'Segment updated.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('manage-customers')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        CustomerSegment::findOrFail($id)->delete();
        return $this->successResponse(null, 'Segment deleted.');
    }

    public function preview(int $id): JsonResponse
    {
        $segment = CustomerSegment::findOrFail($id);
        $query   = $segment->applyRules();
        $count   = $query->count();
        $sample  = $query->limit(10)->get(['id', 'name', 'code', 'phone', 'email']);

        $segment->update(['customer_count_cached' => $count]);

        return $this->successResponse([
            'count'  => $count,
            'sample' => $sample,
        ]);
    }
}
