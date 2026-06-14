<?php

return [
    'dsn' => env('SENTRY_LARAVEL_DSN', ''),
    'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE', 0.1),
    'profiles_sample_rate' => 0.0,
    'send_default_pii' => false,
    'before_send' => null,
    'environment' => env('APP_ENV', 'production'),
    'release' => env('APP_VERSION', '1.0.0'),
    'ignore_exceptions' => [
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Validation\ValidationException::class,
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
    ],
];
