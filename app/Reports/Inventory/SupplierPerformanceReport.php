<?php

namespace App\Reports\Inventory;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SupplierPerformanceReport extends BaseReport
{
    public function getName(): string { return 'Supplier Performance'; }
    public function getCategory(): string { return 'inventory'; }
    public function getDescription(): string { return 'Supplier reliability: delivery time, fulfilment rate, spend.'; }
    public function getRequiredModule(): ?string { return 'stock-reports'; }

    public function getFilterSchema(): array
    {
        return [
            ['key'=>'date_range','type'=>'date_range','label'=>'Date Range','default'=>'this_year','required'=>true],
        ];
    }

    public function run(array $filters): ReportResult
    {
        $filters = array_merge($this->getDefaultFilters(), $filters);

        return $this->remember('supplier-performance', $filters, function () use ($filters) {
            [$start, $end] = $this->parseDateRange($filters);

            if (! Schema::hasTable('purchase_orders') || ! Schema::hasTable('suppliers')) {
                return new ReportResult(meta: $this->buildMeta($filters, $start, $end, 0));
            }

            $pos = DB::table('purchase_orders as po')
                ->join('suppliers as s', 's.id', '=', 'po.supplier_id')
                ->whereBetween('po.order_date', [$start->toDateString(), $end->toDateString()])
                ->select('po.*', 's.name as supplier_name')
                ->get();

            // GRN receive dates per PO
            $grnDates = Schema::hasTable('grns')
                ? DB::table('grns')
                    ->whereNotNull('purchase_order_id')
                    ->where('status', 'received')
                    ->groupBy('purchase_order_id')
                    ->selectRaw('purchase_order_id, MIN(received_date) as first_received')
                    ->pluck('first_received', 'purchase_order_id')
                : collect();

            // Items ordered vs received per PO
            $itemsData = Schema::hasTable('purchase_order_items')
                ? DB::table('purchase_order_items')
                    ->whereIn('purchase_order_id', $pos->pluck('id'))
                    ->groupBy('purchase_order_id')
                    ->selectRaw('purchase_order_id, SUM(quantity_ordered) as ordered, SUM(quantity_received) as received')
                    ->get()->keyBy('purchase_order_id')
                : collect();

            $bySupplier = $pos->groupBy('supplier_id')->map(function ($supplierPos, $sid) use ($grnDates, $itemsData) {
                $name    = $supplierPos->first()->supplier_name;
                $poCount = $supplierPos->count();
                $spend   = round($supplierPos->sum('total'), 2);

                // Avg fulfillment days
                $fulfillmentDays = $supplierPos->filter(fn($po) => isset($grnDates[$po->id]))->map(function ($po) use ($grnDates) {
                    return max(0, date_diff(date_create($po->order_date), date_create($grnDates[$po->id]))->days);
                });
                $avgFulfillment = $fulfillmentDays->count() > 0 ? round($fulfillmentDays->avg(), 1) : null;

                // Qty fulfilment rate
                $totalOrdered  = $supplierPos->sum(fn($po) => (float) ($itemsData[$po->id]->ordered ?? 0));
                $totalReceived = $supplierPos->sum(fn($po) => (float) ($itemsData[$po->id]->received ?? 0));
                $fulfilmentRate = $totalOrdered > 0 ? round($totalReceived / $totalOrdered * 100, 1) : null;

                return [
                    'supplier_name'    => $name,
                    'po_count'         => $poCount,
                    'total_spend'      => $spend,
                    'received_count'   => $supplierPos->filter(fn($po) => $po->status === 'received')->count(),
                    'avg_fulfil_days'  => $avgFulfillment,
                    'qty_fulfilment_pct' => $fulfilmentRate,
                ];
            })->sortByDesc('total_spend')->values();

            return new ReportResult(
                summary: [
                    $this->card('Active Suppliers', $bySupplier->count(), 'int'),
                    $this->card('Total Spend',       $bySupplier->sum('total_spend'), 'money'),
                    $this->card('Avg Delivery Days', round($bySupplier->whereNotNull('avg_fulfil_days')->avg('avg_fulfil_days') ?? 0, 1), 'int'),
                ],
                rows: $bySupplier,
                columns: [
                    ['key'=>'supplier_name',     'label'=>'Supplier',        'type'=>'string'],
                    ['key'=>'po_count',           'label'=>'POs',             'type'=>'int',   'align'=>'right','total'=>true],
                    ['key'=>'total_spend',        'label'=>'Total Spend',     'type'=>'money', 'align'=>'right','total'=>true],
                    ['key'=>'received_count',     'label'=>'Fully Received',  'type'=>'int',   'align'=>'right'],
                    ['key'=>'avg_fulfil_days',    'label'=>'Avg Delivery Days','type'=>'number','align'=>'right'],
                    ['key'=>'qty_fulfilment_pct', 'label'=>'Qty Fulfilment %','type'=>'percent','align'=>'right'],
                ],
                totals: ['po_count'=>$bySupplier->sum('po_count'),'total_spend'=>round($bySupplier->sum('total_spend'),2)],
                meta: $this->buildMeta($filters, $start, $end, $bySupplier->count()),
            );
        });
    }
}
