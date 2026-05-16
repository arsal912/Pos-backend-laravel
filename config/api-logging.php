<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Logging Configuration
    |--------------------------------------------------------------------------
    */

    'enabled' => env('API_LOGGING_ENABLED', true),

    'retention_days' => env('API_LOG_RETENTION_DAYS', 30),

    'max_body_size' => env('API_LOG_MAX_BODY_SIZE', 10000),

    /*
    |--------------------------------------------------------------------------
    | Routes to exclude from logging
    | Supports fnmatch patterns (* wildcard)
    |--------------------------------------------------------------------------
    */
    'excluded_routes' => [
        'api/health',
        'api/v1/public/landing/status',
        'up',
    ],

    /*
    |--------------------------------------------------------------------------
    | Additional sensitive fields to mask
    |--------------------------------------------------------------------------
    */
    'sensitive_fields' => [
        'password',
        'password_confirmation',
        'token',
        'api_key',
        'card_number',
        'cvv',
    ],
];
