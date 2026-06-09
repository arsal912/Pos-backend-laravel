<?php

namespace App\Http\Controllers\Api\Store\Pos;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\PosDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    use ApiResponse;

    /**
     * Register a device or return existing registration (idempotent by device_uuid).
     * Called on every POS load — safe to call repeatedly.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_uuid'  => 'required|string|max:64',
            'device_name'  => 'nullable|string|max:100',
            'user_agent'   => 'nullable|string|max:500',
            'fingerprint'  => 'nullable|string|max:128',
        ]);

        $storeId = app('current_store_id');

        $device = PosDevice::withTrashed()
            ->where('device_uuid', $validated['device_uuid'])
            ->where('store_id', $storeId)
            ->first();

        if ($device) {
            // Restore if soft-deleted
            if ($device->trashed()) {
                $device->restore();
                $device->update(['is_active' => true]);
            }
            $device->update([
                'device_name' => $validated['device_name'] ?? $device->device_name,
                'user_agent'  => $validated['user_agent']  ?? $device->user_agent,
                'fingerprint' => $validated['fingerprint'] ?? $device->fingerprint,
                'last_seen_at'=> now(),
            ]);
        } else {
            $device = PosDevice::create([
                'store_id'      => $storeId,
                'device_uuid'   => $validated['device_uuid'],
                'device_name'   => $validated['device_name'] ?? 'POS Terminal',
                'user_agent'    => $validated['user_agent']  ?? $request->userAgent(),
                'fingerprint'   => $validated['fingerprint'],
                'registered_by' => auth()->id(),
                'last_seen_at'  => now(),
                'is_active'     => true,
            ]);
        }

        return $this->successResponse([
            'device_id'     => $device->id,
            'device_uuid'   => $device->device_uuid,
            'device_name'   => $device->device_name,
            'registered_at' => $device->created_at,
            'is_active'     => $device->is_active,
            'sync_endpoint' => '/api/v1/store/pos/sync/sales',
        ], 'Device registered.', 200);
    }

    /**
     * List all devices for this store. Requires manage-pos-devices.
     */
    public function index(Request $request): JsonResponse
    {
        $devices = PosDevice::where('store_id', app('current_store_id'))
            ->orderBy('last_seen_at', 'desc')
            ->get()
            ->map(fn ($d) => array_merge($d->toArray(), ['status' => $d->status]));

        return $this->successResponse(['devices' => $devices]);
    }

    /**
     * Update device name or active flag. Requires manage-pos-devices.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $device = PosDevice::where('store_id', app('current_store_id'))->findOrFail($id);

        $validated = $request->validate([
            'device_name' => 'sometimes|string|max:100',
            'is_active'   => 'sometimes|boolean',
        ]);

        $device->update($validated);

        return $this->successResponse($device->fresh(), 'Device updated.');
    }

    /**
     * Deactivate a device (soft-delete + is_active=false). Never hard-deletes.
     * Deactivated devices get 403 on subsequent sync attempts.
     */
    public function destroy(int $id): JsonResponse
    {
        $device = PosDevice::where('store_id', app('current_store_id'))->findOrFail($id);
        $device->update(['is_active' => false]);
        $device->delete(); // soft delete — audit trail preserved

        return $this->successResponse(null, 'Device deactivated.');
    }

    /**
     * Heartbeat — updates last_seen_at. Called every 5 min by POS client.
     */
    public function ping(Request $request, int $id): JsonResponse
    {
        $device = PosDevice::where('store_id', app('current_store_id'))
            ->where('is_active', true)
            ->find($id);

        if (! $device) {
            return $this->errorResponse('Device not found or deactivated.', 403);
        }

        $device->update([
            'last_seen_at'        => now(),
            'pending_sales_count' => max(0, (int) $request->input('pending_count', 0)),
        ]);

        return $this->successResponse(['pinged_at' => now()]);
    }

    /**
     * Per-device sync health stats — used by the enhanced devices settings page.
     */
    public function syncStatus(int $id): JsonResponse
    {
        $device   = PosDevice::where('store_id', app('current_store_id'))->findOrFail($id);
        $storeId  = app('current_store_id');

        // Query tenant DB for sales synced from this device
        $salesToday = \App\Models\Sale::where('synced_from_device_id', $device->id)
            ->whereDate('created_at', today())
            ->count();

        $conflictsUnresolved = \App\Models\Sale::where('synced_from_device_id', $device->id)
            ->where(function ($q) {
                $q->where('has_stock_conflict', true)
                  ->orWhere('has_credit_conflict', true);
            })
            ->count();

        $salesLast30 = \App\Models\Sale::where('synced_from_device_id', $device->id)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        return $this->successResponse([
            'device'                => array_merge($device->toArray(), ['status' => $device->status]),
            'sales_synced_today'    => $salesToday,
            'sales_synced_30d'      => $salesLast30,
            'unresolved_conflicts'  => $conflictsUnresolved,
            'pending_sales_count'   => $device->pending_sales_count,
        ]);
    }
}
