<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Store / Tenant API Routes
| Prefix: /api/v1/store
| Middleware: auth:sanctum, tenant.scope
|--------------------------------------------------------------------------
*/

// Placeholder - core POS routes will be added in Phase 4+
Route::get('test', function () {
    return response()->json([
        'success' => true,
        'message' => 'Store routes are working',
        'store_id' => app('current_store_id'),
        'user' => auth()->user()->only(['id', 'name', 'email']),
    ]);
});

// Example of a module-protected route (will be expanded in Phase 4)
Route::middleware('module:pos-sales')->get('pos/ping', function () {
    return response()->json([
        'success' => true,
        'message' => 'POS sales module accessible',
    ]);
});

Route::middleware('module:inventory')->get('inventory/ping', function () {
    return response()->json([
        'success' => true,
        'message' => 'Inventory module accessible',
    ]);
});

Route::middleware('module:reports')->get('reports/ping', function () {
    return response()->json([
        'success' => true,
        'message' => 'Reports module accessible',
    ]);
});
