<?php

namespace App\Reports\Sales;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReturnsReport extends BaseReport
{
    public function getName(): string { return 'Returns Analysis'; }
    public function getCategory(): string { return 'sales'; }
    public function getDescription(): string { return 'Return patterns, refund amounts, and common reasons.'; }
    public function getRequiredModule(): ?string { return 'sales-reports'; }

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

        return $this->remember('returns-analysis', $filters, function () use ($filters) {
            [$start, $end] = $this->parseDateRange($filters);
            $branchId = $this->branchId($filters);

            if (! Schema::hasTable('sale_returns')) {
                return new ReportResult(meta: $this->buildMeta($filters, $start, $end, 0));
            }

            $returnsBase = DB::table('sale_returns')
                ->where('status', 'completed')
                ->whereBetween('return_date', [$start->toDateString(), $end->toDateString()])
                ->when($branchId, fn($q) => $q->where('branch_id', $branchId));

            $totalRefunds  = (float) (clone $returnsBase)->sum('refund_amount');
            $returnCount   = (int) (clone $returnsBase)->count();

            // Sales in period for return rate
            $totalSales    = (int) $this->salesBase($start, $end, $branchId)->count();
            $returnRate    = $totalSales > 0 ? round($returnCount / $totalSales * 100, 1) : 0;

            $rows = (clone $returnsBase)
                ->leftJoin('sales as s', 's.id', '=', 'sale_returns.original_sale_id')
                ->leftJoin('customers as c', 'c.id', '=', 'sale_returns.customer_id')
                ->select(
                    'sale_returns.return_number',
                    's.sale_number as original_sale',
                    DB::raw("COALESCE(c.name, 'Walk-in') as customer_name"),
                    'sale_returns.return_date',
                    'sale_returns.refund_amount',
                    'sale_returns.refund_method',
                    DB::raw("COALESCE(sale_returns.reason, '—') as reason"),
                )
                ->orderByDesc('sale_returns.return_date')
                ->limit(200)
                ->get()
                ->map(fn($r) => [
                    'return_number' => $r->return_number,
                    'original_sale' => $r->original_sale ?? '—',
                    'customer_name' => $r->customer_name,
                    'return_date'   => $r->return_date,
                    'refund_amount' => round((float) $r->refund_amount, 2),
                    'refund_method' => ucfirst(str_replace('_', ' ', $r->refund_method ?? '')),
                    'reason'        => $r->reason,
                ]);

            // Daily trend
            $dailyTrend = (clone $returnsBase)
                ->groupBy('return_date')
                ->selectRaw('return_date, COUNT(*) as count, SUM(refund_amount) as amount')
                ->orderBy('return_date')
                ->get();

            $chartData = [
                'type'   => 'bar',
                'labels' => $dailyTrend->pluck('return_date')->map(fn($d) => date('d M', strtotime($d)))->all(),
                'series' => [['name' => 'Refunds', 'data' => $dailyTrend->pluck('amount')->map(fn($v) => round((float)$v,2))->all()]],
            ];

            return new ReportResult(
                summary: [
                    $this->card('Total Refunds',   $totalRefunds, 'money'),
                    $this->card('Return Count',    $returnCount,  'int'),
                    $this->card('Return Rate',     $returnRate,   'pct'),
                    $this->card('Avg Refund',      $returnCount > 0 ? round($totalRefunds / $returnCount, 2) : 0, 'money'),
                ],
                rows: $rows,
                columns: [
                    ['key'=>'return_number','label'=>'Return #',    'type'=>'string'],
                    ['key'=>'original_sale','label'=>'Original Sale','type'=>'string'],
                    ['key'=>'customer_name','label'=>'Customer',    'type'=>'string'],
                    ['key'=>'return_date',  'label'=>'Date',        'type'=>'date'],
                    ['key'=>'refund_amount','label'=>'Refund Amt',  'type'=>'money','align'=>'right','total'=>true],
                    ['key'=>'refund_method','label'=>'Method',      'type'=>'string'],
                    ['key'=>'reason',       'label'=>'Reason',      'type'=>'string'],
                ],
                totals: ['refund_amount' => round($totalRefunds, 2)],
                chart_data: $chartData,
                meta: $this->buildMeta($filters, $start, $end, $rows->count()),
            );
        });
    }
}
