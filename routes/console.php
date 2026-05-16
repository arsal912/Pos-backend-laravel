<?php

use App\Models\ApiLog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Purge old API logs (default retention: 30 days)
 * Usage: php artisan api-logs:purge [--days=30]
 */
Artisan::command('api-logs:purge {--days=30}', function () {
    $days = (int) $this->option('days');
    $deleted = ApiLog::where('created_at', '<', now()->subDays($days))->delete();
    $this->info("Deleted {$deleted} API log entries older than {$days} days.");
})->purpose('Purge old API log entries');

// Schedule API log cleanup daily at 2am
Schedule::command('api-logs:purge --days=' . config('api-logging.retention_days', 30))
    ->dailyAt('02:00');
