<?php

namespace App\Reports\Sales;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;

class SalesSummaryReport extends BaseReport
{
    public function getName(): string { return 'Sales Summary'; }
    public function getCategory(): string { return 'sales'; }
    public function getDescription(): string { return 'Key sales KPIs and revenue overview for a period.'; }
    public function getRequiredModule(): ?string { return 'sales-reports'; }

    public function getFilterSchema(): array
    {
        return [
            ['key'=>'date_range','type'=>'date_range','label'=>'Date Range','default'=>'this_month','required'=>true],
            ['key'=>'branch_id', 'type'=>'branch_select','label'=>'Branch'],
            ['key'=>'compare',   'type'=>'select','label'=>'Compare','default'=>false,
             'options'=>[['value'=>'0','label'=>'No comparison'],['value'=>'1','label'=>'vs Previous Period']]],
        ];
    }

    public function run(array $filters): ReportResult
    {
        $filters = array_merge($this->getDefaultFilters(), $filters);

        return $this->remember('sales-summary', $filters, function () use ($filters) {
            [$start, $end] = $this->parseDateRange($filters);
            $branchId = $this->branchId($filters);

            $stats = $this->fetchStats($start, $end, $branchId);

            // Previous period for comparison
            $comparison = null;
            if (filter_var($filters['compare'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                [$prevStart, $prevEnd] = $this->previousPeriod($start, $end);
                $prevStats  = $this->fetchStats($prevStart, $prevEnd, $branchId);
                $comparison = new ReportResult(
                    summary: $this->buildSummaryCards($prevStats),
                    meta: $this->buildMeta($filters, $prevStart, $prevEnd, 0),
                );
            }

            // Daily chart
            $chartRows = $this->salesBase($start, $end, $branchId)
                ->groupBy('sale_date')
                ->selectRaw('sale_date, COUNT(*) as txn, SUM(total) as revenue')
                ->orderBy('sale_date')
                ->get();

            $chartData = [
                'type'   => 'line',
                'labels' => $chartRows->pluck('sale_date')->map(fn($d) => date('d M', strtotime($d)))->all(),
                'series' => [
                    ['name' => 'Revenue', 'data' => $chartRows->pluck('revenue')->map(fn($v) => round((float)$v, 2))->all()],
                ],
            ];

            return new ReportResult(
                summary:    $this->buildSummaryCards($stats),
                rows:       collect(),
                chart_data: $chartData,
                comparison: $comparison,
                meta:       $this->buildMeta($filters, $start, $end, 0),
            );
        });
    }

    private function fetchStats($start, $end, ?int $branchId): array
    {
        $base = $this->salesBase($start, $end, $branchId);

        $revenue    = (float) (clone $base)->sum('total');
        $txnCount   = (int)   (clone $base)->count();
        $tax        = (float) (clone $base)->sum('tax_amount');
        $discounts  = (float) (clone $base)->sum('discount_amount');

        // Items sold from sale_items
        $itemsSold = (float) DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', 'completed')
            ->whereBetween('sales.sale_date', [$start->toDateString(), $end->toDateString()])
            ->when($branchId, fn($q) => $q->where('sales.branch_id', $branchId))
            ->sum('sale_items.quantity');

        // Refunds
        $refunds = DB::getSchemaBuilder()->hasTable('sale_returns')
            ? (float) DB::table('sale_returns')
                ->where('status', 'completed')
                ->whereBetween('return_date', [$start->toDateString(), $end->toDateString()])
                ->sum('refund_amount')
            : 0;

        $aov = $txnCount > 0 ? round($revenue / $txnCount, 2) : 0;

        return compact('revenue', 'txnCount', 'tax', 'discounts', 'itemsSold', 'refunds', 'aov');
    }

    private function buildSummaryCards(array $s): array
    {
        return [
            $this->card('Gross Revenue',      $s['revenue'],   'money'),
            $this->card('Total Transactions', $s['txnCount'],  'int'),
            $this->card('Average Order Value',$s['aov'],       'money'),
            $this->card('Items Sold',         $s['itemsSold'], 'int'),
            $this->card('Tax Collected',      $s['tax'],       'money'),
            $this->card('Discounts Given',    $s['discounts'], 'money'),
            $this->card('Refunds',            $s['refunds'],   'money'),
            $this->card('Net Revenue',        $s['revenue'] - $s['refunds'], 'money'),
        ];
    }
}
