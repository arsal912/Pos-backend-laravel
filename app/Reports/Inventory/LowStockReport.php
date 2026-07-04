<?php

namespace App\Reports\Inventory;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;

class LowStockReport extends BaseReport
{
    public function getName(): string { return 'Low Stock'; }
    public function getCategory(): string { return 'inventory'; }
    public function getDescription(): string { return 'Products at or below low-stock threshold with reorder suggestions.'; }
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
        $filters = array_merge($this->getDefaultFilters(), $filters);

        return $this->remember('low-stock', $filters, function () use ($filters) {
            $branchId   = $this->branchId($filters);
            $categoryId = $filters['category_id'] ?? null;

            // Products at/below threshold OR out of stock
            $rows = DB::table('inventory_items as ii')
                ->join('products as p', 'p.id', '=', 'ii.product_id')
                ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
                ->whereNull('p.deleted_at')
                ->where('p.track_stock', true)
                ->where(function ($q) {
                    $q->where('ii.quantity', '<=', 0)
                      ->orWhereRaw('p.low_stock_threshold IS NOT NULL AND ii.quantity <= p.low_stock_threshold');
                })
                ->when($branchId, fn($q) => $q->where('ii.branch_id', $branchId))
                ->when($categoryId, fn($q) => $q->where('p.category_id', $categoryId))
                ->selectRaw("
                    p.id as product_id,
                    p.name as product_name,
                    p.sku,
                    COALESCE(c.name,'Uncategorized') as category_name,
                    ii.quantity as current_qty,
                    COALESCE(p.low_stock_threshold, 0) as threshold,
                    p.cost_price
                ")
                ->orderBy('ii.quantity')
                ->get();

            // Get avg daily sales velocity for last 30 days
            $productIds = $rows->pluck('product_id')->all();
            $velocity   = DB::table('sale_items as si')
                ->join('sales as s', 's.id', '=', 'si.sale_id')
                ->where('s.status', 'completed')
                ->whereIn('si.product_id', $productIds)
                ->where('s.sale_date', '>=', now()->subDays(30)->toDateString())
                ->groupBy('si.product_id')
                ->selectRaw('si.product_id, SUM(si.quantity) as qty_30d')
                ->pluck('qty_30d', 'product_id');

            // Last sale date
            $lastSale = DB::table('sale_items as si')
                ->join('sales as s', 's.id', '=', 'si.sale_id')
                ->where('s.status', 'completed')
                ->whereIn('si.product_id', $productIds)
                ->groupBy('si.product_id')
                ->selectRaw('si.product_id, MAX(s.sale_date) as last_sale_date')
                ->pluck('last_sale_date', 'product_id');

            $enriched = $rows->map(function ($r) use ($velocity, $lastSale) {
                $qty30d         = (float) ($velocity[$r->product_id] ?? 0);
                $avgDailySales  = round($qty30d / 30, 2);
                $lastSaleDate   = $lastSale[$r->product_id] ?? null;
                $daysSinceLastSale = $lastSaleDate ? now()->diffInDays($lastSaleDate) : null;

                // Suggested reorder: 30-day supply
                $suggestedReorder = $avgDailySales > 0 ? ceil($avgDailySales * 30) : (int) ($r->threshold * 2 ?: 10);

                return [
                    'product_name'      => $r->product_name,
                    'sku'               => $r->sku,
                    'category_name'     => $r->category_name,
                    'current_qty'       => round((float) $r->current_qty, 3),
                    'threshold'         => (int) $r->threshold,
                    'days_since_last_sale' => $daysSinceLastSale,
                    'avg_daily_sales'   => $avgDailySales,
                    'suggested_reorder' => $suggestedReorder,
                    'reorder_cost'      => round($suggestedReorder * (float) $r->cost_price, 2),
                ];
            });

            return new ReportResult(
                summary: [
                    $this->card('Low/Out-of-Stock SKUs', $enriched->count(), 'int'),
                    $this->card('Out of Stock',          $enriched->filter(fn($r) => $r['current_qty'] <= 0)->count(), 'int'),
                    $this->card('Est. Reorder Cost',     $enriched->sum('reorder_cost'), 'money'),
                ],
                rows: $enriched,
                columns: [
                    ['key'=>'product_name',        'label'=>'Product',            'type'=>'string'],
                    ['key'=>'sku',                 'label'=>'SKU',               'type'=>'string'],
                    ['key'=>'category_name',       'label'=>'Category',          'type'=>'string'],
                    ['key'=>'current_qty',         'label'=>'Current Qty',       'type'=>'number','align'=>'right'],
                    ['key'=>'threshold',           'label'=>'Threshold',         'type'=>'int',   'align'=>'right'],
                    ['key'=>'avg_daily_sales',     'label'=>'Avg Daily Sales',   'type'=>'number','align'=>'right'],
                    ['key'=>'days_since_last_sale','label'=>'Days Since Sale',   'type'=>'int',   'align'=>'right'],
                    ['key'=>'suggested_reorder',   'label'=>'Suggested Reorder', 'type'=>'int',   'align'=>'right'],
                    ['key'=>'reorder_cost',        'label'=>'Est. Reorder Cost', 'type'=>'money', 'align'=>'right','total'=>true],
                ],
                totals: ['reorder_cost' => round($enriched->sum('reorder_cost'), 2)],
                meta: $this->buildMeta($filters, now(), now(), $enriched->count()),
            );
        });
    }
}
