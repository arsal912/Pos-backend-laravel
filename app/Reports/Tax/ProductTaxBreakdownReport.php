<?php

namespace App\Reports\Tax;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;

/**
 * Product Tax Breakdown Report
 *
 * Shows taxable amount and tax collected per product for the period.
 * Used during tax audits to prove which products were sold at which tax rate
 * and how much tax was collected on each.
 *
 * NOTE: sale_items.tax_rate and sale_items.tax_amount are denormalized
 * from the product's tax_rate at time of sale — they won't change even
 * if the product's tax rate is later updated.
 */
class ProductTaxBreakdownReport extends BaseReport
{
    public function getName(): string { return 'Product Tax Breakdown'; }
    public function getCategory(): string { return 'tax'; }
    public function getDescription(): string { return 'Per-product taxable amounts — audit-ready product-level tax trail.'; }
    public function getRequiredModule(): ?string { return 'tax-reports'; }

    public function getFilterSchema(): array
    {
        return [
            ['key'=>'date_range',  'type'=>'date_range',   'label'=>'Date Range','default'=>'this_month','required'=>true],
            ['key'=>'branch_id',   'type'=>'branch_select','label'=>'Branch'],
            ['key'=>'category_id', 'type'=>'select',       'label'=>'Category','options'=>[]],
            ['key'=>'tax_rate',    'type'=>'number',       'label'=>'Tax Rate % (exact)'],
        ];
    }

    public function run(array $filters): ReportResult
    {
        $filters = array_merge($this->getDefaultFilters(), $filters);

        return $this->remember('product-tax-breakdown', $filters, function () use ($filters) {
            [$start, $end] = $this->parseDateRange($filters);
            $branchId   = $this->branchId($filters);
            $categoryId = $filters['category_id'] ?? null;
            $taxRate    = isset($filters['tax_rate']) && $filters['tax_rate'] !== '' ? (float) $filters['tax_rate'] : null;

            $rows = DB::table('sale_items as si')
                ->join('sales as s', 's.id', '=', 'si.sale_id')
                ->join('products as p', 'p.id', '=', 'si.product_id')
                ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
                ->where('s.status', 'completed')
                ->where('si.tax_rate', '>', 0)
                ->whereBetween('s.sale_date', [$start->toDateString(), $end->toDateString()])
                ->when($branchId, fn($q) => $q->where('s.branch_id', $branchId))
                ->when($categoryId, fn($q) => $q->where('p.category_id', $categoryId))
                ->when($taxRate !== null, fn($q) => $q->where('si.tax_rate', $taxRate))
                ->groupBy('si.product_id', 'si.product_name', 'si.sku', 'si.tax_rate', 'c.name')
                ->selectRaw("
                    si.product_name,
                    si.sku,
                    COALESCE(c.name,'Uncategorized') as category_name,
                    si.tax_rate,
                    SUM(si.quantity) as qty_sold,
                    SUM(si.line_total) as gross_amount,
                    SUM(si.tax_amount) as tax_collected,
                    SUM(si.line_total - si.tax_amount) as net_amount
                ")
                ->orderBy('si.tax_rate')
                ->orderByDesc('gross_amount')
                ->get()
                ->map(fn($r) => [
                    'product_name'  => $r->product_name,
                    'sku'           => $r->sku,
                    'category_name' => $r->category_name,
                    'tax_rate'      => (float) $r->tax_rate,
                    'qty_sold'      => round((float) $r->qty_sold, 2),
                    'gross_amount'  => round((float) $r->gross_amount, 2),
                    'tax_collected' => round((float) $r->tax_collected, 2),
                    'net_amount'    => round((float) $r->net_amount, 2),
                ]);

            $totalTax   = round($rows->sum('tax_collected'), 2);
            $totalGross = round($rows->sum('gross_amount'), 2);

            return new ReportResult(
                summary: [
                    $this->card('Total Tax Collected', $totalTax, 'money'),
                    $this->card('Taxable Products',    $rows->count(), 'int'),
                    $this->card('Gross Taxable Sales', $totalGross, 'money'),
                ],
                rows: $rows,
                columns: [
                    ['key'=>'product_name',  'label'=>'Product',       'type'=>'string'],
                    ['key'=>'sku',           'label'=>'SKU',           'type'=>'string'],
                    ['key'=>'category_name', 'label'=>'Category',      'type'=>'string'],
                    ['key'=>'tax_rate',      'label'=>'Tax Rate %',    'type'=>'number','align'=>'right'],
                    ['key'=>'qty_sold',      'label'=>'Qty Sold',      'type'=>'number','align'=>'right','total'=>true],
                    ['key'=>'gross_amount',  'label'=>'Gross Amount',  'type'=>'money', 'align'=>'right','total'=>true],
                    ['key'=>'tax_collected', 'label'=>'Tax Collected', 'type'=>'money', 'align'=>'right','total'=>true],
                    ['key'=>'net_amount',    'label'=>'Net of Tax',    'type'=>'money', 'align'=>'right','total'=>true],
                ],
                totals: [
                    'qty_sold'      => round($rows->sum('qty_sold'), 2),
                    'gross_amount'  => $totalGross,
                    'tax_collected' => $totalTax,
                    'net_amount'    => round($rows->sum('net_amount'), 2),
                ],
                meta: $this->buildMeta($filters, $start, $end, $rows->count()),
            );
        });
    }
}
