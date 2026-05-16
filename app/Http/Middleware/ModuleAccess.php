<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ModuleAccess
{
    /**
     * Usage in routes:
     *   Route::middleware('module:inventory')->group(...)
     *
     * Priority of checks:
     *   1. Super admin → always allowed
     *   2. User-level override (UserModule)
     *   3. Store-level setting (StoreModule)
     *   4. Plan default (Plan -> modules)
     */
    public function handle(Request $request, Closure $next, string $moduleSlug): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Super admin bypasses all module checks
        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        // User must belong to a store
        if (!$user->store_id) {
            return response()->json([
                'success' => false,
                'message' => 'No store associated with this account.',
            ], 403);
        }

        // Store must be active
        if (!$user->store?->is_active || $user->store->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Your store is not active. Please contact support.',
            ], 403);
        }

        // Check module access (uses cascade: user override -> store -> plan)
        if (!$user->hasModuleAccess($moduleSlug)) {
            return response()->json([
                'success' => false,
                'message' => "Access to the '{$moduleSlug}' module is not enabled for your account.",
                'module' => $moduleSlug,
            ], 403);
        }

        return $next($request);
    }
}
