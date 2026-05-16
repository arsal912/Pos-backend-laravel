<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // API stateful for Sanctum
        $middleware->statefulApi();

        // Global API middleware
        $middleware->api(prepend: [
            \App\Http\Middleware\ApiLogger::class,
        ]);

        // Named middleware aliases
        $middleware->alias([
            'super.admin' => \App\Http\Middleware\SuperAdmin::class,
            'tenant.scope' => \App\Http\Middleware\TenantScope::class,
            'module' => \App\Http\Middleware\ModuleAccess::class,
            'landing.enabled' => \App\Http\Middleware\LandingPageEnabled::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);

        // Rate limiters
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute((int) env('RATE_LIMIT_API', 120))
                ->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute((int) env('RATE_LIMIT_AUTH', 10))
                ->by($request->ip());
        });
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // JSON responses for all API exceptions
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Resource not found.',
                ], 404);
            }
        });

        $exceptions->render(function (\Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => config('app.debug') ? $e->getMessage() : 'Server error.',
                    'trace' => config('app.debug') ? $e->getTrace() : null,
                ], 500);
            }
        });
    })
    ->create();
