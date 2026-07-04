<?php

namespace App\Reports\Sales;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;

class SalesByBrandReport extends BaseReport
{
    public function getName(): string { return 'Sales by Brand'; }
    public function getCategory(): string { return 'sales'; }
    public function getDescription(): string { return 'Revenue breakdown by product brand.'; }
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

        return $this->remember('sales-by-brand', $filters, function () use ($filters) {
            [$start, $end] = $this->parseDateRange($filters);
            $branchId = $this->branchId($filters);

            $rows = DB::table('sale_items as si')
                ->join('sales as s', 's.id', '=', 'si.sale_id')
                ->join('products as p', 'p.id', '=', 'si.product_id')
                ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
                ->where('s.status', 'completed')
                ->whereBetween('s.sale_date', [$start->toDateString(), $end->toDateString()])
                ->when($branchId, fn($q) => $q->where('s.branch_id', $branchId))
                ->groupBy('b.id', 'b.name')
                ->selectRaw("
                    COALESCE(b.name, 'No Brand') as brand_name,
                    COUNT(DISTINCT s.id) as transactions,
                    SUM(si.quantity) as qty_sold,
                    SUM(si.line_total) as revenue,
                    SUM(si.cost_at_time * si.quantity) as cogs
                ")
                ->orderByDesc('revenue')
                ->get()
                ->map(fn($r) => [
                    'brand_name'   => $r->brand_name,
                    'transactions' => (int) $r->transactions,
                    'qty_sold'     => round((float) $r->qty_sold, 2),
                    'revenue'      => round((float) $r->revenue, 2),
                    'cogs'         => round((float) $r->cogs, 2),
                    'profit'       => round((float) $r->revenue - (float) $r->cogs, 2),
                ]);

            $totalRevenue = $rows->sum('revenue');

            $chartData = [
                'type'   => 'bar',
                'labels' => $rows->take(10)->pluck('brand_name')->all(),
                'series' => [['name' => 'Revenue', 'data' => $rows->take(10)->pluck('revenue')->all()]],
            ];

            return new ReportResult(
                summary: [
                    $this->card('Total Revenue', $totalRevenue, 'money'),
                    $this->card('Brands Sold',   $rows->count(), 'int'),
                ],
                rows: $rows,
                columns: [
                    ['key'=>'brand_name',  'label'=>'Brand',       'type'=>'string'],
                    ['key'=>'transactions','label'=>'Transactions', 'type'=>'int',  'align'=>'right','total'=>true],
                    ['key'=>'qty_sold',    'label'=>'Qty Sold',    'type'=>'number','align'=>'right','total'=>true],
                    ['key'=>'revenue',     'label'=>'Revenue',     'type'=>'money', 'align'=>'right','total'=>true],
                    ['key'=>'profit',      'label'=>'Profit',      'type'=>'money', 'align'=>'right','total'=>true],
                ],
                totals: ['transactions'=>$rows->sum('transactions'),'qty_sold'=>round($rows->sum('qty_sold'),2),'revenue'=>round($totalRevenue,2),'profit'=>round($rows->sum('profit'),2)],
                chart_data: $chartData,
                meta: $this->buildMeta($filters, $start, $end, $rows->count()),
            );
        });
    }
}
