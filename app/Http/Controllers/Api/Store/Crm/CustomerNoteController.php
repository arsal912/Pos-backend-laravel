<?php

namespace App\Http\Controllers\Api\Store\Crm;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Customer;
use App\Models\CustomerNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerNoteController extends Controller
{
    use ApiResponse;

    public function index(Request $request, int $customerId): JsonResponse
    {
        if (! $request->user()->can('view-customers')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        Customer::findOrFail($customerId);

        $notes = CustomerNote::where('customer_id', $customerId)
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at')
            ->paginate($request->input('per_page', 20));

        return $this->paginatedResponse($notes);
    }

    public function store(Request $request, int $customerId): JsonResponse
    {
        if (! $request->user()->can('manage-customers')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'note'      => 'required|string',
            'is_pinned' => 'sometimes|boolean',
        ]);

        Customer::findOrFail($customerId);

        $note = CustomerNote::create([
            'customer_id' => $customerId,
            'note'        => $validated['note'],
            'is_pinned'   => $validated['is_pinned'] ?? false,
            'created_by'  => auth()->id(),
        ]);

        return $this->successResponse(['note' => $note], 'Note added.', 201);
    }

    public function destroy(Request $request, int $customerId, int $noteId): JsonResponse
    {
        if (! $request->user()->can('manage-customers')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        CustomerNote::where('customer_id', $customerId)->findOrFail($noteId)->delete();

        return $this->successResponse(null, 'Note deleted.');
    }
}
