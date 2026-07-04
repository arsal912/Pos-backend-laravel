<?php

namespace App\Reports\Inventory;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;

class StockAgingReport extends BaseReport
{
    public function getName(): string { return 'Stock Aging'; }
    public function getCategory(): string { return 'inventory'; }
    public function getDescription(): string { return 'Identify slow-moving and dead stock by days since last sale.'; }
    public function getRequiredModule(): ?string { return 'stock-reports'; }

    public function getFilterSchema(): array
    {
        return [
            ['key'=>'branch_id',   'type'=>'branch_select','label'=>'Branch'],
            ['key'=>'category_id', 'type'=>'select','label'=>'Category','options'=>[]],
        ];
    }

    public function getDefaultFilters(): array { return ['branch_id'=>null,'category_id'=>null]; }

    public function run(array $filters): ReportResult
    {
        $filters  = array_merge($this->getDefaultFilters(), $filters);
        $branchId = $this->branchId($filters);
        $catId    = $filters['category_id'] ?? null;

        return $this->remember('stock-aging', $filters, function () use ($branchId, $catId, $filters) {
            // Get current stock
            $stock = DB::table('inventory_items as ii')
                ->join('products as p', 'p.id', '=', 'ii.product_id')
                ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
                ->whereNull('p.deleted_at')
                ->where('p.track_stock', true)
                ->where('ii.quantity', '>', 0)
                ->when($branchId, fn($q) => $q->where('ii.branch_id', $branchId))
                ->when($catId, fn($q) => $q->where('p.category_id', $catId))
                ->selectRaw("p.id as product_id, p.name, p.sku, COALESCE(c.name,'Uncategorized') as category, ii.quantity, p.cost_price")
                ->get();

            $productIds = $stock->pluck('product_id')->all();

            // Last sale date per product
            $lastSales = DB::table('sale_items as si')
                ->join('sales as s', 's.id', '=', 'si.sale_id')
                ->where('s.status', 'completed')
                ->whereIn('si.product_id', $productIds)
                ->groupBy('si.product_id')
                ->selectRaw('si.product_id, MAX(s.sale_date) as last_sale')
                ->pluck('last_sale', 'product_id');

            // First stock-in date
            $firstIn = DB::table('stock_movements')
                ->whereIn('product_id', $productIds)
                ->where('quantity', '>', 0)
                ->groupBy('product_id')
                ->selectRaw('product_id, MIN(created_at) as first_in')
                ->pluck('first_in', 'product_id');

            $now    = now();
            $buckets = ['0-30'=>[],'31-60'=>[],'61-90'=>[],'91-180'=>[],'180+'=>[]];

            $rows = $stock->map(function ($r) use ($lastSales, $firstIn, $now) {
                $lastSale      = $lastSales[$r->product_id] ?? null;
                $daysSinceLastSale = $lastSale ? $now->diffInDays($lastSale) : null;
                $daysInStock   = isset($firstIn[$r->product_id]) ? $now->diffInDays($firstIn[$r->product_id]) : null;

                $agedays = $daysSinceLastSale ?? $daysInStock ?? 999;
                $bucket  = match (true) {
                    $agedays <= 30  => '0-30',
                    $agedays <= 60  => '31-60',
                    $agedays <= 90  => '61-90',
                    $agedays <= 180 => '91-180',
                    default         => '180+',
                };

                return [
                    'product_name'         => $r->name,
                    'sku'                  => $r->sku,
                    'category'             => $r->category,
                    'quantity'             => round((float) $r->quantity, 3),
                    'cost_value'           => round((float) $r->quantity * (float) $r->cost_price, 2),
                    'last_sale_date'       => $lastSale ?? '—',
                    'days_since_last_sale' => $daysSinceLastSale ?? '—',
                    'days_in_stock'        => $daysInStock ?? '—',
                    'age_bucket'           => $bucket,
                ];
            })->sortByDesc('days_since_last_sale')->values();

            // Bucket summary
            $bucketSummary = $rows->groupBy('age_bucket')->map(fn($g, $k) => [
                'bucket'     => $k . ' days',
                'sku_count'  => $g->count(),
                'cost_value' => round($g->sum('cost_value'), 2),
            ])->values();

            $chartData = [
                'type'   => 'bar',
                'labels' => $bucketSummary->pluck('bucket')->all(),
                'series' => [['name'=>'Cost Value','data'=>$bucketSummary->pluck('cost_value')->all()]],
            ];

            return new ReportResult(
                summary: [
                    $this->card('Total SKUs',           $rows->count(), 'int'),
                    $this->card('Dead Stock (180+ days)', $rows->filter(fn($r) => $r['age_bucket'] === '180+')->count(), 'int'),
                    $this->card('Dead Stock Value',      $rows->filter(fn($r) => $r['age_bucket'] === '180+')->sum('cost_value'), 'money'),
                ],
                rows: $rows,
                groups: $bucketSummary->keyBy('bucket')->all(),
                columns: [
                    ['key'=>'product_name',        'label'=>'Product',        'type'=>'string'],
                    ['key'=>'sku',                 'label'=>'SKU',           'type'=>'string'],
                    ['key'=>'category',            'label'=>'Category',      'type'=>'string'],
                    ['key'=>'quantity',            'label'=>'Qty',           'type'=>'number','align'=>'right'],
                    ['key'=>'cost_value',          'label'=>'Cost Value',    'type'=>'money', 'align'=>'right','total'=>true],
                    ['key'=>'last_sale_date',      'label'=>'Last Sale',     'type'=>'string'],
                    ['key'=>'days_since_last_sale','label'=>'Days Idle',     'type'=>'string','align'=>'right'],
                    ['key'=>'age_bucket',          'label'=>'Age Bucket',    'type'=>'string'],
                ],
                totals: ['cost_value'=>round($rows->sum('cost_value'),2)],
                chart_data: $chartData,
                meta: $this->buildMeta($filters, now(), now(), $rows->count()),
            );
        });
    }
}
