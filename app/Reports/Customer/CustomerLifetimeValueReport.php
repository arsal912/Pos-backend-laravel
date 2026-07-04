<?php

namespace App\Reports\Customer;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;

class CustomerLifetimeValueReport extends BaseReport
{
    public function getName(): string { return 'Customer Lifetime Value'; }
    public function getCategory(): string { return 'customer'; }
    public function getDescription(): string { return 'Top customers by total spend — your most valuable relationships.'; }
    public function getRequiredModule(): ?string { return 'customer-reports'; }

    public function getFilterSchema(): array
    {
        return [
            ['key'=>'top_n',   'type'=>'number','label'=>'Top N Customers','default'=>100],
            ['key'=>'group_id','type'=>'select','label'=>'Customer Group','options'=>[]],
        ];
    }

    public function getDefaultFilters(): array { return ['top_n'=>100,'group_id'=>null]; }

    public function run(array $filters): ReportResult
    {
        $filters = array_merge($this->getDefaultFilters(), $filters);
        $topN    = max(1, (int)($filters['top_n'] ?? 100));
        $groupId = $filters['group_id'] ?? null;

        return $this->remember('customer-ltv', $filters, function () use ($filters, $topN, $groupId) {
            $rows = DB::table('customers as c')
                ->leftJoin('customer_groups as cg', 'cg.id', '=', 'c.customer_group_id')
                ->whereNull('c.deleted_at')
                ->where('c.total_purchases_count', '>', 0)
                ->when($groupId, fn($q) => $q->where('c.customer_group_id', $groupId))
                ->selectRaw("
                    c.name, c.code, c.phone,
                    COALESCE(cg.name,'No Group') as group_name,
                    c.lifetime_value,
                    c.total_purchases_count,
                    c.last_purchase_at,
                    c.created_at as customer_since,
                    c.loyalty_points_balance,
                    c.outstanding_balance
                ")
                ->orderByDesc('c.lifetime_value')
                ->limit($topN)
                ->get()
                ->map(function ($r) {
                    $daysSince = $r->customer_since ? now()->diffInDays($r->customer_since) : 0;
                    $avgOrder  = $r->total_purchases_count > 0
                        ? round((float) $r->lifetime_value / (int) $r->total_purchases_count, 2)
                        : 0;
                    return [
                        'customer_name'    => $r->name,
                        'code'             => $r->code,
                        'phone'            => $r->phone ?? '—',
                        'group_name'       => $r->group_name,
                        'lifetime_value'   => round((float) $r->lifetime_value, 2),
                        'purchase_count'   => (int) $r->total_purchases_count,
                        'avg_order_value'  => $avgOrder,
                        'last_purchase'    => $r->last_purchase_at ?? '—',
                        'customer_age_days'=> $daysSince,
                        'loyalty_points'   => round((float) $r->loyalty_points_balance, 0),
                        'credit_owed'      => round((float) $r->outstanding_balance, 2),
                    ];
                });

            $top10 = $rows->take(10);
            $chartData = [
                'type'   => 'bar',
                'labels' => $top10->pluck('customer_name')->map(fn($n) => mb_substr($n, 0, 20))->all(),
                'series' => [['name'=>'Lifetime Value','data'=>$top10->pluck('lifetime_value')->all()]],
            ];

            return new ReportResult(
                summary: [
                    $this->card('Total LTV (Top ' . $topN . ')', $rows->sum('lifetime_value'), 'money'),
                    $this->card('Top Customer',                   $rows->first()['customer_name'] ?? '—', 'string'),
                    $this->card('Top Customer LTV',               $rows->first()['lifetime_value'] ?? 0, 'money'),
                    $this->card('Avg Purchases/Customer',         $rows->count() > 0 ? round($rows->avg('purchase_count'), 1) : 0, 'int'),
                ],
                rows: $rows,
                columns: [
                    ['key'=>'customer_name',   'label'=>'Customer',      'type'=>'string'],
                    ['key'=>'group_name',      'label'=>'Group',         'type'=>'string'],
                    ['key'=>'lifetime_value',  'label'=>'Total Spend',   'type'=>'money','align'=>'right','total'=>true],
                    ['key'=>'purchase_count',  'label'=>'# Purchases',   'type'=>'int',  'align'=>'right'],
                    ['key'=>'avg_order_value', 'label'=>'Avg Order',     'type'=>'money','align'=>'right'],
                    ['key'=>'last_purchase',   'label'=>'Last Purchase', 'type'=>'string'],
                    ['key'=>'customer_age_days','label'=>'Days as Customer','type'=>'int','align'=>'right'],
                    ['key'=>'loyalty_points',  'label'=>'Points',        'type'=>'int',  'align'=>'right'],
                ],
                totals: ['lifetime_value'=>round($rows->sum('lifetime_value'),2),'purchase_count'=>$rows->sum('purchase_count')],
                chart_data: $chartData,
                meta: $this->buildMeta($filters, now(), now(), $rows->count()),
            );
        });
    }
}
