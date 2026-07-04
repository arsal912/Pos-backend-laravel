<?php

namespace App\Reports\Sales;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;

class SalesByCustomerReport extends BaseReport
{
    public function getName(): string { return 'Sales by Customer'; }
    public function getCategory(): string { return 'customer'; }
    public function getDescription(): string { return 'Top customers by revenue — identify your VIPs.'; }
    public function getRequiredModule(): ?string { return 'customer-reports'; }

    public function getFilterSchema(): array
    {
        return [
            ['key'=>'date_range','type'=>'date_range','label'=>'Date Range','default'=>'this_month','required'=>true],
            ['key'=>'branch_id', 'type'=>'branch_select','label'=>'Branch'],
            ['key'=>'top_n',     'type'=>'number','label'=>'Top N','default'=>50],
        ];
    }

    public function run(array $filters): ReportResult
    {
        $filters = array_merge($this->getDefaultFilters(), $filters);

        return $this->remember('sales-by-customer', $filters, function () use ($filters) {
            [$start, $end] = $this->parseDateRange($filters);
            $branchId = $this->branchId($filters);
            $topN     = (int) ($filters['top_n'] ?? 50);

            $rows = $this->salesBase($start, $end, $branchId)
                ->whereNotNull('customer_id')
                ->join('customers as c', 'c.id', '=', 'sales.customer_id')
                ->leftJoin('customer_groups as cg', 'cg.id', '=', 'c.customer_group_id')
                ->groupBy('sales.customer_id', 'c.name', 'c.code', 'c.phone', 'cg.name', 'c.loyalty_points_balance')
                ->selectRaw("
                    c.name as customer_name,
                    c.code as customer_code,
                    c.phone as customer_phone,
                    COALESCE(cg.name, 'No Group') as group_name,
                    COUNT(*) as transactions,
                    SUM(sales.total) as total_spend,
                    AVG(sales.total) as avg_ticket,
                    MAX(sales.sale_date) as last_purchase,
                    c.loyalty_points_balance as loyalty_points
                ")
                ->orderByDesc('total_spend')
                ->limit($topN)
                ->get()
                ->map(fn($r) => [
                    'customer_name'  => $r->customer_name,
                    'customer_code'  => $r->customer_code,
                    'customer_phone' => $r->customer_phone ?? '—',
                    'group_name'     => $r->group_name,
                    'transactions'   => (int) $r->transactions,
                    'total_spend'    => round((float) $r->total_spend, 2),
                    'avg_ticket'     => round((float) $r->avg_ticket, 2),
                    'last_purchase'  => $r->last_purchase,
                    'loyalty_points' => round((float) $r->loyalty_points, 0),
                ]);

            return new ReportResult(
                summary: [
                    $this->card('Total Revenue',  $rows->sum('total_spend'), 'money'),
                    $this->card('Unique Customers',$rows->count(), 'int'),
                    $this->card('Avg Spend/Customer', $rows->avg('total_spend') ?? 0, 'money'),
                ],
                rows: $rows,
                columns: [
                    ['key'=>'customer_name', 'label'=>'Customer',     'type'=>'string'],
                    ['key'=>'group_name',    'label'=>'Group',        'type'=>'string'],
                    ['key'=>'transactions',  'label'=>'Transactions', 'type'=>'int',  'align'=>'right','total'=>true],
                    ['key'=>'total_spend',   'label'=>'Total Spend',  'type'=>'money','align'=>'right','total'=>true],
                    ['key'=>'avg_ticket',    'label'=>'Avg Ticket',   'type'=>'money','align'=>'right'],
                    ['key'=>'last_purchase', 'label'=>'Last Purchase','type'=>'date'],
                    ['key'=>'loyalty_points','label'=>'Points',       'type'=>'int',  'align'=>'right'],
                ],
                totals: ['transactions'=>$rows->sum('transactions'),'total_spend'=>round($rows->sum('total_spend'),2)],
                meta: $this->buildMeta($filters, $start, $end, $rows->count()),
            );
        });
    }
}
