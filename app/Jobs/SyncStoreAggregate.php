<?php

namespace App\Jobs;

use App\Models\Store;
use App\Models\StoreAggregate;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncStoreAggregate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Store $store;

    public function __construct(Store $store)
    {
        $this->store = $store;
    }

    public function handle(): void
    {
        // Central-DB data (always available)
        $activeUsersCount = User::where('store_id', $this->store->id)
            ->where('is_active', true)
            ->count();

        // Tenant-DB data
        $branchesCount = 0;
        $totalRevenue  = 0.0;
        $monthRevenue  = 0.0;
        $todayRevenue  = 0.0;
        $lastSaleAt    = null;
        $meta          = [];

        try {
            $this->store->run(function () use (
                &$branchesCount, &$totalRevenue, &$monthRevenue,
                &$todayRevenue, &$lastSaleAt, &$meta
            ) {
                $now = now();

                // Branches (Phase 2.5)
                $branchesCount = DB::table('branches')->count();

                // Sales (Phase 4)
                if (DB::getSchemaBuilder()->hasTable('sales')) {
                    $completedSales = DB::table('sales')->where('status', 'completed');

                    $totalRevenue = (float) (clone $completedSales)->sum('total');

                    $monthRevenue = (float) (clone $completedSales)
                        ->whereMonth('sale_date', $now->month)
                        ->whereYear('sale_date', $now->year)
                        ->sum('total');

                    $todayRevenue = (float) (clone $completedSales)
                        ->whereDate('sale_date', $now->toDateString())
                        ->sum('total');

                    $salesCountToday = (clone $completedSales)
                        ->whereDate('sale_date', $now->toDateString())
                        ->count();

                    $salesMonthCount = (clone $completedSales)
                        ->whereMonth('sale_date', $now->month)
                        ->whereYear('sale_date', $now->year)
                        ->count();

                    $avgOrderToday = $salesCountToday > 0
                        ? round($todayRevenue / $salesCountToday, 2)
                        : 0;

                    $lastSaleAt = DB::table('sales')
                        ->where('status', 'completed')
                        ->orderByDesc('sale_date')
                        ->value('sale_date');

                    // Top product today by line total
                    $topProduct = DB::table('sale_items')
                        ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                        ->where('sales.status', 'completed')
                        ->whereDate('sales.sale_date', $now->toDateString())
                        ->groupBy('sale_items.product_name')
                        ->select('sale_items.product_name', DB::raw('SUM(sale_items.line_total) as revenue'))
                        ->orderByDesc('revenue')
                        ->first();

                    $meta['sales_today_count']  = $salesCountToday;
                    $meta['sales_today_amount'] = $todayRevenue;
                    $meta['sales_month_count']  = $salesMonthCount;
                    $meta['avg_order_value']    = $avgOrderToday;
                    $meta['top_product_today']  = $topProduct?->product_name;
                }

                // Products & Inventory (Phase 4)
                if (DB::getSchemaBuilder()->hasTable('products')) {
                    $meta['total_products'] = DB::table('products')
                        ->whereNull('deleted_at')
                        ->where('is_active', true)
                        ->count();
                }

                if (DB::getSchemaBuilder()->hasTable('inventory_items')) {
                    $meta['low_stock_count'] = DB::table('inventory_items')
                        ->join('products', 'products.id', '=', 'inventory_items.product_id')
                        ->whereNotNull('products.low_stock_threshold')
                        ->whereRaw('inventory_items.quantity <= products.low_stock_threshold')
                        ->whereNull('products.deleted_at')
                        ->count();
                }

                // Customers (Phase 4)
                if (DB::getSchemaBuilder()->hasTable('customers')) {
                    $meta['total_customers'] = DB::table('customers')
                        ->whereNull('deleted_at')
                        ->count();

                    $meta['new_customers_today'] = DB::table('customers')
                        ->whereNull('deleted_at')
                        ->whereDate('created_at', now()->toDateString())
                        ->count();
                }
            });
        } catch (\Throwable $e) {
            Log::warning("SyncStoreAggregate failed for store {$this->store->id}: {$e->getMessage()}");
        }

        // Phase 6 — POS device metrics (central DB, no tenant context needed)
        $meta['pos_devices_active']    = \App\Models\PosDevice::where('store_id', $this->store->id)
            ->where('is_active', true)
            ->count();
        $meta['pos_devices_online']    = \App\Models\PosDevice::where('store_id', $this->store->id)
            ->where('last_seen_at', '>=', now()->subMinutes(10))
            ->count();
        $meta['offline_sales_pending'] = \App\Models\PosDevice::where('store_id', $this->store->id)
            ->sum('pending_sales_count');

        // Merge new meta with existing to preserve fields from other sync paths
        $existingMeta = StoreAggregate::where('store_id', $this->store->id)->value('meta') ?? [];

        StoreAggregate::updateOrCreate(
            ['store_id' => $this->store->id],
            [
                'tenant_database'    => $this->store->database()->getName(),
                'branches_count'     => $branchesCount,
                'active_users_count' => $activeUsersCount,
                'total_revenue'      => $totalRevenue,
                'month_revenue'      => $monthRevenue,
                'today_revenue'      => $todayRevenue,
                'last_payment_at'    => $lastSaleAt,
                'last_synced_at'     => now(),
                'meta'               => array_merge(
                    is_array($existingMeta) ? $existingMeta : [],
                    $meta
                ),
            ]
        );
    }
}
