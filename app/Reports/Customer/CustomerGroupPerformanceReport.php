<?php

namespace App\Reports\Customer;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CustomerGroupPerformanceReport extends BaseReport
{
    public function getName(): string { return 'Group Performance'; }
    public function getCategory(): string { return 'customer'; }
    public function getDescription(): string { return 'Revenue, LTV, loyalty, and credit breakdown by customer group.'; }
    public function getRequiredModule(): ?string { return 'customer-reports'; }

    public function getFilterSchema(): array
    {
        return [
            ['key'=>'date_range','type'=>'date_range','label'=>'Date Range','default'=>'this_month','required'=>true],
            ['key'=>'branch_id', 'type'=>'branch_select','label'=>'Branch'],
        ];
    }

    public function run(array $filters): ReportResult
    {
        $filters = array_merge($this->getDefaultFilters(), $filters);

        return $this->remember('group-performance', $filters, function () use ($filters) {
            [$start, $end] = $this->parseDateRange($filters);
            $branchId = $this->branchId($filters);

            // Customer counts and credit/loyalty aggregates per group
            $groupStats = DB::table('customers as c')
                ->leftJoin('customer_groups as cg', 'cg.id', '=', 'c.customer_group_id')
                ->whereNull('c.deleted_at')
                ->groupBy('cg.id', 'cg.name')
                ->selectRaw("
                    COALESCE(cg.name,'No Group') as group_name,
                    COUNT(*) as customer_count,
                    SUM(c.lifetime_value) as total_ltv,
                    AVG(c.lifetime_value) as avg_ltv,
                    SUM(c.loyalty_points_balance) as total_points,
                    AVG(c.loyalty_points_balance) as avg_points,
                    SUM(c.outstanding_balance) as total_credit
                ")
                ->get()
                ->keyBy('group_name');

            // Period revenue per group
            $periodRevenue = DB::table('sales as s')
                ->join('customers as c', 'c.id', '=', 's.customer_id')
                ->leftJoin('customer_groups as cg', 'cg.id', '=', 'c.customer_group_id')
                ->where('s.status', 'completed')
                ->whereBetween('s.sale_date', [$start->toDateString(), $end->toDateString()])
                ->when($branchId, fn($q) => $q->where('s.branch_id', $branchId))
                ->groupByRaw("COALESCE(cg.name,'No Group')")
                ->selectRaw("COALESCE(cg.name,'No Group') as group_name, COUNT(*) as txns, SUM(s.total) as revenue")
                ->pluck('revenue', 'group_name');

            $txnByGroup = DB::table('sales as s')
                ->join('customers as c', 'c.id', '=', 's.customer_id')
                ->leftJoin('customer_groups as cg', 'cg.id', '=', 'c.customer_group_id')
                ->where('s.status', 'completed')
                ->whereBetween('s.sale_date', [$start->toDateString(), $end->toDateString()])
                ->when($branchId, fn($q) => $q->where('s.branch_id', $branchId))
                ->groupByRaw("COALESCE(cg.name,'No Group')")
                ->selectRaw("COALESCE(cg.name,'No Group') as group_name, COUNT(*) as txns")
                ->pluck('txns', 'group_name');

            $rows = $groupStats->map(function ($g) use ($periodRevenue, $txnByGroup) {
                $groupName = $g->group_name;
                $revenue   = (float) ($periodRevenue[$groupName] ?? 0);
                $txns      = (int) ($txnByGroup[$groupName] ?? 0);
                $customers = (int) $g->customer_count;
                return [
                    'group_name'         => $groupName,
                    'customer_count'     => $customers,
                    'period_revenue'     => round($revenue, 2),
                    'period_txn_count'   => $txns,
                    'avg_txn_per_customer'=> $customers > 0 ? round($txns / $customers, 1) : 0,
                    'total_ltv'          => round((float) $g->total_ltv, 2),
                    'avg_ltv'            => round((float) $g->avg_ltv, 2),
                    'total_points'       => round((float) $g->total_points, 0),
                    'avg_points'         => round((float) $g->avg_points, 0),
                    'total_credit_owed'  => round((float) $g->total_credit, 2),
                ];
            })->sortByDesc('period_revenue')->values();

            $chartData = [
                'type'   => 'bar',
                'labels' => $rows->pluck('group_name')->all(),
                'series' => [
                    ['name'=>'Period Revenue','data'=>$rows->pluck('period_revenue')->all()],
                    ['name'=>'Total LTV',     'data'=>$rows->pluck('total_ltv')->all()],
                ],
            ];

            return new ReportResult(
                summary: [
                    $this->card('Customer Groups',   $rows->count(), 'int'),
                    $this->card('Period Revenue',    $rows->sum('period_revenue'), 'money'),
                    $this->card('Total LTV (All)',   $rows->sum('total_ltv'), 'money'),
                    $this->card('Total Credit Owed', $rows->sum('total_credit_owed'), 'money'),
                ],
                rows: $rows,
                columns: [
                    ['key'=>'group_name',          'label'=>'Group',          'type'=>'string'],
                    ['key'=>'customer_count',       'label'=>'Customers',     'type'=>'int',  'align'=>'right','total'=>true],
                    ['key'=>'period_revenue',       'label'=>'Period Revenue','type'=>'money','align'=>'right','total'=>true],
                    ['key'=>'period_txn_count',     'label'=>'Transactions',  'type'=>'int',  'align'=>'right','total'=>true],
                    ['key'=>'avg_ltv',              'label'=>'Avg LTV',       'type'=>'money','align'=>'right'],
                    ['key'=>'avg_points',           'label'=>'Avg Points',    'type'=>'int',  'align'=>'right'],
                    ['key'=>'total_credit_owed',    'label'=>'Credit Owed',   'type'=>'money','align'=>'right','total'=>true],
                ],
                totals: [
                    'customer_count'   => $rows->sum('customer_count'),
                    'period_revenue'   => round($rows->sum('period_revenue'),2),
                    'period_txn_count' => $rows->sum('period_txn_count'),
                    'total_credit_owed'=> round($rows->sum('total_credit_owed'),2),
                ],
                chart_data: $chartData,
                meta: $this->buildMeta($filters, $start, $end, $rows->count()),
            );
        });
    }
}
