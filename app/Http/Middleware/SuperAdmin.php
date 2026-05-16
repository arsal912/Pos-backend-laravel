<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (!$user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Super admin privileges required.',
            ], 403);
        }

        return $next($request);
    }
}
