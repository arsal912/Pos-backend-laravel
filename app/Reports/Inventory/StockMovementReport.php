<?php

namespace App\Reports\Inventory;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;

class StockMovementReport extends BaseReport
{
    public function getName(): string { return 'Stock Movements'; }
    public function getCategory(): string { return 'inventory'; }
    public function getDescription(): string { return 'Full audit log of every stock change with export.'; }
    public function getRequiredModule(): ?string { return 'stock-reports'; }

    public function getFilterSchema(): array
    {
        return [
            ['key'=>'date_range', 'type'=>'date_range',   'label'=>'Date Range','default'=>'this_month','required'=>true],
            ['key'=>'branch_id',  'type'=>'branch_select','label'=>'Branch'],
            ['key'=>'product_id', 'type'=>'text',         'label'=>'Product ID'],
            ['key'=>'type',       'type'=>'select',       'label'=>'Movement Type','default'=>'',
             'options'=>[
                ['value'=>'','label'=>'All Types'],
                ['value'=>'sale','label'=>'Sale'],
                ['value'=>'sale_return','label'=>'Sale Return'],
                ['value'=>'purchase','label'=>'Purchase (GRN)'],
                ['value'=>'adjustment','label'=>'Adjustment'],
                ['value'=>'transfer_in','label'=>'Transfer In'],
                ['value'=>'transfer_out','label'=>'Transfer Out'],
                ['value'=>'initial','label'=>'Initial Stock'],
            ]],
        ];
    }

    public function run(array $filters): ReportResult
    {
        $filters = array_merge($this->getDefaultFilters(), $filters);

        [$start, $end] = $this->parseDateRange($filters);
        $branchId  = $this->branchId($filters);
        $productId = $filters['product_id'] ?? null;
        $type      = $filters['type'] ?? null;

        // Don't cache movement reports (audit data, always fresh)
        $query = DB::table('stock_movements as sm')
            ->join('products as p', 'p.id', '=', 'sm.product_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'sm.variant_id')
            ->whereBetween('sm.created_at', [$start, $end])
            ->when($branchId, fn($q) => $q->where('sm.branch_id', $branchId))
            ->when($productId, fn($q) => $q->where('sm.product_id', $productId))
            ->when($type, fn($q) => $q->where('sm.type', $type))
            ->select(
                'sm.id',
                'sm.created_at',
                DB::raw("COALESCE(pv.sku, p.sku) as sku"),
                'p.name as product_name',
                'sm.type',
                'sm.quantity',
                'sm.cost_at_time',
                'sm.balance_after',
                'sm.reference_type',
                'sm.reference_id',
                'sm.notes',
            )
            ->orderByDesc('sm.created_at')
            ->limit(500);

        $rows = $query->get()->map(fn($r) => [
            'date'           => date('d M Y H:i', strtotime($r->created_at)),
            'product_name'   => $r->product_name,
            'sku'            => $r->sku,
            'type'           => str_replace('_', ' ', $r->type),
            'quantity'       => round((float) $r->quantity, 3),
            'cost_at_time'   => round((float) $r->cost_at_time, 2),
            'balance_after'  => round((float) $r->balance_after, 3),
            'reference'      => $r->reference_type ? "{$r->reference_type} #{$r->reference_id}" : '',
            'notes'          => $r->notes ?? '',
        ]);

        $addedQty   = round($rows->filter(fn($r) => $r['quantity'] > 0)->sum('quantity'), 3);
        $deductedQty= round(abs($rows->filter(fn($r) => $r['quantity'] < 0)->sum('quantity')), 3);

        return new ReportResult(
            summary: [
                $this->card('Total Movements', $rows->count(), 'int'),
                $this->card('Total Added',     $addedQty, 'int'),
                $this->card('Total Deducted',  $deductedQty, 'int'),
            ],
            rows: $rows,
            columns: [
                ['key'=>'date',          'label'=>'Date',          'type'=>'string'],
                ['key'=>'product_name',  'label'=>'Product',       'type'=>'string'],
                ['key'=>'sku',           'label'=>'SKU',           'type'=>'string'],
                ['key'=>'type',          'label'=>'Type',          'type'=>'string'],
                ['key'=>'quantity',      'label'=>'Quantity',      'type'=>'number','align'=>'right'],
                ['key'=>'cost_at_time',  'label'=>'Cost At Time',  'type'=>'money', 'align'=>'right'],
                ['key'=>'balance_after', 'label'=>'Balance After', 'type'=>'number','align'=>'right'],
                ['key'=>'reference',     'label'=>'Reference',     'type'=>'string'],
                ['key'=>'notes',         'label'=>'Notes',         'type'=>'string'],
            ],
            meta: $this->buildMeta($filters, $start, $end, $rows->count()),
        );
    }
}
