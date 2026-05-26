<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $query = Store::with(['activeSubscription.plan'])
            ->withCount(['users', 'branches']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $stores = $query->latest()->paginate($request->input('per_page', 15));

        return $this->paginatedResponse($stores);
    }

    public function show(int $id): JsonResponse
    {
        $store = Store::with([
            'users:id,name,email,store_id,is_active',
            'branches',
            'activeSubscription.plan',
            'subscriptions.plan',
        ])
            ->withCount(['users', 'branches'])
            ->findOrFail($id);

        return $this->successResponse($store);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,active,suspended,expired',
            'reason' => 'nullable|string',
        ]);

        $store = Store::findOrFail($id);
        $store->update([
            'status' => $validated['status'],
            'is_active' => $validated['status'] === 'active',
        ]);

        return $this->successResponse($store, 'Store status updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $store = Store::findOrFail($id);
        $store->delete();

        return $this->successResponse(null, 'Store deleted');
    }

    /**
     * Login as the store owner (impersonation) — returns a token for that user.
     */
    public function impersonate(int $id): JsonResponse
    {
        $store = Store::with('users')->findOrFail($id);

        if (! $store->database()->manager()->databaseExists($store->database()->getName())) {
            return $this->errorResponse('Tenant database is not available for this store.', 500);
        }

        $owner = $store->users()->whereHas('roles', fn ($q) => $q->where('name', 'store-owner'))->first();

        if (!$owner) {
            return $this->errorResponse('Store owner not found.', 404);
        }

        $token = $owner->createToken('impersonation_' . now()->timestamp)->plainTextToken;

        return $this->successResponse([
            'token' => $token,
            'token_type' => 'Bearer',
            'impersonating' => [
                'user_id' => $owner->id,
                'user_name' => $owner->name,
                'store_id' => $store->id,
                'store_name' => $store->name,
            ],
        ], 'Impersonation token generated');
    }
}
