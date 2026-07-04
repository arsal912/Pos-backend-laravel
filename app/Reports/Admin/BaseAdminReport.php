<?php

namespace App\Reports\Admin;

use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;

/**
 * Base class for super-admin platform-wide reports.
 * These run against the CENTRAL DB (store_aggregates, stores, subscriptions)
 * NOT against any tenant DB.
 */
abstract class BaseAdminReport extends BaseReport
{
    public function getCategory(): string { return 'admin'; }
    public function getRequiredModule(): ?string { return null; }
    public function getRequiredPermission(): ?string { return 'view-admin-reports'; }

    /** Admin reports always use the central DB. */
    protected function centralDb()
    {
        return DB::connection('mysql');
    }

    protected function timezone(): string
    {
        return config('app.timezone', 'Asia/Karachi');
    }
}
