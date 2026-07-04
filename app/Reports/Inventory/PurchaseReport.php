<?php

namespace App\Reports\Inventory;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PurchaseReport extends BaseReport
{
    public function getName(): string { return 'Purchase Analysis'; }
    public function getCategory(): string { return 'inventory'; }
    public function getDescription(): string { return 'Purchase orders by supplier, date, and category.'; }
    public function getRequiredModule(): ?string { return 'stock-reports'; }

    public function getFilterSchema(): array
    {
        return [
            ['key'=>'date_range',  'type'=>'date_range',   'label'=>'Date Range','default'=>'this_month','required'=>true],
            ['key'=>'branch_id',   'type'=>'branch_select','label'=>'Branch'],
            ['key'=>'supplier_id', 'type'=>'select',       'label'=>'Supplier','options'=>[]],
            ['key'=>'status',      'type'=>'select',       'label'=>'Status','default'=>'',
             'options'=>[
                ['value'=>'','label'=>'All'],
                ['value'=>'draft','label'=>'Draft'],
                ['value'=>'sent','label'=>'Sent'],
                ['value'=>'received','label'=>'Received'],
                ['value'=>'cancelled','label'=>'Cancelled'],
            ]],
        ];
    }

    public function run(array $filters): ReportResult
    {
        $filters = array_merge($this->getDefaultFilters(), $filters);

        return $this->remember('purchase-analysis', $filters, function () use ($filters) {
            [$start, $end] = $this->parseDateRange($filters);
            $branchId   = $this->branchId($filters);
            $supplierId = $filters['supplier_id'] ?? null;
            $status     = $filters['status'] ?? null;

            if (! Schema::hasTable('purchase_orders')) {
                return new ReportResult(meta: $this->buildMeta($filters, $start, $end, 0));
            }

            $rows = DB::table('purchase_orders as po')
                ->leftJoin('suppliers as s', 's.id', '=', 'po.supplier_id')
                ->whereBetween('po.order_date', [$start->toDateString(), $end->toDateString()])
                ->when($branchId, fn($q) => $q->where('po.branch_id', $branchId))
                ->when($supplierId, fn($q) => $q->where('po.supplier_id', $supplierId))
                ->when($status, fn($q) => $q->where('po.status', $status))
                ->select(
                    'po.po_number',
                    DB::raw("COALESCE(s.name,'—') as supplier_name"),
                    'po.order_date',
                    'po.status',
                    'po.total',
                    'po.notes',
                )
                ->orderByDesc('po.order_date')
                ->get()
                ->map(fn($r) => [
                    'po_number'     => $r->po_number,
                    'supplier_name' => $r->supplier_name,
                    'order_date'    => $r->order_date,
                    'status'        => ucfirst($r->status),
                    'total'         => round((float) $r->total, 2),
                ]);

            // By supplier breakdown
            $bySupplier = $rows->groupBy('supplier_name')->map(fn($g, $s) => [
                'supplier_name' => $s,
                'po_count'      => $g->count(),
                'total_spend'   => round($g->sum('total'), 2),
            ])->values()->sortByDesc('total_spend')->values();

            // Monthly trend
            $monthly = $rows->groupBy(fn($r) => substr($r['order_date'], 0, 7))
                ->map(fn($g, $m) => ['month'=>$m,'total'=>round($g->sum('total'),2)])
                ->values()
                ->sortBy('month')->values();

            $chartData = [
                'type'   => 'bar',
                'labels' => $monthly->pluck('month')->all(),
                'series' => [['name'=>'Purchase Total','data'=>$monthly->pluck('total')->all()]],
            ];

            return new ReportResult(
                summary: [
                    $this->card('Total POs',     $rows->count(), 'int'),
                    $this->card('Total Spend',   $rows->sum('total'), 'money'),
                    $this->card('Received POs',  $rows->filter(fn($r) => strtolower($r['status']) === 'received')->count(), 'int'),
                ],
                rows: $rows,
                columns: [
                    ['key'=>'po_number',    'label'=>'PO #',     'type'=>'string'],
                    ['key'=>'supplier_name','label'=>'Supplier', 'type'=>'string'],
                    ['key'=>'order_date',   'label'=>'Date',     'type'=>'date'],
                    ['key'=>'status',       'label'=>'Status',   'type'=>'string'],
                    ['key'=>'total',        'label'=>'Total',    'type'=>'money','align'=>'right','total'=>true],
                ],
                totals: ['total'=>round($rows->sum('total'),2)],
                chart_data: $chartData,
                meta: $this->buildMeta($filters, $start, $end, $rows->count()),
            );
        });
    }
}
