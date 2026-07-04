<?php

namespace App\Reports\Sales;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;

class SalesByDayReport extends BaseReport
{
    public function getName(): string { return 'Sales by Day'; }
    public function getCategory(): string { return 'sales'; }
    public function getDescription(): string { return 'Daily transaction and revenue breakdown.'; }
    public function getRequiredModule(): ?string { return 'sales-reports'; }

    public function getFilterSchema(): array
    {
        return [
            ['key'=>'date_range','type'=>'date_range','label'=>'Date Range','default'=>'this_month','required'=>true],
            ['key'=>'branch_id', 'type'=>'branch_select','label'=>'Branch'],
            ['key'=>'group_by',  'type'=>'select','label'=>'Group By','default'=>'day',
             'options'=>[['value'=>'day','label'=>'Day'],['value'=>'week','label'=>'Week'],['value'=>'month','label'=>'Month']]],
        ];
    }

    public function run(array $filters): ReportResult
    {
        $filters = array_merge($this->getDefaultFilters(), $filters);

        return $this->remember('sales-by-day', $filters, function () use ($filters) {
            [$start, $end] = $this->parseDateRange($filters);
            $branchId = $this->branchId($filters);
            $groupBy  = $filters['group_by'] ?? 'day';

            $dateTrunc = match ($groupBy) {
                'week'  => "DATE_FORMAT(sale_date,'%x-W%v')",
                'month' => "DATE_FORMAT(sale_date,'%Y-%m')",
                default => 'sale_date',
            };

            $rows = $this->salesBase($start, $end, $branchId)
                ->selectRaw("
                    {$dateTrunc} as period,
                    COUNT(*) as transactions,
                    SUM(total) as gross_revenue,
                    SUM(tax_amount) as tax,
                    SUM(discount_amount) as discounts
                ")
                ->groupByRaw($dateTrunc)
                ->orderByRaw($dateTrunc)
                ->get();

            // Add items per period
            $itemsByPeriod = DB::table('sale_items')
                ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->where('sales.status', 'completed')
                ->whereBetween('sales.sale_date', [$start->toDateString(), $end->toDateString()])
                ->when($branchId, fn($q) => $q->where('sales.branch_id', $branchId))
                ->selectRaw("{$dateTrunc} as period, SUM(quantity) as items_sold")
                ->groupByRaw($dateTrunc)
                ->pluck('items_sold', 'period');

            // Refunds per period
            $refundsByPeriod = DB::getSchemaBuilder()->hasTable('sale_returns')
                ? DB::table('sale_returns')
                    ->where('status', 'completed')
                    ->whereBetween('return_date', [$start->toDateString(), $end->toDateString()])
                    ->selectRaw("{$dateTrunc} as period, SUM(refund_amount) as refunds")
                    ->groupByRaw($dateTrunc)
                    ->pluck('refunds', 'period')
                : collect();

            $enriched = $rows->map(function ($row) use ($itemsByPeriod, $refundsByPeriod) {
                $txn     = (int) $row->transactions;
                $gross   = (float) $row->gross_revenue;
                $refunds = (float) ($refundsByPeriod[$row->period] ?? 0);
                return [
                    'period'       => $row->period,
                    'transactions' => $txn,
                    'gross_revenue'=> round($gross, 2),
                    'refunds'      => round($refunds, 2),
                    'net_revenue'  => round($gross - $refunds, 2),
                    'items_sold'   => (int) ($itemsByPeriod[$row->period] ?? 0),
                    'avg_ticket'   => $txn > 0 ? round($gross / $txn, 2) : 0,
                    'tax'          => round((float) $row->tax, 2),
                    'discounts'    => round((float) $row->discounts, 2),
                ];
            });

            $totals = [
                'transactions'  => $enriched->sum('transactions'),
                'gross_revenue' => round($enriched->sum('gross_revenue'), 2),
                'refunds'       => round($enriched->sum('refunds'), 2),
                'net_revenue'   => round($enriched->sum('net_revenue'), 2),
                'items_sold'    => $enriched->sum('items_sold'),
                'tax'           => round($enriched->sum('tax'), 2),
                'discounts'     => round($enriched->sum('discounts'), 2),
            ];

            $chartData = [
                'type'   => 'bar',
                'labels' => $enriched->pluck('period')->all(),
                'series' => [
                    ['name' => 'Gross Revenue', 'data' => $enriched->pluck('gross_revenue')->all()],
                    ['name' => 'Net Revenue',   'data' => $enriched->pluck('net_revenue')->all()],
                ],
            ];

            return new ReportResult(
                summary: [
                    $this->card('Total Revenue',      $totals['gross_revenue'], 'money'),
                    $this->card('Total Transactions', $totals['transactions'],  'int'),
                    $this->card('Total Refunds',       $totals['refunds'],       'money'),
                    $this->card('Net Revenue',         $totals['net_revenue'],   'money'),
                ],
                rows: $enriched,
                columns: [
                    ['key'=>'period',       'label'=>'Period',      'type'=>'string'],
                    ['key'=>'transactions', 'label'=>'Transactions','type'=>'int',   'align'=>'right','total'=>true],
                    ['key'=>'gross_revenue','label'=>'Gross Rev',   'type'=>'money', 'align'=>'right','total'=>true],
                    ['key'=>'refunds',      'label'=>'Refunds',     'type'=>'money', 'align'=>'right','total'=>true],
                    ['key'=>'net_revenue',  'label'=>'Net Revenue', 'type'=>'money', 'align'=>'right','total'=>true],
                    ['key'=>'items_sold',   'label'=>'Items Sold',  'type'=>'int',   'align'=>'right','total'=>true],
                    ['key'=>'avg_ticket',   'label'=>'Avg Ticket',  'type'=>'money', 'align'=>'right'],
                    ['key'=>'tax',          'label'=>'Tax',         'type'=>'money', 'align'=>'right','total'=>true],
                    ['key'=>'discounts',    'label'=>'Discounts',   'type'=>'money', 'align'=>'right','total'=>true],
                ],
                totals: $totals,
                chart_data: $chartData,
                meta: $this->buildMeta($filters, $start, $end, $enriched->count()),
            );
        });
    }
}
