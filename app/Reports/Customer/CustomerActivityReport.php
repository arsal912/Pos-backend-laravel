<?php

namespace App\Reports\Customer;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CustomerActivityReport extends BaseReport
{
    public function getName(): string { return 'Customer Activity'; }
    public function getCategory(): string { return 'customer'; }
    public function getDescription(): string { return 'Customer transaction activity, revenue, and credit status.'; }
    public function getRequiredModule(): ?string { return 'customer-reports'; }

    public function getFilterSchema(): array
    {
        return [
            ['key'=>'date_range',  'type'=>'date_range',   'label'=>'Date Range','default'=>'this_month','required'=>true],
            ['key'=>'branch_id',   'type'=>'branch_select','label'=>'Branch'],
            ['key'=>'group_id',    'type'=>'select',       'label'=>'Customer Group','options'=>[]],
        ];
    }

    public function run(array $filters): ReportResult
    {
        $filters = array_merge($this->getDefaultFilters(), $filters);

        return $this->remember('customer-activity', $filters, function () use ($filters) {
            [$start, $end] = $this->parseDateRange($filters);
            $branchId = $this->branchId($filters);
            $groupId  = $filters['group_id'] ?? null;

            $rows = DB::table('customers as c')
                ->leftJoin('customer_groups as cg', 'cg.id', '=', 'c.customer_group_id')
                ->leftJoin('sales as s', function ($join) use ($start, $end, $branchId) {
                    $join->on('s.customer_id', '=', 'c.id')
                         ->where('s.status', 'completed')
                         ->whereBetween('s.sale_date', [$start->toDateString(), $end->toDateString()]);
                    if ($branchId) $join->where('s.branch_id', $branchId);
                })
                ->whereNull('c.deleted_at')
                ->where('c.is_active', true)
                ->when($groupId, fn($q) => $q->where('c.customer_group_id', $groupId))
                ->groupBy('c.id', 'c.name', 'c.code', 'c.phone', 'c.last_purchase_at', 'c.outstanding_balance', 'c.loyalty_points_balance', 'cg.name')
                ->having(DB::raw('COUNT(s.id)'), '>', 0)
                ->selectRaw("
                    c.id, c.name, c.code, c.phone,
                    COALESCE(cg.name, 'No Group') as group_name,
                    COUNT(s.id) as transactions,
                    SUM(s.total) as revenue,
                    MAX(s.sale_date) as last_purchase,
                    c.outstanding_balance,
                    c.loyalty_points_balance
                ")
                ->orderByDesc('revenue')
                ->get()
                ->map(fn($r) => [
                    'customer_name'       => $r->name,
                    'code'                => $r->code,
                    'phone'               => $r->phone ?? '—',
                    'group_name'          => $r->group_name,
                    'transactions'        => (int) $r->transactions,
                    'revenue'             => round((float) $r->revenue, 2),
                    'last_purchase'       => $r->last_purchase,
                    'outstanding_balance' => round((float) $r->outstanding_balance, 2),
                    'loyalty_points'      => round((float) $r->loyalty_points_balance, 0),
                ]);

            return new ReportResult(
                summary: [
                    $this->card('Active Customers',    $rows->count(), 'int'),
                    $this->card('Total Revenue',       $rows->sum('revenue'), 'money'),
                    $this->card('Total Outstanding',   $rows->sum('outstanding_balance'), 'money'),
                    $this->card('Avg Spend/Customer',  $rows->count() > 0 ? round($rows->avg('revenue'), 2) : 0, 'money'),
                ],
                rows: $rows,
                columns: [
                    ['key'=>'customer_name',      'label'=>'Customer',        'type'=>'string'],
                    ['key'=>'group_name',          'label'=>'Group',          'type'=>'string'],
                    ['key'=>'phone',               'label'=>'Phone',          'type'=>'string'],
                    ['key'=>'transactions',        'label'=>'Transactions',   'type'=>'int',  'align'=>'right','total'=>true],
                    ['key'=>'revenue',             'label'=>'Revenue',        'type'=>'money','align'=>'right','total'=>true],
                    ['key'=>'last_purchase',       'label'=>'Last Purchase',  'type'=>'date'],
                    ['key'=>'outstanding_balance', 'label'=>'Credit Owed',   'type'=>'money','align'=>'right'],
                    ['key'=>'loyalty_points',      'label'=>'Points',         'type'=>'int',  'align'=>'right'],
                ],
                totals: ['transactions'=>$rows->sum('transactions'),'revenue'=>round($rows->sum('revenue'),2)],
                meta: $this->buildMeta($filters, $start, $end, $rows->count()),
            );
        });
    }
}
