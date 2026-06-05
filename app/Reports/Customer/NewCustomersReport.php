<?php

namespace App\Reports\Customer;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;

class NewCustomersReport extends BaseReport
{
    public function getName(): string { return 'New Customers'; }
    public function getCategory(): string { return 'customer'; }
    public function getDescription(): string { return 'Customer acquisition: new sign-ups and first purchases.'; }
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

        return $this->remember('new-customers', $filters, function () use ($filters) {
            [$start, $end] = $this->parseDateRange($filters);
            $branchId = $this->branchId($filters);

            $rows = DB::table('customers as c')
                ->leftJoin('customer_groups as cg', 'cg.id', '=', 'c.customer_group_id')
                ->whereNull('c.deleted_at')
                ->whereBetween('c.created_at', [$start, $end])
                ->selectRaw("
                    c.id, c.name, c.code, c.phone, c.email,
                    COALESCE(cg.name,'No Group') as group_name,
                    c.created_at as registered_at,
                    c.lifetime_value,
                    c.total_purchases_count,
                    c.first_purchase_at
                ")
                ->orderByDesc('c.created_at')
                ->get()
                ->map(fn($r) => [
                    'customer_name'      => $r->name,
                    'code'               => $r->code,
                    'phone'              => $r->phone ?? '—',
                    'group_name'         => $r->group_name,
                    'registered_at'      => date('Y-m-d', strtotime($r->registered_at)),
                    'first_purchase'     => $r->first_purchase_at ?? '—',
                    'lifetime_value'     => round((float) $r->lifetime_value, 2),
                    'purchases_count'    => (int) $r->total_purchases_count,
                ]);

            // Daily new customer trend
            $daily = DB::table('customers')
                ->whereNull('deleted_at')
                ->whereBetween('created_at', [$start, $end])
                ->groupByRaw("DATE(created_at)")
                ->selectRaw("DATE(created_at) as date, COUNT(*) as count")
                ->orderBy('date')
                ->get();

            $chartData = [
                'type'   => 'bar',
                'labels' => $daily->pluck('date')->map(fn($d) => date('d M', strtotime($d)))->all(),
                'series' => [['name'=>'New Customers','data'=>$daily->pluck('count')->map(fn($v) => (int)$v)->all()]],
            ];

            $purchasedCount = $rows->filter(fn($r) => $r['purchases_count'] > 0)->count();

            return new ReportResult(
                summary: [
                    $this->card('New Customers',        $rows->count(), 'int'),
                    $this->card('Made a Purchase',      $purchasedCount, 'int'),
                    $this->card('Conversion Rate',      $rows->count() > 0 ? round($purchasedCount / $rows->count() * 100, 1) : 0, 'pct'),
                    $this->card('Total Revenue (New)',  $rows->sum('lifetime_value'), 'money'),
                ],
                rows: $rows,
                columns: [
                    ['key'=>'customer_name',  'label'=>'Customer',        'type'=>'string'],
                    ['key'=>'group_name',     'label'=>'Group',           'type'=>'string'],
                    ['key'=>'registered_at',  'label'=>'Registered',      'type'=>'date'],
                    ['key'=>'first_purchase', 'label'=>'First Purchase',  'type'=>'string'],
                    ['key'=>'purchases_count','label'=>'# Purchases',     'type'=>'int',  'align'=>'right'],
                    ['key'=>'lifetime_value', 'label'=>'LTV',             'type'=>'money','align'=>'right','total'=>true],
                ],
                totals: ['lifetime_value'=>round($rows->sum('lifetime_value'),2)],
                chart_data: $chartData,
                meta: $this->buildMeta($filters, $start, $end, $rows->count()),
            );
        });
    }
}
