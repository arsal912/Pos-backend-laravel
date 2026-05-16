<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TenantScope
{
    /**
     * Ensures user accessing tenant routes has a valid store and is active.
     * The actual data scoping happens via global query scopes on tenant-aware models.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        if (!$user->store_id) {
            return response()->json([
                'success' => false,
                'message' => 'No store associated with this account.',
            ], 403);
        }

        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is disabled.',
            ], 403);
        }

        $store = $user->store;

        if (!$store || !$store->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Your store is not active.',
            ], 403);
        }

        if ($store->status === 'suspended') {
            return response()->json([
                'success' => false,
                'message' => 'Your store has been suspended. Please contact support.',
            ], 403);
        }

        if ($store->status === 'expired' || (!$store->isOnTrial() && !$store->hasActiveSubscription())) {
            return response()->json([
                'success' => false,
                'message' => 'Your subscription has expired. Please renew to continue.',
                'subscription_expired' => true,
            ], 402);
        }

        // Set tenant context for the request
        app()->instance('current_store', $store);
        app()->instance('current_store_id', $store->id);

        return $next($request);
    }
}
