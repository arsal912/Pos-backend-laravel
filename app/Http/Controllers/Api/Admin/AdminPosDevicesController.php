<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\PosDevice;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Super-admin view of POS devices across ALL stores.
 * Reads from central DB (pos_devices) only — no tenant DB access.
 */
class AdminPosDevicesController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $query = PosDevice::with('store:id,name,status')
            ->withTrashed()
            ->orderBy('last_seen_at', 'desc');

        if ($request->filled('store_id')) {
            $query->where('store_id', $request->integer('store_id'));
        }

        if ($request->filled('status')) {
            match ($request->input('status')) {
                'active'      => $query->where('is_active', true)->whereNull('deleted_at'),
                'deactivated' => $query->where('is_active', false),
                'online'      => $query->where('last_seen_at', '>=', now()->subMinutes(10)),
                'offline'     => $query->where(fn ($q) =>
                    $q->whereNull('last_seen_at')
                      ->orWhere('last_seen_at', '<', now()->subMinutes(10))
                ),
                default       => null,
            };
        }

        if ($request->filled('search')) {
            $term = $request->input('search');
            $query->where(fn ($q) =>
                $q->where('device_name', 'like', "%{$term}%")
                  ->orWhere('device_uuid', 'like', "%{$term}%")
            );
        }

        $devices = $query->paginate($request->integer('per_page', 30));

        // Annotate each device with computed status
        $items = $devices->getCollection()->map(fn ($d) => array_merge(
            $d->toArray(),
            ['status' => $d->status]
        ));

        return $this->successResponse([
            'devices' => $items,
            'meta'    => [
                'total'       => $devices->total(),
                'current_page'=> $devices->currentPage(),
                'last_page'   => $devices->lastPage(),
            ],
            // Platform-level summary
            'summary' => [
                'total_devices'    => PosDevice::count(),
                'active_devices'   => PosDevice::where('is_active', true)->count(),
                'online_devices'   => PosDevice::where('last_seen_at', '>=', now()->subMinutes(10))->count(),
                'pending_total'    => PosDevice::sum('pending_sales_count'),
            ],
        ]);
    }

    /** Force-deactivate a device from the admin panel. */
    public function deactivate(int $id): JsonResponse
    {
        $device = PosDevice::withTrashed()->findOrFail($id);
        $device->update(['is_active' => false]);
        $device->delete(); // soft-delete for audit trail

        return $this->successResponse(null, "Device {$device->device_name} deactivated.");
    }
}
