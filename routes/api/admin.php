<?php

use App\Http\Controllers\Api\Admin\ApiLogController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\LandingPageController;
use App\Http\Controllers\Api\Admin\ModuleController;
use App\Http\Controllers\Api\Admin\StoreController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin API Routes (Super Admin only)
| Prefix: /api/v1/admin
| Middleware: auth:sanctum, super.admin
|--------------------------------------------------------------------------
*/

// Dashboard
Route::get('dashboard', [DashboardController::class, 'index']);

// Stores / Tenants Management
Route::prefix('stores')->group(function () {
    Route::get('/', [StoreController::class, 'index']);
    Route::get('{id}', [StoreController::class, 'show']);
    Route::put('{id}/status', [StoreController::class, 'updateStatus']);
    Route::delete('{id}', [StoreController::class, 'destroy']);
    Route::post('{id}/impersonate', [StoreController::class, 'impersonate']);
});

// Landing Page CMS
Route::prefix('landing-page')->group(function () {
    Route::get('/', [LandingPageController::class, 'index']);
    Route::put('toggle', [LandingPageController::class, 'toggle']);
    Route::put('settings', [LandingPageController::class, 'updateSettings']);
    Route::put('sections/{sectionKey}', [LandingPageController::class, 'updateSection']);
    Route::patch('sections/{sectionKey}/toggle', [LandingPageController::class, 'toggleSection']);
    Route::post('sections/reorder', [LandingPageController::class, 'reorderSections']);
});

// Module Management (the key feature!)
Route::prefix('modules')->group(function () {
    Route::get('/', [ModuleController::class, 'index']);

    // Store-level module toggles
    Route::get('store/{storeId}', [ModuleController::class, 'getStoreModules']);
    Route::put('store/{storeId}/module/{moduleId}', [ModuleController::class, 'updateStoreModule']);
    Route::post('store/{storeId}/bulk', [ModuleController::class, 'bulkUpdateStoreModules']);

    // User-level module overrides
    Route::get('user/{userId}', [ModuleController::class, 'getUserModules']);
    Route::put('user/{userId}/module/{moduleId}', [ModuleController::class, 'updateUserModule']);
    Route::delete('user/{userId}/module/{moduleId}', [ModuleController::class, 'removeUserModuleOverride']);
});

// API Logs
Route::prefix('api-logs')->group(function () {
    Route::get('/', [ApiLogController::class, 'index']);
    Route::get('stats', [ApiLogController::class, 'stats']);
    Route::get('{id}', [ApiLogController::class, 'show']);
    Route::post('purge', [ApiLogController::class, 'purge']);
});
