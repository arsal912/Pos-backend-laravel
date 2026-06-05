<?php

namespace App\Reports\Sales;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;

class SalesByCategoryReport extends BaseReport
{
    public function getName(): string { return 'Sales by Category'; }
    public function getCategory(): string { return 'sales'; }
    public function getDescription(): string { return 'Revenue breakdown by product category.'; }
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

        return $this->remember('sales-by-category', $filters, function () use ($filters) {
            [$start, $end] = $this->parseDateRange($filters);
            $branchId = $this->branchId($filters);

            $rows = DB::table('sale_items as si')
                ->join('sales as s', 's.id', '=', 'si.sale_id')
                ->join('products as p', 'p.id', '=', 'si.product_id')
                ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
                ->where('s.status', 'completed')
                ->whereBetween('s.sale_date', [$start->toDateString(), $end->toDateString()])
                ->when($branchId, fn($q) => $q->where('s.branch_id', $branchId))
                ->groupBy('c.id', 'c.name')
                ->selectRaw("
                    COALESCE(c.name, 'Uncategorized') as category_name,
                    COUNT(DISTINCT s.id) as transactions,
                    SUM(si.quantity) as qty_sold,
                    SUM(si.line_total) as revenue,
                    SUM(si.cost_at_time * si.quantity) as cogs
                ")
                ->orderByDesc('revenue')
                ->get()
                ->map(fn($r) => [
                    'category_name' => $r->category_name,
                    'transactions'  => (int) $r->transactions,
                    'qty_sold'      => round((float) $r->qty_sold, 2),
                    'revenue'       => round((float) $r->revenue, 2),
                    'cogs'          => round((float) $r->cogs, 2),
                    'profit'        => round((float) $r->revenue - (float) $r->cogs, 2),
                ]);

            $totalRevenue = $rows->sum('revenue');
            $rows = $rows->map(fn($r) => array_merge($r, [
                'revenue_pct' => $totalRevenue > 0 ? round($r['revenue'] / $totalRevenue * 100, 1) : 0,
            ]));

            $chartData = [
                'type'   => 'pie',
                'labels' => $rows->pluck('category_name')->all(),
                'series' => [['name' => 'Revenue', 'data' => $rows->pluck('revenue')->all()]],
            ];

            return new ReportResult(
                summary: [
                    $this->card('Total Revenue',    $totalRevenue, 'money'),
                    $this->card('Categories Sold',  $rows->count(), 'int'),
                    $this->card('Top Category',     $rows->first()['category_name'] ?? '—', 'string'),
                ],
                rows: $rows,
                columns: [
                    ['key'=>'category_name','label'=>'Category',    'type'=>'string'],
                    ['key'=>'transactions', 'label'=>'Transactions', 'type'=>'int',  'align'=>'right','total'=>true],
                    ['key'=>'qty_sold',     'label'=>'Qty Sold',    'type'=>'number','align'=>'right','total'=>true],
                    ['key'=>'revenue',      'label'=>'Revenue',     'type'=>'money', 'align'=>'right','total'=>true],
                    ['key'=>'profit',       'label'=>'Profit',      'type'=>'money', 'align'=>'right','total'=>true],
                    ['key'=>'revenue_pct',  'label'=>'% of Revenue','type'=>'percent','align'=>'right'],
                ],
                totals: ['transactions'=>$rows->sum('transactions'),'qty_sold'=>round($rows->sum('qty_sold'),2),'revenue'=>round($totalRevenue,2),'profit'=>round($rows->sum('profit'),2)],
                chart_data: $chartData,
                meta: $this->buildMeta($filters, $start, $end, $rows->count()),
            );
        });
    }
}
