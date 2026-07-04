<?php

namespace App\Reports\Sales;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;

class SalesByCashierReport extends BaseReport
{
    public function getName(): string { return 'Sales by Cashier'; }
    public function getCategory(): string { return 'sales'; }
    public function getDescription(): string { return 'Staff performance by transactions and revenue.'; }
    public function getRequiredModule(): ?string { return 'staff-reports'; }
    public function getRequiredPermission(): ?string { return 'view-staff-reports'; }

    public function getFilterSchema(): array
    {
        return [
            ['key'=>'date_range','type'=>'date_range','label'=>'Date Range','default'=>'this_month','required'=>true],
            ['key'=>'branch_id', 'type'=>'branch_select','label'=>'Branch'],
        ];
    }

    public function run(array $filters): ReportResult
    {
        $filters = array_merge($this->getDefaultFilters(), $filters);

        return $this->remember('sales-by-cashier', $filters, function () use ($filters) {
            [$start, $end] = $this->parseDateRange($filters);
            $branchId = $this->branchId($filters);

            // Aggregate from tenant DB
            $cashierStats = $this->salesBase($start, $end, $branchId)
                ->groupBy('cashier_id')
                ->selectRaw("
                    cashier_id,
                    COUNT(*) as transactions,
                    SUM(total) as revenue,
                    SUM(discount_amount) as discounts
                ")
                ->orderByDesc('revenue')
                ->get();

            // Look up cashier names from central DB
            $cashierIds = $cashierStats->pluck('cashier_id')->filter()->all();
            $userNames  = DB::connection('mysql')->table('users')
                ->whereIn('id', $cashierIds)
                ->pluck('name', 'id');

            // Item counts per cashier
            $itemsByCashier = DB::table('sale_items as si')
                ->join('sales as s', 's.id', '=', 'si.sale_id')
                ->where('s.status', 'completed')
                ->whereBetween('s.sale_date', [$start->toDateString(), $end->toDateString()])
                ->when($branchId, fn($q) => $q->where('s.branch_id', $branchId))
                ->groupBy('s.cashier_id')
                ->selectRaw('s.cashier_id, SUM(si.quantity) as items_sold')
                ->pluck('items_sold', 'cashier_id');

            // Returns processed
            $returnsByCashier = DB::getSchemaBuilder()->hasTable('sale_returns')
                ? DB::table('sale_returns')
                    ->where('status', 'completed')
                    ->whereBetween('return_date', [$start->toDateString(), $end->toDateString()])
                    ->groupBy('cashier_id')
                    ->selectRaw('cashier_id, COUNT(*) as returns_count')
                    ->pluck('returns_count', 'cashier_id')
                : collect();

            $rows = $cashierStats->map(function ($r) use ($userNames, $itemsByCashier, $returnsByCashier) {
                $txn    = (int) $r->transactions;
                $rev    = (float) $r->revenue;
                $items  = (float) ($itemsByCashier[$r->cashier_id] ?? 0);
                return [
                    'cashier_name'       => $userNames[$r->cashier_id] ?? "Cashier #{$r->cashier_id}",
                    'transactions'       => $txn,
                    'revenue'            => round($rev, 2),
                    'items_sold'         => round($items, 0),
                    'avg_ticket'         => $txn > 0 ? round($rev / $txn, 2) : 0,
                    'avg_items_per_txn'  => $txn > 0 ? round($items / $txn, 1) : 0,
                    'discounts_given'    => round((float) $r->discounts, 2),
                    'returns_processed'  => (int) ($returnsByCashier[$r->cashier_id] ?? 0),
                ];
            });

            $chartData = [
                'type'   => 'bar',
                'labels' => $rows->pluck('cashier_name')->all(),
                'series' => [['name' => 'Revenue', 'data' => $rows->pluck('revenue')->all()]],
            ];

            return new ReportResult(
                summary: [
                    $this->card('Total Revenue',      $rows->sum('revenue'), 'money'),
                    $this->card('Total Transactions', $rows->sum('transactions'), 'int'),
                    $this->card('Active Cashiers',    $rows->count(), 'int'),
                ],
                rows: $rows,
                columns: [
                    ['key'=>'cashier_name',      'label'=>'Cashier',       'type'=>'string'],
                    ['key'=>'transactions',       'label'=>'Transactions',  'type'=>'int',  'align'=>'right','total'=>true],
                    ['key'=>'revenue',            'label'=>'Revenue',       'type'=>'money','align'=>'right','total'=>true],
                    ['key'=>'items_sold',         'label'=>'Items Sold',    'type'=>'int',  'align'=>'right','total'=>true],
                    ['key'=>'avg_ticket',         'label'=>'Avg Ticket',    'type'=>'money','align'=>'right'],
                    ['key'=>'avg_items_per_txn',  'label'=>'Avg Items/Txn', 'type'=>'number','align'=>'right'],
                    ['key'=>'discounts_given',    'label'=>'Discounts',     'type'=>'money','align'=>'right','total'=>true],
                    ['key'=>'returns_processed',  'label'=>'Returns',       'type'=>'int',  'align'=>'right','total'=>true],
                ],
                totals: ['transactions'=>$rows->sum('transactions'),'revenue'=>round($rows->sum('revenue'),2),'items_sold'=>$rows->sum('items_sold'),'discounts_given'=>round($rows->sum('discounts_given'),2),'returns_processed'=>$rows->sum('returns_processed')],
                chart_data: $chartData,
                meta: $this->buildMeta($filters, $start, $end, $rows->count()),
            );
        });
    }
}
