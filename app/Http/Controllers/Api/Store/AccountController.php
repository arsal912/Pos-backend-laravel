<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    use ApiResponse;

    /**
     * Schedule account/store deletion (30-day grace period).
     * POST /store/account/request-deletion
     */
    public function requestDeletion(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasRole('store-owner')) {
            return $this->errorResponse('Only the store owner can request account deletion.', 403);
        }

        $store = $user->store;

        if (! $store) {
            return $this->notFoundResponse('Store not found.');
        }

        if ($store->deletion_scheduled_at) {
            return $this->errorResponse(
                'Account deletion is already scheduled for ' . $store->deletion_scheduled_at->toIso8601String() . '.',
                409
            );
        }

        $scheduledAt = now()->addDays(30);
        $store->deletion_scheduled_at = $scheduledAt;
        $store->save();

        return $this->successResponse([
            'store_id'              => $store->id,
            'store_name'            => $store->name,
            'deletion_scheduled_at' => $scheduledAt->toIso8601String(),
            'note'                  => 'Your account and all associated data will be permanently deleted on the scheduled date. Contact support before then to cancel.',
        ], 'Account deletion scheduled successfully.');
    }
}
