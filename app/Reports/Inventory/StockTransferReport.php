<?php

namespace App\Reports\Inventory;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StockTransferReport extends BaseReport
{
    public function getName(): string { return 'Stock Transfers'; }
    public function getCategory(): string { return 'inventory'; }
    public function getDescription(): string { return 'All branch-to-branch stock movements by period.'; }
    public function getRequiredModule(): ?string { return 'stock-reports'; }

    public function getFilterSchema(): array
    {
        return [
            ['key'=>'date_range',     'type'=>'date_range',   'label'=>'Date Range','default'=>'this_month','required'=>true],
            ['key'=>'from_branch_id', 'type'=>'branch_select','label'=>'From Branch'],
            ['key'=>'to_branch_id',   'type'=>'branch_select','label'=>'To Branch'],
            ['key'=>'status',         'type'=>'select',       'label'=>'Status','default'=>'',
             'options'=>[
                ['value'=>'','label'=>'All'],
                ['value'=>'draft','label'=>'Draft'],
                ['value'=>'in_transit','label'=>'In Transit'],
                ['value'=>'received','label'=>'Received'],
                ['value'=>'cancelled','label'=>'Cancelled'],
            ]],
        ];
    }

    public function run(array $filters): ReportResult
    {
        $filters = array_merge($this->getDefaultFilters(), $filters);

        return $this->remember('stock-transfers', $filters, function () use ($filters) {
            [$start, $end] = $this->parseDateRange($filters);

            if (! Schema::hasTable('stock_transfers')) {
                return new ReportResult(meta: $this->buildMeta($filters, $start, $end, 0));
            }

            $fromBranch = $filters['from_branch_id'] ?? null;
            $toBranch   = $filters['to_branch_id'] ?? null;
            $status     = $filters['status'] ?? null;

            $rows = DB::table('stock_transfers as st')
                ->join('branches as fb', 'fb.id', '=', 'st.from_branch_id')
                ->join('branches as tb', 'tb.id', '=', 'st.to_branch_id')
                ->whereBetween('st.transfer_date', [$start->toDateString(), $end->toDateString()])
                ->when($fromBranch, fn($q) => $q->where('st.from_branch_id', $fromBranch))
                ->when($toBranch,   fn($q) => $q->where('st.to_branch_id', $toBranch))
                ->when($status,     fn($q) => $q->where('st.status', $status))
                ->select('st.id','st.transfer_number','st.from_branch_id','st.to_branch_id',
                         'fb.name as from_branch_name','tb.name as to_branch_name',
                         'st.transfer_date','st.received_date','st.status','st.notes')
                ->orderByDesc('st.transfer_date')
                ->get();

            // Item counts per transfer
            $itemCounts = DB::table('stock_transfer_items')
                ->whereIn('stock_transfer_id', $rows->pluck('id')->all())
                ->groupBy('stock_transfer_id')
                ->selectRaw('stock_transfer_id, COUNT(DISTINCT product_id) as product_count, SUM(quantity_sent) as total_qty')
                ->get()->keyBy('stock_transfer_id');

            $enriched = $rows->map(fn($r) => [
                'transfer_number' => $r->transfer_number,
                'from_branch'     => $r->from_branch_name,
                'to_branch'       => $r->to_branch_name,
                'transfer_date'   => $r->transfer_date,
                'received_date'   => $r->received_date ?? '—',
                'status'          => ucfirst(str_replace('_', ' ', $r->status)),
                'product_count'   => (int) ($itemCounts[$r->id]->product_count ?? 0),
                'total_qty'       => round((float) ($itemCounts[$r->id]->total_qty ?? 0), 3),
            ]);

            return new ReportResult(
                summary: [
                    $this->card('Total Transfers', $enriched->count(), 'int'),
                    $this->card('In Transit',      $enriched->filter(fn($r) => strtolower($r['status']) === 'in transit')->count(), 'int'),
                    $this->card('Total Items Transferred', round($enriched->sum('total_qty'), 0), 'int'),
                ],
                rows: $enriched,
                columns: [
                    ['key'=>'transfer_number','label'=>'Transfer #',   'type'=>'string'],
                    ['key'=>'from_branch',    'label'=>'From Branch',  'type'=>'string'],
                    ['key'=>'to_branch',      'label'=>'To Branch',    'type'=>'string'],
                    ['key'=>'transfer_date',  'label'=>'Date',         'type'=>'date'],
                    ['key'=>'received_date',  'label'=>'Received',     'type'=>'string'],
                    ['key'=>'status',         'label'=>'Status',       'type'=>'string'],
                    ['key'=>'product_count',  'label'=>'Products',     'type'=>'int',  'align'=>'right','total'=>true],
                    ['key'=>'total_qty',      'label'=>'Total Qty',    'type'=>'number','align'=>'right','total'=>true],
                ],
                totals: ['product_count'=>$enriched->sum('product_count'),'total_qty'=>round($enriched->sum('total_qty'),3)],
                meta: $this->buildMeta($filters, $start, $end, $enriched->count()),
            );
        });
    }
}
