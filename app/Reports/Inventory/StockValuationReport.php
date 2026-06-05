<?php

namespace App\Reports\Inventory;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StockValuationReport extends BaseReport
{
    public function getName(): string { return 'Stock Valuation'; }
    public function getCategory(): string { return 'inventory'; }
    public function getDescription(): string { return 'Inventory value as of a specific date at cost and retail.'; }
    public function getRequiredModule(): ?string { return 'stock-reports'; }

    public function getFilterSchema(): array
    {
        return [
            ['key'=>'as_of_date',  'type'=>'date',  'label'=>'As of Date','default'=>now()->toDateString(),'required'=>true],
            ['key'=>'branch_id',   'type'=>'branch_select','label'=>'Branch'],
            ['key'=>'category_id', 'type'=>'select','label'=>'Category','options'=>[]],
        ];
    }

    public function getDefaultFilters(): array
    {
        return ['as_of_date'=>now()->toDateString(),'branch_id'=>null,'category_id'=>null];
    }

    public function run(array $filters): ReportResult
    {
        $filters  = array_merge($this->getDefaultFilters(), $filters);
        $asOf     = Carbon::parse($filters['as_of_date'] ?? now())->endOfDay();
        $branchId = $this->branchId($filters);
        $catId    = $filters['category_id'] ?? null;

        return $this->remember('stock-valuation', $filters, function () use ($asOf, $branchId, $catId, $filters) {
            // Compute quantity as of asOf date via stock_movements
            $movements = DB::table('stock_movements as sm')
                ->join('products as p', 'p.id', '=', 'sm.product_id')
                ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
                ->whereNull('p.deleted_at')
                ->where('sm.created_at', '<=', $asOf)
                ->when($branchId, fn($q) => $q->where('sm.branch_id', $branchId))
                ->when($catId, fn($q) => $q->where('p.category_id', $catId))
                ->groupBy('sm.product_id', 'sm.branch_id', 'p.name', 'p.sku', 'p.cost_price', 'p.selling_price', 'c.name')
                ->selectRaw("
                    sm.product_id,
                    sm.branch_id,
                    p.name as product_name,
                    p.sku,
                    COALESCE(c.name,'Uncategorized') as category,
                    SUM(sm.quantity) as qty_at_date,
                    AVG(CASE WHEN sm.cost_at_time > 0 THEN sm.cost_at_time ELSE p.cost_price END) as avg_cost,
                    p.selling_price
                ")
                ->having('qty_at_date', '>', 0)
                ->get()
                ->map(fn($r) => [
                    'product_name'  => $r->product_name,
                    'sku'           => $r->sku,
                    'category'      => $r->category,
                    'quantity'      => round((float) $r->qty_at_date, 3),
                    'avg_cost'      => round((float) $r->avg_cost, 2),
                    'cost_value'    => round((float) $r->qty_at_date * (float) $r->avg_cost, 2),
                    'retail_value'  => round((float) $r->qty_at_date * (float) $r->selling_price, 2),
                    'potential_margin' => round(((float) $r->selling_price - (float) $r->avg_cost) / max((float) $r->selling_price, 0.01) * 100, 1),
                ]);

            $totalCost   = round($movements->sum('cost_value'), 2);
            $totalRetail = round($movements->sum('retail_value'), 2);

            return new ReportResult(
                summary: [
                    $this->card('Valuation Date',    $asOf->toDateString(), 'string'),
                    $this->card('SKUs in Stock',      $movements->count(), 'int'),
                    $this->card('Total Cost Value',   $totalCost, 'money'),
                    $this->card('Total Retail Value', $totalRetail, 'money'),
                    $this->card('Unrealised Profit',  $totalRetail - $totalCost, 'money'),
                ],
                rows: $movements,
                columns: [
                    ['key'=>'product_name',     'label'=>'Product',           'type'=>'string'],
                    ['key'=>'sku',              'label'=>'SKU',               'type'=>'string'],
                    ['key'=>'category',         'label'=>'Category',          'type'=>'string'],
                    ['key'=>'quantity',         'label'=>'Qty',               'type'=>'number','align'=>'right','total'=>true],
                    ['key'=>'avg_cost',         'label'=>'Avg Cost',          'type'=>'money', 'align'=>'right'],
                    ['key'=>'cost_value',       'label'=>'Cost Value',        'type'=>'money', 'align'=>'right','total'=>true],
                    ['key'=>'retail_value',     'label'=>'Retail Value',      'type'=>'money', 'align'=>'right','total'=>true],
                    ['key'=>'potential_margin', 'label'=>'Potential Margin %','type'=>'percent','align'=>'right'],
                ],
                totals: [
                    'quantity'    => round($movements->sum('quantity'), 3),
                    'cost_value'  => $totalCost,
                    'retail_value'=> $totalRetail,
                ],
                meta: array_merge(['as_of_date' => $asOf->toDateString()], $this->buildMeta($filters, $asOf, $asOf, $movements->count())),
            );
        });
    }
}
