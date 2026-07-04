<?php

namespace App\Reports\Tax;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;

/**
 * Tax-Exempt Sales Report
 *
 * Shows sales and sale items where tax_amount = 0.
 * Used to verify that:
 * - Zero-rated products were correctly marked (intentional)
 * - No products were accidentally sold without tax (unintentional)
 *
 * NOTE ON FBR COMPLIANCE:
 * Under Pakistan's Sales Tax Act 1990 and FBR SRO 1006(I)/2021,
 * point-of-sale systems for tier-1 retailers must integrate with FBR's
 * real-time invoice monitoring system. This report helps identify
 * zero-rated supplies which require separate treatment in your FBR
 * Annexure-A submission. For live FBR POS integration visit
 * https://e.fbr.gov.pk and contact FBR at 051-111-772-772 for
 * IRIS API credentials.
 */
class TaxExemptSalesReport extends BaseReport
{
    public function getName(): string { return 'Tax-Exempt Sales'; }
    public function getCategory(): string { return 'tax'; }
    public function getDescription(): string { return 'Zero-tax sales — verify exempt products are correctly classified.'; }
    public function getRequiredModule(): ?string { return 'tax-reports'; }

    public function getFilterSchema(): array
    {
        return [
            ['key'=>'date_range','type'=>'date_range','label'=>'Date Range','default'=>'this_month','required'=>true],
            ['key'=>'branch_id', 'type'=>'branch_select','label'=>'Branch'],
            ['key'=>'group_by',  'type'=>'select','label'=>'Group By','default'=>'product',
             'options'=>[
                ['value'=>'product', 'label'=>'By Product'],
                ['value'=>'sale',    'label'=>'By Sale'],
             ]],
        ];
    }

    public function run(array $filters): ReportResult
    {
        $filters = array_merge($this->getDefaultFilters(), $filters);

        return $this->remember('tax-exempt-sales', $filters, function () use ($filters) {
            [$start, $end] = $this->parseDateRange($filters);
            $branchId = $this->branchId($filters);
            $groupBy  = $filters['group_by'] ?? 'product';

            if ($groupBy === 'sale') {
                // Sales where the entire sale had no tax
                $rows = $this->salesBase($start, $end, $branchId)
                    ->where('tax_amount', 0)
                    ->leftJoin('customers as c', 'c.id', '=', 'sales.customer_id')
                    ->select(
                        'sales.sale_number',
                        'sales.sale_date',
                        DB::raw("COALESCE(c.name,'Walk-in') as customer_name"),
                        'sales.total',
                        'sales.tax_amount',
                        'sales.discount_amount',
                    )
                    ->orderByDesc('sales.sale_date')
                    ->limit(500)
                    ->get()
                    ->map(fn($r) => [
                        'sale_number'    => $r->sale_number,
                        'sale_date'      => $r->sale_date,
                        'customer_name'  => $r->customer_name,
                        'total'          => round((float) $r->total, 2),
                        'tax_amount'     => 0,
                        'discount_amount'=> round((float) $r->discount_amount, 2),
                    ]);

                $columns = [
                    ['key'=>'sale_number',   'label'=>'Sale #',         'type'=>'string'],
                    ['key'=>'sale_date',     'label'=>'Date',           'type'=>'date'],
                    ['key'=>'customer_name', 'label'=>'Customer',       'type'=>'string'],
                    ['key'=>'total',         'label'=>'Total',          'type'=>'money','align'=>'right','total'=>true],
                    ['key'=>'discount_amount','label'=>'Discount',      'type'=>'money','align'=>'right'],
                ];
            } else {
                // Products sold with zero tax
                $rows = DB::table('sale_items as si')
                    ->join('sales as s', 's.id', '=', 'si.sale_id')
                    ->join('products as p', 'p.id', '=', 'si.product_id')
                    ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
                    ->where('s.status', 'completed')
                    ->where('si.tax_rate', 0)
                    ->whereBetween('s.sale_date', [$start->toDateString(), $end->toDateString()])
                    ->when($branchId, fn($q) => $q->where('s.branch_id', $branchId))
                    ->groupBy('si.product_id', 'si.product_name', 'si.sku', 'c.name')
                    ->selectRaw("
                        si.product_name,
                        si.sku,
                        COALESCE(c.name,'Uncategorized') as category_name,
                        COUNT(DISTINCT s.id) as transactions,
                        SUM(si.quantity) as qty_sold,
                        SUM(si.line_total) as revenue
                    ")
                    ->orderByDesc('revenue')
                    ->get()
                    ->map(fn($r) => [
                        'product_name'  => $r->product_name,
                        'sku'           => $r->sku,
                        'category_name' => $r->category_name,
                        'transactions'  => (int) $r->transactions,
                        'qty_sold'      => round((float) $r->qty_sold, 2),
                        'revenue'       => round((float) $r->revenue, 2),
                    ]);

                $columns = [
                    ['key'=>'product_name',  'label'=>'Product',       'type'=>'string'],
                    ['key'=>'sku',           'label'=>'SKU',           'type'=>'string'],
                    ['key'=>'category_name', 'label'=>'Category',      'type'=>'string'],
                    ['key'=>'transactions',  'label'=>'Transactions',  'type'=>'int',  'align'=>'right','total'=>true],
                    ['key'=>'qty_sold',      'label'=>'Qty Sold',      'type'=>'number','align'=>'right','total'=>true],
                    ['key'=>'revenue',       'label'=>'Revenue',       'type'=>'money','align'=>'right','total'=>true],
                ];
            }

            $totalExemptRevenue = $rows->sum($groupBy === 'sale' ? 'total' : 'revenue');

            // What % of all sales are tax-exempt?
            $totalRevenue = (float) $this->salesBase($start, $end, $branchId)->sum('total');
            $exemptPct    = $totalRevenue > 0 ? round($totalExemptRevenue / $totalRevenue * 100, 1) : 0;

            return new ReportResult(
                summary: [
                    $this->card('Tax-Exempt Revenue', $totalExemptRevenue, 'money'),
                    $this->card('% of All Sales',     $exemptPct, 'pct'),
                    $this->card('SKUs / Sales',        $rows->count(), 'int'),
                ],
                rows: $rows,
                columns: $columns,
                totals: $groupBy === 'sale'
                    ? ['total' => round($totalExemptRevenue, 2)]
                    : ['transactions'=>$rows->sum('transactions'),'qty_sold'=>round($rows->sum('qty_sold'),2),'revenue'=>round($totalExemptRevenue,2)],
                meta: $this->buildMeta($filters, $start, $end, $rows->count()),
            );
        });
    }
}
