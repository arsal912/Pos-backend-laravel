<?php

namespace App\Reports\Inventory;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;

class OutOfStockReport extends BaseReport
{
    public function getName(): string { return 'Out of Stock'; }
    public function getCategory(): string { return 'inventory'; }
    public function getDescription(): string { return 'Products with zero stock, sorted by estimated lost revenue.'; }
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

        return $this->remember('out-of-stock', $filters, function () use ($branchId, $catId, $filters) {
            $rows = DB::table('inventory_items as ii')
                ->join('products as p', 'p.id', '=', 'ii.product_id')
                ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
                ->whereNull('p.deleted_at')
                ->where('p.track_stock', true)
                ->where('ii.quantity', '<=', 0)
                ->when($branchId, fn($q) => $q->where('ii.branch_id', $branchId))
                ->when($catId, fn($q) => $q->where('p.category_id', $catId))
                ->selectRaw("p.id as product_id, p.name, p.sku, COALESCE(c.name,'Uncategorized') as category, p.selling_price")
                ->get();

            $productIds = $rows->pluck('product_id')->all();

            // Days out of stock (last movement that set qty to 0 or below)
            $lastMovements = DB::table('stock_movements')
                ->whereIn('product_id', $productIds)
                ->groupBy('product_id')
                ->selectRaw('product_id, MAX(created_at) as last_movement')
                ->pluck('last_movement', 'product_id');

            // Avg daily sales (last 60 days)
            $velocity = DB::table('sale_items as si')
                ->join('sales as s', 's.id', '=', 'si.sale_id')
                ->where('s.status', 'completed')
                ->whereIn('si.product_id', $productIds)
                ->where('s.sale_date', '>=', now()->subDays(60)->toDateString())
                ->groupBy('si.product_id')
                ->selectRaw('si.product_id, SUM(si.quantity) as qty_60d, SUM(si.line_total) as rev_60d')
                ->get()->keyBy('product_id');

            $enriched = $rows->map(function ($r) use ($lastMovements, $velocity) {
                $lastMov   = $lastMovements[$r->product_id] ?? null;
                $daysOut   = $lastMov ? now()->diffInDays($lastMov) : null;
                $avgDaily  = isset($velocity[$r->product_id]) ? round((float) $velocity[$r->product_id]->qty_60d / 60, 2) : 0;
                $avgRevDay = isset($velocity[$r->product_id]) ? round((float) $velocity[$r->product_id]->rev_60d / 60, 2) : 0;
                $lostRev   = $daysOut !== null ? round($avgRevDay * $daysOut, 2) : 0;

                return [
                    'product_name'     => $r->name,
                    'sku'              => $r->sku,
                    'category'         => $r->category,
                    'selling_price'    => round((float) $r->selling_price, 2),
                    'days_out_of_stock'=> $daysOut,
                    'avg_daily_sales'  => $avgDaily,
                    'est_lost_revenue' => $lostRev,
                ];
            })->sortByDesc('est_lost_revenue')->values();

            return new ReportResult(
                summary: [
                    $this->card('Out of Stock SKUs',     $enriched->count(), 'int'),
                    $this->card('Est. Lost Revenue',     $enriched->sum('est_lost_revenue'), 'money'),
                ],
                rows: $enriched,
                columns: [
                    ['key'=>'product_name',     'label'=>'Product',         'type'=>'string'],
                    ['key'=>'sku',              'label'=>'SKU',             'type'=>'string'],
                    ['key'=>'category',         'label'=>'Category',        'type'=>'string'],
                    ['key'=>'selling_price',    'label'=>'Selling Price',   'type'=>'money','align'=>'right'],
                    ['key'=>'days_out_of_stock','label'=>'Days Out of Stock','type'=>'int',  'align'=>'right'],
                    ['key'=>'avg_daily_sales',  'label'=>'Avg Daily Sales', 'type'=>'number','align'=>'right'],
                    ['key'=>'est_lost_revenue', 'label'=>'Est. Lost Revenue','type'=>'money','align'=>'right','total'=>true],
                ],
                totals: ['est_lost_revenue'=>round($enriched->sum('est_lost_revenue'),2)],
                meta: $this->buildMeta($filters, now(), now(), $enriched->count()),
            );
        });
    }
}
