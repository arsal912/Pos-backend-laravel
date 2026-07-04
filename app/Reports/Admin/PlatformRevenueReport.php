<?php

namespace App\Reports\Admin;

use App\DTOs\ReportResult;
use Illuminate\Support\Facades\DB;

class PlatformRevenueReport extends BaseAdminReport
{
    public function getName(): string { return 'Platform Revenue'; }
    public function getDescription(): string { return 'Total revenue across all stores by period, plan, and store.'; }

    public function getFilterSchema(): array
    {
        return [
            ['key'=>'date_range','type'=>'date_range','label'=>'Date Range','default'=>'this_month','required'=>true],
        ];
    }

    public function run(array $filters): ReportResult
    {
        $filters = array_merge($this->getDefaultFilters(), $filters);

        return $this->remember('admin-platform-revenue', $filters, function () use ($filters) {
            [$start, $end] = $this->parseDateRange($filters);

            // Revenue by store from store_aggregates
            $storeRevenue = $this->centralDb()->table('store_aggregates as sa')
                ->join('stores as s', 's.id', '=', 'sa.store_id')
                ->leftJoin('subscriptions as sub', function ($j) {
                    $j->on('sub.store_id', '=', 's.id')->where('sub.status', 'active');
                })
                ->leftJoin('plans as p', 'p.id', '=', 'sub.plan_id')
                ->select(
                    's.id as store_id',
                    's.name as store_name',
                    's.status as store_status',
                    DB::raw("COALESCE(p.name,'No Plan') as plan_name"),
                    'sa.today_revenue',
                    'sa.month_revenue',
                    'sa.total_revenue',
                    DB::raw("JSON_UNQUOTE(JSON_EXTRACT(sa.meta,'$.sales_today_count')) as sales_today"),
                    'sa.last_synced_at',
                )
                ->orderByDesc('sa.month_revenue')
                ->get()
                ->map(fn($r) => [
                    'store_name'    => $r->store_name,
                    'status'        => $r->store_status,
                    'plan_name'     => $r->plan_name,
                    'today_revenue' => round((float) $r->today_revenue, 2),
                    'month_revenue' => round((float) $r->month_revenue, 2),
                    'total_revenue' => round((float) $r->total_revenue, 2),
                    'sales_today'   => (int) ($r->sales_today ?? 0),
                    'last_synced'   => $r->last_synced_at ? date('d M H:i', strtotime($r->last_synced_at)) : '—',
                ]);

            // Revenue by plan
            $byPlan = $storeRevenue->groupBy('plan_name')
                ->map(fn($g, $plan) => [
                    'plan_name'     => $plan,
                    'store_count'   => $g->count(),
                    'month_revenue' => round($g->sum('month_revenue'), 2),
                    'total_revenue' => round($g->sum('total_revenue'), 2),
                ])->sortByDesc('month_revenue')->values();

            // Monthly billing revenue from payments table
            $billingRevenue = $this->centralDb()->table('payments')
                ->where('status', 'completed')
                ->whereBetween('paid_at', [$start, $end])
                ->selectRaw("DATE_FORMAT(paid_at,'%Y-%m') as month, SUM(amount) as revenue, COUNT(*) as payments")
                ->groupByRaw("DATE_FORMAT(paid_at,'%Y-%m')")
                ->orderByRaw("DATE_FORMAT(paid_at,'%Y-%m')")
                ->get();

            $chartData = [
                'type'   => 'bar',
                'labels' => $billingRevenue->pluck('month')->all(),
                'series' => [['name'=>'Subscription Revenue','data'=>$billingRevenue->pluck('revenue')->map(fn($v)=>round((float)$v,2))->all()]],
            ];

            $totalMonthRevenue = round($storeRevenue->sum('month_revenue'), 2);
            $totalAllRevenue   = round($storeRevenue->sum('total_revenue'), 2);

            return new ReportResult(
                summary: [
                    $this->card('Active Stores',      $storeRevenue->filter(fn($r)=>$r['status']==='active')->count(), 'int'),
                    $this->card('Total MRR (Billing)', $this->centralDb()->table('payments')->where('status','completed')->whereMonth('paid_at',now()->month)->whereYear('paid_at',now()->year)->sum('amount'), 'money'),
                    $this->card('Platform Revenue (Month)',$totalMonthRevenue, 'money'),
                    $this->card('Platform Revenue (All-Time)',$totalAllRevenue,'money'),
                ],
                rows: $storeRevenue,
                columns: [
                    ['key'=>'store_name',    'label'=>'Store',          'type'=>'string'],
                    ['key'=>'plan_name',     'label'=>'Plan',           'type'=>'string'],
                    ['key'=>'status',        'label'=>'Status',         'type'=>'string'],
                    ['key'=>'today_revenue', 'label'=>'Today',          'type'=>'money','align'=>'right'],
                    ['key'=>'month_revenue', 'label'=>'This Month',     'type'=>'money','align'=>'right','total'=>true],
                    ['key'=>'total_revenue', 'label'=>'All-Time',       'type'=>'money','align'=>'right','total'=>true],
                    ['key'=>'sales_today',   'label'=>'Sales Today',    'type'=>'int',  'align'=>'right'],
                    ['key'=>'last_synced',   'label'=>'Last Synced',    'type'=>'string'],
                ],
                totals: ['month_revenue'=>$totalMonthRevenue,'total_revenue'=>$totalAllRevenue],
                chart_data: $chartData,
                meta: $this->buildMeta($filters, $start, $end, $storeRevenue->count()),
            );
        });
    }
}
