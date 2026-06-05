<?php

namespace App\Reports\Tax;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;

/**
 * Tax Collected Report
 *
 * Breaks down tax collected by date and tax rate for a period.
 * Used for monthly sales tax filing prep (FBR, provincial taxes, etc.).
 *
 * COMPLIANCE NOTE:
 * FBR live POS integration (mandatory for tier-1 retailers under
 * SRO 1006(I)/2021) is a separate compliance project not included here.
 * This reporting suite provides the data needed for offline tax filing.
 * For FBR IRIS API access see: https://e.fbr.gov.pk
 */
class TaxCollectedReport extends BaseReport
{
    public function getName(): string { return 'Tax Collected'; }
    public function getCategory(): string { return 'tax'; }
    public function getDescription(): string { return 'Tax collected by date and rate — monthly filing prep.'; }
    public function getRequiredModule(): ?string { return 'tax-reports'; }

    public function getFilterSchema(): array
    {
        return [
            ['key'=>'date_range','type'=>'date_range','label'=>'Date Range','default'=>'this_month','required'=>true],
            ['key'=>'branch_id', 'type'=>'branch_select','label'=>'Branch'],
            ['key'=>'group_by',  'type'=>'select','label'=>'Group By','default'=>'day',
             'options'=>[
                ['value'=>'day',  'label'=>'Day'],
                ['value'=>'week', 'label'=>'Week'],
                ['value'=>'month','label'=>'Month'],
             ]],
        ];
    }

    public function run(array $filters): ReportResult
    {
        $filters = array_merge($this->getDefaultFilters(), $filters);

        return $this->remember('tax-collected', $filters, function () use ($filters) {
            [$start, $end] = $this->parseDateRange($filters);
            $branchId = $this->branchId($filters);
            $groupBy  = $filters['group_by'] ?? 'day';

            $dateTrunc = match ($groupBy) {
                'week'  => "DATE_FORMAT(s.sale_date,'%x-W%v')",
                'month' => "DATE_FORMAT(s.sale_date,'%Y-%m')",
                default => 's.sale_date',
            };

            // Tax breakdown by period and tax rate
            $rows = DB::table('sale_items as si')
                ->join('sales as s', 's.id', '=', 'si.sale_id')
                ->leftJoin('tax_rates as tr', 'tr.id', '=', DB::raw(
                    '(SELECT id FROM tax_rates WHERE rate = si.tax_rate LIMIT 1)'
                ))
                ->where('s.status', 'completed')
                ->where('si.tax_rate', '>', 0)
                ->whereBetween('s.sale_date', [$start->toDateString(), $end->toDateString()])
                ->when($branchId, fn($q) => $q->where('s.branch_id', $branchId))
                ->groupByRaw("{$dateTrunc}, si.tax_rate")
                ->selectRaw("
                    {$dateTrunc} as period,
                    si.tax_rate,
                    COALESCE(tr.name, CONCAT(si.tax_rate,'%')) as rate_name,
                    COALESCE(tr.is_inclusive, 0) as is_inclusive,
                    COUNT(DISTINCT s.id) as transactions,
                    SUM(si.line_total) as taxable_sales,
                    SUM(si.tax_amount) as tax_collected
                ")
                ->orderByRaw("{$dateTrunc}, si.tax_rate")
                ->get()
                ->map(fn($r) => [
                    'period'        => $r->period,
                    'rate_name'     => $r->rate_name,
                    'tax_rate'      => (float) $r->tax_rate,
                    'tax_type'      => $r->is_inclusive ? 'Inclusive' : 'Exclusive',
                    'transactions'  => (int) $r->transactions,
                    'taxable_sales' => round((float) $r->taxable_sales, 2),
                    'tax_collected' => round((float) $r->tax_collected, 2),
                    'effective_rate'=> (float) $r->taxable_sales > 0
                        ? round((float) $r->tax_collected / (float) $r->taxable_sales * 100, 2)
                        : 0,
                ]);

            $totalTax   = round($rows->sum('tax_collected'), 2);
            $totalSales = round($rows->sum('taxable_sales'), 2);

            // Chart: tax by rate over time
            $rateGroups = $rows->groupBy('rate_name');
            $periodLabels = $rows->pluck('period')->unique()->sort()->values();

            $chartData = [
                'type'   => 'bar',
                'labels' => $periodLabels->map(fn($p) => $groupBy === 'day' ? date('d M', strtotime($p)) : $p)->all(),
                'series' => $rateGroups->map(function ($rateRows, $rateName) use ($periodLabels) {
                    $byPeriod = $rateRows->pluck('tax_collected', 'period');
                    return [
                        'name' => $rateName,
                        'data' => $periodLabels->map(fn($p) => round((float) ($byPeriod[$p] ?? 0), 2))->all(),
                    ];
                })->values()->all(),
            ];

            return new ReportResult(
                summary: [
                    $this->card('Total Tax Collected', $totalTax, 'money'),
                    $this->card('Taxable Sales',       $totalSales, 'money'),
                    $this->card('Effective Tax Rate',  $totalSales > 0 ? round($totalTax / $totalSales * 100, 2) : 0, 'pct'),
                    $this->card('Tax Rates Applied',   $rows->pluck('tax_rate')->unique()->count(), 'int'),
                ],
                rows: $rows,
                columns: [
                    ['key'=>'period',        'label'=>'Period',          'type'=>'string'],
                    ['key'=>'rate_name',     'label'=>'Tax Rate',        'type'=>'string'],
                    ['key'=>'tax_type',      'label'=>'Type',            'type'=>'string'],
                    ['key'=>'transactions',  'label'=>'Transactions',    'type'=>'int',  'align'=>'right','total'=>true],
                    ['key'=>'taxable_sales', 'label'=>'Taxable Sales',   'type'=>'money','align'=>'right','total'=>true],
                    ['key'=>'tax_collected', 'label'=>'Tax Collected',   'type'=>'money','align'=>'right','total'=>true],
                    ['key'=>'effective_rate','label'=>'Eff. Rate %',     'type'=>'percent','align'=>'right'],
                ],
                totals: [
                    'transactions'  => $rows->sum('transactions'),
                    'taxable_sales' => $totalSales,
                    'tax_collected' => $totalTax,
                ],
                chart_data: $chartData,
                meta: $this->buildMeta($filters, $start, $end, $rows->count()),
            );
        });
    }
}
