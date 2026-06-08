<?php

use App\Http\Controllers\Api\Admin\AdminReportController;
use App\Http\Controllers\Api\Admin\ApiLogController;
use App\Http\Controllers\Api\Admin\AdminCommunicationsController;
use App\Http\Controllers\Api\Admin\CommunicationsProviderController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\LandingPageController;
use App\Http\Controllers\Api\Admin\BillingController;
use App\Http\Controllers\Api\Admin\ModuleController;
use App\Http\Controllers\Api\Admin\PaymentGatewayController;
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
    Route::get('{id}/analytics', [StoreController::class, 'analytics']);
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

// Payment Gateways
Route::prefix('payment-gateways')->group(function () {
    Route::get('/', [PaymentGatewayController::class, 'index']);
    Route::put('{slug}', [PaymentGatewayController::class, 'update']);
    Route::post('{slug}/test', [PaymentGatewayController::class, 'test']);
});

// Central billing / subscription management
Route::prefix('billing')->group(function () {
    Route::get('stats', [BillingController::class, 'stats']);

    Route::get('subscriptions', [BillingController::class, 'subscriptions']);
    Route::get('subscriptions/{id}', [BillingController::class, 'showSubscription']);
    Route::post('subscriptions/{id}/extend', [BillingController::class, 'extendSubscription']);
    Route::post('subscriptions/{id}/cancel', [BillingController::class, 'cancelSubscription']);
    Route::post('subscriptions/{id}/reactivate', [BillingController::class, 'reactivateSubscription']);

    Route::get('payments', [BillingController::class, 'payments']);
    Route::get('payments/{id}', [BillingController::class, 'showPayment']);
    Route::post('payments/{id}/refund', [BillingController::class, 'refundPayment']);

    Route::get('events', [BillingController::class, 'events']);
    Route::get('events/{id}', [BillingController::class, 'showEvent']);
});

// Platform-wide Admin Reports
Route::prefix('reports')->group(function () {
    Route::get('/',                    [AdminReportController::class, 'index']);
    Route::get('{slug}/schema',        [AdminReportController::class, 'schema']);
    Route::post('{slug}/run',          [AdminReportController::class, 'run']);
    Route::post('{slug}/export',       [AdminReportController::class, 'export']);
});

// Communications Providers
Route::prefix('communications-providers')->group(function () {
    Route::get('/',              [CommunicationsProviderController::class, 'index']);
    Route::put('{id}',           [CommunicationsProviderController::class, 'update'])->whereNumber('id');
    Route::post('{id}/test',     [CommunicationsProviderController::class, 'test'])->whereNumber('id');
    Route::post('{id}/set-default',[CommunicationsProviderController::class,'setDefault'])->whereNumber('id');
});

// Platform-wide Communications Overview
Route::prefix('communications')->group(function () {
    Route::get('overview',          [AdminCommunicationsController::class, 'overview']);
    Route::get('quotas',            [AdminCommunicationsController::class, 'quotas']);
    Route::get('stores/{id}/logs',  [AdminCommunicationsController::class, 'storeLogs'])->whereNumber('id');
});

// API Logs
Route::prefix('api-logs')->group(function () {
    Route::get('/', [ApiLogController::class, 'index']);
    Route::get('stats', [ApiLogController::class, 'stats']);
    Route::get('{id}', [ApiLogController::class, 'show']);
    Route::post('purge', [ApiLogController::class, 'purge']);
});
