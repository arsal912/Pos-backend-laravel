<?php

namespace App\Reports\Tax;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;

/**
 * Tax by Rate Report
 *
 * Aggregates total taxable sales and tax collected per tax rate for the period.
 * The primary report needed when filling out FBR sales tax returns:
 * - Column 1: Tax rate %
 * - Column 2: Taxable sales at that rate
 * - Column 3: Tax payable (collected from customers)
 *
 * NOTE: This report uses sale_items.tax_rate (% stored at time of sale)
 * and sale_items.tax_amount (calculated at time of sale) for accuracy.
 * Products with tax_rate=0 are excluded.
 */
class TaxByRateReport extends BaseReport
{
    public function getName(): string { return 'Tax by Rate'; }
    public function getCategory(): string { return 'tax'; }
    public function getDescription(): string { return 'Per-rate taxable sales and tax payable — for FBR return filing.'; }
    public function getRequiredModule(): ?string { return 'tax-reports'; }

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

        return $this->remember('tax-by-rate', $filters, function () use ($filters) {
            [$start, $end] = $this->parseDateRange($filters);
            $branchId = $this->branchId($filters);

            $rows = DB::table('sale_items as si')
                ->join('sales as s', 's.id', '=', 'si.sale_id')
                ->where('s.status', 'completed')
                ->where('si.tax_rate', '>', 0)
                ->whereBetween('s.sale_date', [$start->toDateString(), $end->toDateString()])
                ->when($branchId, fn($q) => $q->where('s.branch_id', $branchId))
                ->groupBy('si.tax_rate')
                ->selectRaw("
                    si.tax_rate as rate_pct,
                    COUNT(DISTINCT s.id) as transactions,
                    SUM(si.quantity) as qty_sold,
                    SUM(si.line_total) as taxable_amount,
                    SUM(si.tax_amount) as tax_collected,
                    SUM(si.line_total - si.tax_amount) as net_of_tax
                ")
                ->orderBy('si.tax_rate')
                ->get()
                ->map(fn($r) => [
                    'rate_pct'       => (float) $r->rate_pct,
                    'rate_label'     => number_format((float) $r->rate_pct, 2) . '%',
                    'transactions'   => (int) $r->transactions,
                    'qty_sold'       => round((float) $r->qty_sold, 2),
                    'taxable_amount' => round((float) $r->taxable_amount, 2),
                    'tax_collected'  => round((float) $r->tax_collected, 2),
                    'net_of_tax'     => round((float) $r->net_of_tax, 2),
                ]);

            $totalTaxable = round($rows->sum('taxable_amount'), 2);
            $totalTax     = round($rows->sum('tax_collected'), 2);

            $chartData = [
                'type'   => 'pie',
                'labels' => $rows->pluck('rate_label')->all(),
                'series' => [['name'=>'Tax Collected','data'=>$rows->pluck('tax_collected')->all()]],
            ];

            return new ReportResult(
                summary: [
                    $this->card('Total Tax Collected', $totalTax,    'money'),
                    $this->card('Total Taxable Sales', $totalTaxable, 'money'),
                    $this->card('Net of Tax',          round($totalTaxable - $totalTax, 2), 'money'),
                    $this->card('Period',              $start->toDateString() . ' → ' . $end->toDateString(), 'string'),
                ],
                rows: $rows,
                columns: [
                    ['key'=>'rate_label',    'label'=>'Tax Rate',       'type'=>'string'],
                    ['key'=>'transactions',  'label'=>'Transactions',   'type'=>'int',  'align'=>'right','total'=>true],
                    ['key'=>'qty_sold',      'label'=>'Items Sold',     'type'=>'number','align'=>'right','total'=>true],
                    ['key'=>'taxable_amount','label'=>'Taxable Amount', 'type'=>'money','align'=>'right','total'=>true],
                    ['key'=>'tax_collected', 'label'=>'Tax Payable',    'type'=>'money','align'=>'right','total'=>true],
                    ['key'=>'net_of_tax',    'label'=>'Net of Tax',     'type'=>'money','align'=>'right','total'=>true],
                ],
                totals: [
                    'transactions'   => $rows->sum('transactions'),
                    'qty_sold'       => round($rows->sum('qty_sold'), 2),
                    'taxable_amount' => $totalTaxable,
                    'tax_collected'  => $totalTax,
                    'net_of_tax'     => round($rows->sum('net_of_tax'), 2),
                ],
                chart_data: $chartData,
                meta: $this->buildMeta($filters, $start, $end, $rows->count()),
            );
        });
    }
}
