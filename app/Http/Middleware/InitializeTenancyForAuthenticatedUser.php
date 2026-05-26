<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InitializeTenancyForAuthenticatedUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        if (! $user->store_id) {
            return response()->json([
                'success' => false,
                'message' => 'No store associated with this account.',
            ], 403);
        }

        $store = $user->store;

        if (! $store || ! $store->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Your store is not active.',
            ], 403);
        }

        tenancy()->initialize($store);
        app()->instance('current_store', $store);
        app()->instance('current_store_id', $store->id);

        return $next($request);
    }
}
