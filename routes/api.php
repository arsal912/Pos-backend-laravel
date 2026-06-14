<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\EmailVerificationController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\Public\LandingController;
use App\Http\Controllers\Api\Payments\JazzCashCallbackController;
use App\Http\Controllers\Api\Payments\EasypaisaCallbackController;
use App\Http\Controllers\Api\UnsubscribeController;
use App\Http\Controllers\Api\Webhook\CommunicationsWebhookController;
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
    // HEALTH (public — used by PWA offline probe)
    // ============================================
    Route::get('health', function () {
        return response()->json(['status' => 'ok', 'ts' => now()->toIso8601String()]);
    });

    Route::get('health/detail', function (\Illuminate\Http\Request $request) {
        $token = $request->header('X-Health-Token');
        if ($token !== config('app.health_check_token') || !$token) {
            return response()->json(['status' => 'ok']); // don't leak details without token
        }

        $checks = [];

        // DB check
        try {
            \DB::connection()->getPdo();
            $checks['database'] = 'ok';
        } catch (\Exception $e) {
            $checks['database'] = 'error: ' . $e->getMessage();
        }

        // Queue depth (jobs table)
        try {
            $pending = \DB::table('jobs')->count();
            $failed  = \DB::table('failed_jobs')->count();
            $checks['queue_pending'] = $pending;
            $checks['queue_failed']  = $failed;
            $checks['queue'] = $failed > 10 ? 'degraded' : 'ok';
        } catch (\Exception $e) {
            $checks['queue'] = 'error';
        }

        $allOk = !collect($checks)->contains(fn($v) => str_contains((string)$v, 'error') || $v === 'degraded');
        $status = $allOk ? 200 : 503;

        return response()->json([
            'status'    => $allOk ? 'ok' : 'degraded',
            'checks'    => $checks,
            'timestamp' => now()->toIso8601String(),
            'version'   => config('app.version', '1.0.0'),
        ], $status);
    })->middleware('throttle:60,1');

    // ============================================
    // UNSUBSCRIBE (public, no auth, HMAC-signed)
    // ============================================
    Route::prefix('unsubscribe')->group(function () {
        Route::get('/',         [UnsubscribeController::class, 'show']);
        Route::post('/confirm', [UnsubscribeController::class, 'confirm']);
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
        Route::post('stripe',                        [StripeWebhookController::class,          'handle']);
        Route::post('paypal',                        [PayPalWebhookController::class,           'handle']);
        // Communications delivery webhooks (Twilio + Resend)
        Route::post('communications/sms',            [CommunicationsWebhookController::class,   'sms']);
        Route::post('communications/email',          [CommunicationsWebhookController::class,   'email']);
        Route::post('communications/whatsapp',       [CommunicationsWebhookController::class,   'whatsapp']);
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
