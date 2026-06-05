<?php

namespace App\Reports\Sales;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;

class SalesByProductReport extends BaseReport
{
    public function getName(): string { return 'Sales by Product'; }
    public function getCategory(): string { return 'sales'; }
    public function getDescription(): string { return 'Top products by revenue, quantity, or profit.'; }
    public function getRequiredModule(): ?string { return 'sales-reports'; }

    public function getFilterSchema(): array
    {
        return [
            ['key'=>'date_range',  'type'=>'date_range',    'label'=>'Date Range','default'=>'this_month','required'=>true],
            ['key'=>'branch_id',   'type'=>'branch_select', 'label'=>'Branch'],
            ['key'=>'category_id', 'type'=>'select',        'label'=>'Category', 'options'=>[]],
            ['key'=>'top_n',       'type'=>'number',        'label'=>'Top N Products','default'=>50],
            ['key'=>'sort',        'type'=>'select',        'label'=>'Sort By','default'=>'revenue',
             'options'=>[['value'=>'revenue','label'=>'Revenue'],['value'=>'quantity','label'=>'Quantity'],['value'=>'profit','label'=>'Profit']]],
        ];
    }

    public function run(array $filters): ReportResult
    {
        $filters = array_merge($this->getDefaultFilters(), $filters);

        return $this->remember('sales-by-product', $filters, function () use ($filters) {
            [$start, $end] = $this->parseDateRange($filters);
            $branchId   = $this->branchId($filters);
            $topN       = (int) ($filters['top_n'] ?? 50);
            $sort       = in_array($filters['sort'] ?? '', ['quantity','profit']) ? $filters['sort'] : 'revenue';
            $categoryId = $filters['category_id'] ?? null;

            $query = DB::table('sale_items as si')
                ->join('sales as s', 's.id', '=', 'si.sale_id')
                ->join('products as p', 'p.id', '=', 'si.product_id')
                ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
                ->where('s.status', 'completed')
                ->whereBetween('s.sale_date', [$start->toDateString(), $end->toDateString()])
                ->when($branchId, fn($q) => $q->where('s.branch_id', $branchId))
                ->when($categoryId, fn($q) => $q->where('p.category_id', $categoryId))
                ->groupBy('si.product_id', 'si.product_name', 'si.sku', 'c.name')
                ->selectRaw("
                    si.product_id,
                    si.product_name,
                    si.sku,
                    c.name as category_name,
                    SUM(si.quantity) as qty_sold,
                    SUM(si.line_total) as revenue,
                    SUM(si.cost_at_time * si.quantity) as cogs
                ")
                ->limit($topN);

            $orderCol = match ($sort) {
                'quantity' => 'qty_sold',
                'profit'   => DB::raw('SUM(si.line_total) - SUM(si.cost_at_time * si.quantity)'),
                default    => 'revenue',
            };
            $query->orderByDesc($orderCol);

            $rows = $query->get()->map(function ($r) {
                $revenue = (float) $r->revenue;
                $cogs    = (float) $r->cogs;
                $profit  = $revenue - $cogs;
                return [
                    'product_name'  => $r->product_name,
                    'sku'           => $r->sku,
                    'category_name' => $r->category_name ?? '—',
                    'qty_sold'      => round((float) $r->qty_sold, 2),
                    'revenue'       => round($revenue, 2),
                    'cogs'          => round($cogs, 2),
                    'profit'        => round($profit, 2),
                    'margin_pct'    => $revenue > 0 ? round($profit / $revenue * 100, 1) : 0,
                ];
            });

            $top10 = $rows->take(10);
            $chartData = [
                'type'   => 'bar',
                'labels' => $top10->pluck('product_name')->map(fn($n) => mb_substr($n, 0, 20))->all(),
                'series' => [['name' => 'Revenue', 'data' => $top10->pluck('revenue')->all()]],
            ];

            return new ReportResult(
                summary: [
                    $this->card('Total Revenue',  $rows->sum('revenue'), 'money'),
                    $this->card('Total Qty Sold', $rows->sum('qty_sold'), 'int'),
                    $this->card('Total Profit',   $rows->sum('profit'), 'money'),
                    $this->card('Avg Margin',     $rows->avg('margin_pct'), 'pct'),
                ],
                rows: $rows,
                columns: [
                    ['key'=>'product_name',  'label'=>'Product',   'type'=>'string'],
                    ['key'=>'sku',           'label'=>'SKU',       'type'=>'string'],
                    ['key'=>'category_name', 'label'=>'Category',  'type'=>'string'],
                    ['key'=>'qty_sold',      'label'=>'Qty Sold',  'type'=>'number','align'=>'right','total'=>true],
                    ['key'=>'revenue',       'label'=>'Revenue',   'type'=>'money', 'align'=>'right','total'=>true],
                    ['key'=>'cogs',          'label'=>'COGS',      'type'=>'money', 'align'=>'right','total'=>true],
                    ['key'=>'profit',        'label'=>'Profit',    'type'=>'money', 'align'=>'right','total'=>true],
                    ['key'=>'margin_pct',    'label'=>'Margin %',  'type'=>'percent','align'=>'right'],
                ],
                totals: ['qty_sold'=>round($rows->sum('qty_sold'),2),'revenue'=>round($rows->sum('revenue'),2),'cogs'=>round($rows->sum('cogs'),2),'profit'=>round($rows->sum('profit'),2)],
                chart_data: $chartData,
                meta: $this->buildMeta($filters, $start, $end, $rows->count()),
            );
        });
    }
}
