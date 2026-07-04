<?php

namespace App\Reports\Sales;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;

class SalesByPaymentMethodReport extends BaseReport
{
    public function getName(): string { return 'Sales by Payment Method'; }
    public function getCategory(): string { return 'sales'; }
    public function getDescription(): string { return 'How customers pay — cash, card, JazzCash, credit, etc.'; }
    public function getRequiredModule(): ?string { return 'sales-reports'; }

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

        return $this->remember('sales-by-payment', $filters, function () use ($filters) {
            [$start, $end] = $this->parseDateRange($filters);
            $branchId = $this->branchId($filters);

            $rows = DB::table('sale_payments as sp')
                ->join('sales as s', 's.id', '=', 'sp.sale_id')
                ->where('s.status', 'completed')
                ->whereBetween('s.sale_date', [$start->toDateString(), $end->toDateString()])
                ->when($branchId, fn($q) => $q->where('s.branch_id', $branchId))
                ->groupBy('sp.method')
                ->selectRaw("
                    sp.method,
                    COUNT(DISTINCT s.id) as transactions,
                    SUM(sp.amount) as amount
                ")
                ->orderByDesc('amount')
                ->get();

            $total = (float) $rows->sum('amount');

            $enriched = $rows->map(fn($r) => [
                'method'        => ucfirst(str_replace('_', ' ', $r->method)),
                'method_slug'   => $r->method,
                'transactions'  => (int) $r->transactions,
                'amount'        => round((float) $r->amount, 2),
                'pct_of_total'  => $total > 0 ? round((float) $r->amount / $total * 100, 1) : 0,
            ]);

            $chartData = [
                'type'   => 'pie',
                'labels' => $enriched->pluck('method')->all(),
                'series' => [['name' => 'Amount', 'data' => $enriched->pluck('amount')->all()]],
            ];

            return new ReportResult(
                summary: [
                    $this->card('Total Payments', $total, 'money'),
                    $this->card('Payment Methods', $enriched->count(), 'int'),
                    $this->card('Top Method', $enriched->first()['method'] ?? '—', 'string'),
                ],
                rows: $enriched,
                columns: [
                    ['key'=>'method',       'label'=>'Method',        'type'=>'string'],
                    ['key'=>'transactions', 'label'=>'Transactions',  'type'=>'int',  'align'=>'right','total'=>true],
                    ['key'=>'amount',       'label'=>'Amount',        'type'=>'money','align'=>'right','total'=>true],
                    ['key'=>'pct_of_total', 'label'=>'% of Total',   'type'=>'percent','align'=>'right'],
                ],
                totals: ['transactions'=>$enriched->sum('transactions'),'amount'=>round($total,2)],
                chart_data: $chartData,
                meta: $this->buildMeta($filters, $start, $end, $enriched->count()),
            );
        });
    }
}
