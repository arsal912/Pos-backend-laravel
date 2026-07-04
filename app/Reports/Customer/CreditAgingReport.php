<?php

namespace App\Reports\Customer;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreditAgingReport extends BaseReport
{
    public function getName(): string { return 'Credit Aging'; }
    public function getCategory(): string { return 'customer'; }
    public function getDescription(): string { return 'Exportable credit aging report by customer and bucket.'; }
    public function getRequiredModule(): ?string { return 'customer-credit'; }

    public function getFilterSchema(): array
    {
        return [
            ['key'=>'group_id','type'=>'select','label'=>'Customer Group','options'=>[]],
        ];
    }

    public function getDefaultFilters(): array { return ['group_id'=>null]; }

    public function run(array $filters): ReportResult
    {
        $filters = array_merge($this->getDefaultFilters(), $filters);
        $groupId = $filters['group_id'] ?? null;

        return $this->remember('credit-aging', $filters, function () use ($filters, $groupId) {
            if (! Schema::hasTable('credit_transactions')) {
                return new ReportResult(
                    summary: [$this->card('Note','No credit transactions yet','string')],
                    meta: $this->buildMeta($filters, now(), now(), 0),
                );
            }

            $customers = DB::table('customers as c')
                ->leftJoin('customer_groups as cg', 'cg.id', '=', 'c.customer_group_id')
                ->whereNull('c.deleted_at')
                ->where('c.outstanding_balance', '>', 0)
                ->when($groupId, fn($q) => $q->where('c.customer_group_id', $groupId))
                ->select('c.id', 'c.name', 'c.code', 'c.phone', 'c.outstanding_balance', 'c.credit_limit',
                    DB::raw("COALESCE(cg.name,'No Group') as group_name"))
                ->orderByDesc('c.outstanding_balance')
                ->get();

            $now = now();
            $bucketNames = ['current (0-30d)', '31-60d', '61-90d', '90+ days'];
            $totals = array_fill_keys($bucketNames, 0.0);

            $rows = $customers->map(function ($c) use ($now, &$totals) {
                // Get oldest unpaid sales from credit_transactions
                $charges = DB::table('credit_transactions')
                    ->where('customer_id', $c->id)
                    ->where('type', 'sale_on_credit')
                    ->where('amount', '>', 0)
                    ->select('amount', 'created_at')
                    ->orderBy('created_at')
                    ->get();

                $buckets = ['current (0-30d)'=>0.0,'31-60d'=>0.0,'61-90d'=>0.0,'90+ days'=>0.0];

                foreach ($charges as $charge) {
                    $days = $now->diffInDays($charge->created_at);
                    $bucket = match (true) {
                        $days <= 30  => 'current (0-30d)',
                        $days <= 60  => '31-60d',
                        $days <= 90  => '61-90d',
                        default      => '90+ days',
                    };
                    $buckets[$bucket] += (float) $charge->amount;
                    $totals[$bucket]  += (float) $charge->amount;
                }

                return [
                    'customer_name'    => $c->name,
                    'code'             => $c->code,
                    'phone'            => $c->phone ?? '—',
                    'group_name'       => $c->group_name,
                    'total_outstanding'=> round((float) $c->outstanding_balance, 2),
                    'credit_limit'     => $c->credit_limit !== null ? round((float) $c->credit_limit, 2) : 'Unlimited',
                    'current_0_30'     => round($buckets['current (0-30d)'], 2),
                    'days_31_60'       => round($buckets['31-60d'], 2),
                    'days_61_90'       => round($buckets['61-90d'], 2),
                    'days_90_plus'     => round($buckets['90+ days'], 2),
                ];
            });

            $grandTotal = round($rows->sum('total_outstanding'), 2);

            // Aging bucket chart
            $chartData = [
                'type'   => 'bar',
                'labels' => array_keys($totals),
                'series' => [['name'=>'Amount','data'=>array_map(fn($v) => round($v,2), array_values($totals))]],
            ];

            return new ReportResult(
                summary: [
                    $this->card('Total Outstanding', $grandTotal, 'money'),
                    $this->card('Customers with Debt', $rows->count(), 'int'),
                    $this->card('90+ Days',  round($totals['90+ days'], 2), 'money'),
                ],
                rows: $rows,
                columns: [
                    ['key'=>'customer_name',   'label'=>'Customer',    'type'=>'string'],
                    ['key'=>'group_name',      'label'=>'Group',       'type'=>'string'],
                    ['key'=>'total_outstanding','label'=>'Total Owed', 'type'=>'money','align'=>'right','total'=>true],
                    ['key'=>'credit_limit',    'label'=>'Limit',       'type'=>'string','align'=>'right'],
                    ['key'=>'current_0_30',    'label'=>'0-30d',       'type'=>'money','align'=>'right','total'=>true],
                    ['key'=>'days_31_60',      'label'=>'31-60d',      'type'=>'money','align'=>'right','total'=>true],
                    ['key'=>'days_61_90',      'label'=>'61-90d',      'type'=>'money','align'=>'right','total'=>true],
                    ['key'=>'days_90_plus',    'label'=>'90+d',        'type'=>'money','align'=>'right','total'=>true],
                ],
                totals: [
                    'total_outstanding' => $grandTotal,
                    'current_0_30'      => round($totals['current (0-30d)'], 2),
                    'days_31_60'        => round($totals['31-60d'], 2),
                    'days_61_90'        => round($totals['61-90d'], 2),
                    'days_90_plus'      => round($totals['90+ days'], 2),
                ],
                chart_data: $chartData,
                meta: $this->buildMeta($filters, now(), now(), $rows->count()),
            );
        });
    }
}
