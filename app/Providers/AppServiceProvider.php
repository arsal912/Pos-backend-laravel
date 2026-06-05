<?php

namespace App\Providers;

use App\Models\Sale;
use App\Observers\SaleObserver;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportManager;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReportManager::class);
        $this->app->singleton(ReportExporter::class);
    }

    public function boot(): void
    {
        Sale::observe(SaleObserver::class);
    }
}
