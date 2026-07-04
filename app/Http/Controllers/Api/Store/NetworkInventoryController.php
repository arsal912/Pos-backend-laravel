<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\InterStoreTransferRequest;
use App\Models\Store;
use App\Models\StoreInventorySnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NetworkInventoryController extends Controller
{
    use ApiResponse;

    // ── GET /store/network/inventory ─────────────────────────────────────────
    // Browse inventory across all OTHER active stores + their locations.
    public function index(Request $request): JsonResponse
    {
        $myStoreId = app('current_store_id');

        $query = StoreInventorySnapshot::query()
            ->where('store_id', '!=', $myStoreId)
            ->where('quantity', '>', 0)          // only show locations that actually have stock
            ->orderBy('store_name')
            ->orderBy('location_name')
            ->orderBy('product_name');

        if ($request->filled('product_sku')) {
            $query->where('product_sku', 'like', '%' . $request->input('product_sku') . '%');
        }

        if ($request->filled('product_name')) {
            $query->where('product_name', 'like', '%' . $request->input('product_name') . '%');
        }

        if ($request->filled('store_id')) {
            $query->where('store_id', $request->input('store_id'));
        }

        $snapshots = $query->paginate($request->input('per_page', 50));

        return $this->paginatedResponse($snapshots);
    }

    // ── GET /store/network/stores ─────────────────────────────────────────────
    // List all other active stores (for the filter dropdown).
    public function stores(): JsonResponse
    {
        $myStoreId = app('current_store_id');

        $stores = Store::where('id', '!=', $myStoreId)
            ->where('is_active', true)
            ->where('status', 'active')
            ->select('id', 'name', 'city', 'country')
            ->orderBy('name')
            ->get();

        return $this->successResponse(['stores' => $stores]);
    }

    // ── POST /store/network/requests ─────────────────────────────────────────
    // Create a transfer request targeting another store's inventory.
    public function createRequest(Request $request): JsonResponse
    {
        $myStoreId = app('current_store_id');
        $myStore   = Store::findOrFail($myStoreId);

        $validated = $request->validate([
            'source_store_id'       => 'required|integer|different:' . $myStoreId,
            'source_location_type'  => 'required|in:branch,warehouse',
            'source_location_id'    => 'required|integer',
            'source_location_name'  => 'required|string|max:150',
            'product_sku'           => 'required|string|max:100',
            'product_name'          => 'required|string|max:255',
            'quantity_requested'    => 'required|numeric|min:0.001',
            'request_notes'         => 'nullable|string|max:1000',
        ]);

        $sourceStore = Store::findOrFail($validated['source_store_id']);

        // Verify there is actually stock to request
        $snapshot = StoreInventorySnapshot::where('store_id',      $validated['source_store_id'])
            ->where('location_type', $validated['source_location_type'])
            ->where('location_id',   $validated['source_location_id'])
            ->where('product_sku',   $validated['product_sku'])
            ->first();

        if (! $snapshot || $snapshot->quantity <= 0) {
            return $this->errorResponse('No stock available at that location.', 422);
        }

        if ($validated['quantity_requested'] > $snapshot->quantity) {
            return $this->errorResponse(
                "Only {$snapshot->quantity} units available. Cannot request {$validated['quantity_requested']}.",
                422
            );
        }

        $req = InterStoreTransferRequest::create([
            'requesting_store_id'   => $myStoreId,
            'requesting_store_name' => $myStore->name,
            'requesting_user_id'    => auth()->user()?->id,
            'source_store_id'       => $sourceStore->id,
            'source_store_name'     => $sourceStore->name,
            'source_location_type'  => $validated['source_location_type'],
            'source_location_id'    => $validated['source_location_id'],
            'source_location_name'  => $validated['source_location_name'],
            'product_sku'           => $validated['product_sku'],
            'product_name'          => $validated['product_name'],
            'quantity_requested'    => $validated['quantity_requested'],
            'status'                => 'pending',
            'request_notes'         => $validated['request_notes'] ?? null,
        ]);

        return $this->successResponse(['request' => $req], 'Transfer request sent.', 201);
    }

    // ── GET /store/network/requests ───────────────────────────────────────────
    // My outgoing requests + incoming requests (direction param: 'out'|'in'|'all').
    public function listRequests(Request $request): JsonResponse
    {
        $myStoreId = app('current_store_id');
        $direction = $request->input('direction', 'all');
        $status    = $request->input('status');

        $query = InterStoreTransferRequest::query();

        if ($direction === 'out') {
            $query->where('requesting_store_id', $myStoreId);
        } elseif ($direction === 'in') {
            $query->where('source_store_id', $myStoreId);
        } else {
            $query->where(function ($q) use ($myStoreId) {
                $q->where('requesting_store_id', $myStoreId)
                  ->orWhere('source_store_id', $myStoreId);
            });
        }

        if ($status) {
            $query->where('status', $status);
        }

        $requests = $query->latest()->paginate($request->input('per_page', 30));

        return $this->paginatedResponse($requests);
    }

    // ── POST /store/network/requests/{id}/approve ─────────────────────────────
    public function approve(Request $request, int $id): JsonResponse
    {
        $myStoreId = app('current_store_id');
        $req       = InterStoreTransferRequest::findOrFail($id);

        if ($req->source_store_id !== $myStoreId) {
            return $this->errorResponse('You can only approve requests targeting your store.', 403);
        }
        if ($req->status !== 'pending') {
            return $this->errorResponse("Cannot approve a request with status '{$req->status}'.", 422);
        }

        $validated = $request->validate([
            'quantity_fulfilled' => 'nullable|numeric|min:0.001',
            'response_notes'     => 'nullable|string|max:1000',
        ]);

        $req->update([
            'status'             => 'approved',
            'quantity_fulfilled' => $validated['quantity_fulfilled'] ?? $req->quantity_requested,
            'response_notes'     => $validated['response_notes'] ?? null,
            'actioned_by_user_id'=> auth()->user()?->id,
            'actioned_at'        => now(),
        ]);

        return $this->successResponse(['request' => $req->fresh()], 'Request approved.');
    }

    // ── POST /store/network/requests/{id}/reject ──────────────────────────────
    public function reject(Request $request, int $id): JsonResponse
    {
        $myStoreId = app('current_store_id');
        $req       = InterStoreTransferRequest::findOrFail($id);

        if ($req->source_store_id !== $myStoreId) {
            return $this->errorResponse('You can only reject requests targeting your store.', 403);
        }
        if (! in_array($req->status, ['pending', 'approved'])) {
            return $this->errorResponse("Cannot reject a request with status '{$req->status}'.", 422);
        }

        $validated = $request->validate(['response_notes' => 'nullable|string|max:1000']);

        $req->update([
            'status'              => 'rejected',
            'response_notes'      => $validated['response_notes'] ?? null,
            'actioned_by_user_id' => auth()->user()?->id,
            'actioned_at'         => now(),
        ]);

        return $this->successResponse(['request' => $req->fresh()], 'Request rejected.');
    }

    // ── POST /store/network/requests/{id}/dispatch ────────────────────────────
    // Source store marks stock as dispatched (in transit).
    public function dispatch(Request $request, int $id): JsonResponse
    {
        $myStoreId = app('current_store_id');
        $req       = InterStoreTransferRequest::findOrFail($id);

        if ($req->source_store_id !== $myStoreId) {
            return $this->errorResponse('Only the source store can dispatch.', 403);
        }
        if ($req->status !== 'approved') {
            return $this->errorResponse("Only approved requests can be dispatched.", 422);
        }

        $req->update(['status' => 'in_transit', 'actioned_at' => now()]);

        return $this->successResponse(['request' => $req->fresh()], 'Marked as in transit.');
    }

    // ── POST /store/network/requests/{id}/complete ────────────────────────────
    // Requesting store confirms receipt.
    public function complete(Request $request, int $id): JsonResponse
    {
        $myStoreId = app('current_store_id');
        $req       = InterStoreTransferRequest::findOrFail($id);

        if ($req->requesting_store_id !== $myStoreId) {
            return $this->errorResponse('Only the requesting store can confirm receipt.', 403);
        }
        if ($req->status !== 'in_transit') {
            return $this->errorResponse("Request must be in transit to complete.", 422);
        }

        $req->update(['status' => 'completed', 'actioned_at' => now()]);

        return $this->successResponse(['request' => $req->fresh()], 'Transfer completed.');
    }

    // ── POST /store/network/requests/{id}/cancel ──────────────────────────────
    // Requesting store cancels their own pending request.
    public function cancel(Request $request, int $id): JsonResponse
    {
        $myStoreId = app('current_store_id');
        $req       = InterStoreTransferRequest::findOrFail($id);

        if ($req->requesting_store_id !== $myStoreId) {
            return $this->errorResponse('Only the requesting store can cancel.', 403);
        }
        if (! in_array($req->status, ['pending', 'approved'])) {
            return $this->errorResponse("Cannot cancel a request with status '{$req->status}'.", 422);
        }

        $req->update(['status' => 'cancelled']);

        return $this->successResponse(['request' => $req->fresh()], 'Request cancelled.');
    }

    // ── GET /store/network/my-inventory ──────────────────────────────────────
    // My own snapshot rows — used to populate the product picker when sending.
    public function myInventory(Request $request): JsonResponse
    {
        $myStoreId = app('current_store_id');

        $rows = StoreInventorySnapshot::where('store_id', $myStoreId)
            ->where('quantity', '>', 0)
            ->when($request->filled('search'), fn ($q) =>
                $q->where(function ($q2) use ($request) {
                    $q2->where('product_name', 'like', '%' . $request->input('search') . '%')
                       ->orWhere('product_sku',  'like', '%' . $request->input('search') . '%');
                })
            )
            ->orderBy('product_name')
            ->get();

        return $this->successResponse(['inventory' => $rows]);
    }

    // ── GET /store/network/stores/{id}/locations ─────────────────────────────
    // Returns branches + warehouses of another store by briefly running in
    // that store's tenant context — read-only, safe.
    public function storeLocations(int $storeId): JsonResponse
    {
        $myStoreId = app('current_store_id');
        if ($storeId === $myStoreId) {
            return $this->errorResponse('Use /store/branches and /store/warehouses for your own store.', 422);
        }

        $store = Store::findOrFail($storeId);

        // Switch to the target tenant DB, read branches + warehouses, then end
        tenancy()->initialize($store);

        $branches   = \App\Models\Branch::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'is_main']);

        $warehouses = \App\Models\Warehouse::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'type']);

        tenancy()->end();

        return $this->successResponse([
            'store'      => ['id' => $store->id, 'name' => $store->name],
            'branches'   => $branches,
            'warehouses' => $warehouses,
        ]);
    }

    // ── POST /store/network/send ──────────────────────────────────────────────
    // My store pushes inventory TO another store. Creates an already-approved
    // transfer so the destination just needs to confirm receipt.
    public function sendToStore(Request $request): JsonResponse
    {
        $myStoreId = app('current_store_id');
        $myStore   = Store::findOrFail($myStoreId);

        $validated = $request->validate([
            'destination_store_id'       => 'required|integer|different:' . $myStoreId,
            'destination_location_type'  => 'required|in:branch,warehouse',
            'destination_location_id'    => 'required|integer',
            'destination_location_name'  => 'required|string|max:150',
            'items'                      => 'required|array|min:1',
            'items.*.product_sku'        => 'required|string|max:100',
            'items.*.product_name'       => 'required|string|max:255',
            'items.*.quantity'           => 'required|numeric|min:0.001',
            'notes'                      => 'nullable|string|max:1000',
        ]);

        $destStore = Store::findOrFail($validated['destination_store_id']);

        $created = [];
        foreach ($validated['items'] as $item) {
            // Check this store actually has stock of this SKU
            $snapshot = StoreInventorySnapshot::where('store_id', $myStoreId)
                ->where('product_sku', $item['product_sku'])
                ->first();

            if (! $snapshot || $snapshot->quantity < $item['quantity']) {
                return $this->errorResponse(
                    "Insufficient stock for {$item['product_sku']}. " .
                    "Available: " . ($snapshot?->quantity ?? 0) . ", requested: {$item['quantity']}.",
                    422
                );
            }

            // Create as already-approved (we're the source and we're initiating)
            $req = InterStoreTransferRequest::create([
                // The SOURCE is us (we're sending)
                'source_store_id'            => $myStoreId,
                'source_store_name'          => $myStore->name,
                // The REQUESTER is the destination (we're creating on their behalf)
                'requesting_store_id'        => $destStore->id,
                'requesting_store_name'      => $destStore->name,
                'requesting_user_id'         => auth()->user()?->id,
                'source_location_type'       => $snapshot->location_type,
                'source_location_id'         => $snapshot->location_id,
                'source_location_name'       => $snapshot->location_name,
                'product_sku'                => $item['product_sku'],
                'product_name'               => $item['product_name'],
                'quantity_requested'         => $item['quantity'],
                'quantity_fulfilled'         => $item['quantity'],
                // Pre-approved since source is initiating
                'status'                     => 'approved',
                'request_notes'              => $validated['notes'] ?? null,
                'actioned_by_user_id'        => auth()->user()?->id,
                'actioned_at'                => now(),
            ]);

            $created[] = $req;
        }

        return $this->successResponse(
            ['transfers' => $created, 'count' => count($created)],
            count($created) . ' outbound transfer(s) created. Destination store will confirm receipt.',
            201
        );
    }
}
