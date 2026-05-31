<?php

use App\Http\Controllers\Api\Store\BillingController;
use App\Http\Controllers\Api\Store\Catalog\BrandController;
use App\Http\Controllers\Api\Store\Catalog\CategoryController;
use App\Http\Controllers\Api\Store\Catalog\ProductController;
use App\Http\Controllers\Api\Store\Catalog\TaxRateController;
use App\Http\Controllers\Api\Store\Catalog\UnitController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Store / Tenant API Routes
| Prefix: /api/v1/store
| Middleware: auth:sanctum, initialize.tenancy, tenant.scope
|--------------------------------------------------------------------------
*/

// Health check
Route::get('test', function () {
    return response()->json([
        'success'  => true,
        'message'  => 'Store routes are working',
        'store_id' => app('current_store_id'),
        'user'     => auth()->user()->only(['id', 'name', 'email']),
    ]);
});

// Billing
Route::prefix('billing')->group(function () {
    Route::get('gateways',               [BillingController::class, 'gateways']);
    Route::get('plans',                  [BillingController::class, 'plans']);
    Route::get('subscription',           [BillingController::class, 'subscription']);
    Route::post('checkout',              [BillingController::class, 'checkout']);
    Route::get('sessions/{sessionId}',   [BillingController::class, 'verifySession']);
    Route::post('cancel',                [BillingController::class, 'cancel']);
    Route::post('change-plan',           [BillingController::class, 'changePlan']);
    Route::get('payments',               [BillingController::class, 'payments']);
    Route::get('payments/{id}/invoice',  [BillingController::class, 'invoice']);
    Route::post('portal',                [BillingController::class, 'portal']);
});

// ============================================================================
// PHASE 4 — CATALOG  (module: products)
// ============================================================================
Route::middleware('module:products')->group(function () {

    // Categories
    Route::prefix('categories')->group(function () {
        Route::get('/',      [CategoryController::class, 'index']);
        Route::post('/',     [CategoryController::class, 'store']);
        Route::put('{id}',   [CategoryController::class, 'update'])->whereNumber('id');
        Route::delete('{id}',[CategoryController::class, 'destroy'])->whereNumber('id');
    });

    // Brands
    Route::prefix('brands')->group(function () {
        Route::get('/',      [BrandController::class, 'index']);
        Route::post('/',     [BrandController::class, 'store']);
        Route::put('{id}',   [BrandController::class, 'update'])->whereNumber('id');
        Route::delete('{id}',[BrandController::class, 'destroy'])->whereNumber('id');
    });

    // Tax rates
    Route::prefix('tax-rates')->group(function () {
        Route::get('/',      [TaxRateController::class, 'index']);
        Route::post('/',     [TaxRateController::class, 'store']);
        Route::put('{id}',   [TaxRateController::class, 'update'])->whereNumber('id');
        Route::delete('{id}',[TaxRateController::class, 'destroy'])->whereNumber('id');
    });

    // Units
    Route::prefix('units')->group(function () {
        Route::get('/',      [UnitController::class, 'index']);
        Route::post('/',     [UnitController::class, 'store']);
        Route::put('{id}',   [UnitController::class, 'update'])->whereNumber('id');
        Route::delete('{id}',[UnitController::class, 'destroy'])->whereNumber('id');
    });

    // Products — lookup must be defined BEFORE /{id} to avoid route conflict
    Route::prefix('products')->group(function () {
        Route::get('lookup', [ProductController::class, 'lookup']);      // barcode/SKU fast lookup
        Route::get('/',      [ProductController::class, 'index']);
        Route::post('/',     [ProductController::class, 'store']);

        Route::prefix('{id}')->whereNumber('id')->group(function () {
            Route::get('/',            [ProductController::class, 'show']);
            Route::put('/',            [ProductController::class, 'update']);
            Route::delete('/',         [ProductController::class, 'destroy']);

            // Variants
            Route::post('variants',                   [ProductController::class, 'storeVariant']);
            Route::put('variants/{variantId}',         [ProductController::class, 'updateVariant'])
                ->whereNumber('variantId');
            Route::delete('variants/{variantId}',      [ProductController::class, 'destroyVariant'])
                ->whereNumber('variantId');

            // Images
            Route::post('images',              [ProductController::class, 'uploadImage']);
            Route::delete('images/{imageId}',  [ProductController::class, 'destroyImage'])
                ->whereNumber('imageId');

            // Barcode generation
            Route::post('barcode/generate',    [ProductController::class, 'generateBarcode']);
        });
    });
});

// ============================================================================
// File serving — tenant-aware, auth required
// ============================================================================
Route::get('files/{path}', function (Request $request, string $path) {
    if (! $request->user()) {
        abort(401);
    }
    if (! Storage::disk('local')->exists($path)) {
        abort(404);
    }
    return Storage::disk('local')->response($path);
})->where('path', '.*');

// ============================================================================
// Module ping routes (for client-side capability checks)
// ============================================================================
Route::middleware('module:pos-sales')->get('pos/ping', fn () => response()->json(['success' => true, 'module' => 'pos-sales']));
Route::middleware('module:inventory')->get('inventory/ping', fn () => response()->json(['success' => true, 'module' => 'inventory']));
Route::middleware('module:reports')->get('reports/ping', fn () => response()->json(['success' => true, 'module' => 'reports']));
