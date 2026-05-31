<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\EmailVerificationController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\Public\LandingController;
use App\Http\Controllers\Api\Payments\JazzCashCallbackController;
use App\Http\Controllers\Api\Payments\EasypaisaCallbackController;
use App\Http\Controllers\Api\Webhook\PayPalWebhookController;
use App\Http\Controllers\Api\Webhook\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // ============================================
    // PUBLIC ROUTES (no authentication required)
    // ============================================
    Route::prefix('public')->group(function () {
        // Landing page data - protected by landing.enabled middleware
        Route::middleware('landing.enabled')->group(function () {
            Route::get('landing', [LandingController::class, 'index']);
            Route::get('landing/plans', [LandingController::class, 'plans']);
        });

        // Status check - always accessible
        Route::get('landing/status', [LandingController::class, 'status']);
    });

    // ============================================
    // AUTHENTICATION ROUTES
    // ============================================
    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth');
        Route::post('register', [RegisterController::class, 'register'])->middleware('throttle:auth');
        Route::post('check-email', [RegisterController::class, 'checkEmail']);
        Route::post('check-store-name', [RegisterController::class, 'checkStoreName']);
        Route::post('email/verify', [EmailVerificationController::class, 'verify']);
        Route::post('password/forgot', [PasswordResetController::class, 'forgot']);
        Route::post('password/validate', [PasswordResetController::class, 'validateToken']);
        Route::post('password/reset', [PasswordResetController::class, 'reset']);

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('logout-all', [AuthController::class, 'logoutAll']);
            Route::post('email/resend', [EmailVerificationController::class, 'resend']);
            Route::get('email/status', [EmailVerificationController::class, 'status']);
        });
    });

    // ============================================
    // ADMIN ROUTES (super admin only)
    // ============================================
    Route::prefix('admin')
        ->middleware(['auth:sanctum', 'super.admin'])
        ->group(base_path('routes/api/admin.php'));

    // ============================================
    // STORE ROUTES (tenant users)
    // ============================================
    Route::prefix('store')
        ->middleware(['auth:sanctum', 'initialize.tenancy', 'tenant.scope'])
        ->group(base_path('routes/api/store.php'));

    // Payment gateway webhooks (CSRF-exempt, signature-verified)
    Route::prefix('webhooks')->group(function () {
        Route::post('stripe', [StripeWebhookController::class, 'handle']);
        Route::post('paypal', [PayPalWebhookController::class, 'handle']);
    });

    // Legacy alias kept for backward compat with already-registered Stripe dashboard URLs
    Route::post('stripe/webhook', [StripeWebhookController::class, 'handle']);

    // Pakistani gateway callbacks — no auth, signature is the auth
    Route::prefix('payments')->group(function () {
        Route::post('jazzcash/callback', [JazzCashCallbackController::class, 'callback']);
        Route::match(['GET', 'POST'], 'jazzcash/return', [JazzCashCallbackController::class, 'return']);

        Route::post('easypaisa/callback', [EasypaisaCallbackController::class, 'callback']);
        Route::match(['GET', 'POST'], 'easypaisa/return', [EasypaisaCallbackController::class, 'return']);
    });
});

// Health check (outside v1 prefix for monitoring)
Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'timestamp' => now()->toIso8601String(),
    'app' => config('app.name'),
]));
