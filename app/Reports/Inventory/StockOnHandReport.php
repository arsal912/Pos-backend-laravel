<?php

namespace App\Reports\Inventory;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;

class StockOnHandReport extends BaseReport
{
    public function getName(): string { return 'Stock on Hand'; }
    public function getCategory(): string { return 'inventory'; }
    public function getDescription(): string { return 'Current inventory levels with cost and retail value totals.'; }
    public function getRequiredModule(): ?string { return 'stock-reports'; }

    public function getFilterSchema(): array
    {
        return [
            ['key'=>'branch_id',   'type'=>'branch_select','label'=>'Branch'],
            ['key'=>'category_id', 'type'=>'select','label'=>'Category','options'=>[]],
            ['key'=>'status',      'type'=>'select','label'=>'Status','default'=>'',
             'options'=>[['value'=>'','label'=>'All'],['value'=>'in_stock','label'=>'In Stock'],['value'=>'low','label'=>'Low Stock'],['value'=>'out','label'=>'Out of Stock']]],
            ['key'=>'min_qty',     'type'=>'number','label'=>'Min Quantity'],
        ];
    }

    public function getDefaultFilters(): array
    {
        return ['branch_id'=>null, 'category_id'=>null, 'status'=>'', 'min_qty'=>null];
    }

    public function run(array $filters): ReportResult
    {
        $filters = array_merge($this->getDefaultFilters(), $filters);

        return $this->remember('stock-on-hand', $filters, function () use ($filters) {
            $branchId   = $this->branchId($filters);
            $categoryId = $filters['category_id'] ?? null;
            $status     = $filters['status'] ?? '';
            $minQty     = isset($filters['min_qty']) && $filters['min_qty'] !== null ? (float) $filters['min_qty'] : null;

            $query = DB::table('inventory_items as ii')
                ->join('products as p', 'p.id', '=', 'ii.product_id')
                ->leftJoin('product_variants as pv', 'pv.id', '=', 'ii.variant_id')
                ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
                ->whereNull('p.deleted_at')
                ->where('p.track_stock', true)
                ->when($branchId, fn($q) => $q->where('ii.branch_id', $branchId))
                ->when($categoryId, fn($q) => $q->where('p.category_id', $categoryId))
                ->when($minQty !== null, fn($q) => $q->where('ii.quantity', '>=', $minQty))
                ->selectRaw("
                    p.id as product_id,
                    p.name as product_name,
                    COALESCE(pv.sku, p.sku) as sku,
                    COALESCE(c.name,'Uncategorized') as category_name,
                    ii.branch_id,
                    COALESCE(pv.cost_price, p.cost_price) as cost_price,
                    COALESCE(pv.selling_price, p.selling_price) as selling_price,
                    p.low_stock_threshold,
                    ii.quantity,
                    ii.reserved_quantity
                ")
                ->orderBy('p.name');

            $rows = $query->get()->map(function ($r) {
                $qty       = (float) $r->quantity;
                $cost      = (float) $r->cost_price;
                $retail    = (float) $r->selling_price;
                $threshold = (int) ($r->low_stock_threshold ?? 0);

                $stockStatus = match (true) {
                    $qty <= 0            => 'out',
                    $threshold > 0 && $qty <= $threshold => 'low',
                    default              => 'in_stock',
                };

                return [
                    'product_name'  => $r->product_name,
                    'sku'           => $r->sku,
                    'category_name' => $r->category_name,
                    'quantity'      => round($qty, 3),
                    'reserved'      => round((float) $r->reserved_quantity, 3),
                    'available'     => round(max(0, $qty - (float) $r->reserved_quantity), 3),
                    'cost_price'    => round($cost, 2),
                    'cost_value'    => round($qty * $cost, 2),
                    'retail_price'  => round($retail, 2),
                    'retail_value'  => round($qty * $retail, 2),
                    'status'        => $stockStatus,
                ];
            })->when($status, fn($c) => $c->filter(fn($r) => $r['status'] === $status))->values();

            $totalCostValue   = round($rows->sum('cost_value'), 2);
            $totalRetailValue = round($rows->sum('retail_value'), 2);
            $totalQty         = round($rows->sum('quantity'), 3);

            return new ReportResult(
                summary: [
                    $this->card('Total Items (SKUs)',  $rows->count(), 'int'),
                    $this->card('Total Quantity',      $totalQty, 'int'),
                    $this->card('Total Cost Value',    $totalCostValue, 'money'),
                    $this->card('Total Retail Value',  $totalRetailValue, 'money'),
                    $this->card('Out of Stock',        $rows->filter(fn($r) => $r['status'] === 'out')->count(), 'int'),
                    $this->card('Low Stock',           $rows->filter(fn($r) => $r['status'] === 'low')->count(), 'int'),
                ],
                rows: $rows,
                columns: [
                    ['key'=>'product_name',  'label'=>'Product',       'type'=>'string'],
                    ['key'=>'sku',           'label'=>'SKU',           'type'=>'string'],
                    ['key'=>'category_name', 'label'=>'Category',      'type'=>'string'],
                    ['key'=>'quantity',      'label'=>'Qty',           'type'=>'number','align'=>'right','total'=>true],
                    ['key'=>'reserved',      'label'=>'Reserved',      'type'=>'number','align'=>'right'],
                    ['key'=>'available',     'label'=>'Available',     'type'=>'number','align'=>'right','total'=>true],
                    ['key'=>'cost_price',    'label'=>'Unit Cost',     'type'=>'money', 'align'=>'right'],
                    ['key'=>'cost_value',    'label'=>'Cost Value',    'type'=>'money', 'align'=>'right','total'=>true],
                    ['key'=>'retail_value',  'label'=>'Retail Value',  'type'=>'money', 'align'=>'right','total'=>true],
                    ['key'=>'status',        'label'=>'Status',        'type'=>'string'],
                ],
                totals: [
                    'quantity'    => $totalQty,
                    'available'   => round($rows->sum('available'), 3),
                    'cost_value'  => $totalCostValue,
                    'retail_value'=> $totalRetailValue,
                ],
                meta: array_merge(['as_of' => now()->toDateTimeString()], $this->buildMeta($filters, now(), now(), $rows->count())),
            );
        });
    }
}
