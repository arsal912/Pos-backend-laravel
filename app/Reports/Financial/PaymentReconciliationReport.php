<?php

namespace App\Reports\Financial;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PaymentReconciliationReport extends BaseReport
{
    public function getName(): string { return 'Payment Reconciliation'; }
    public function getCategory(): string { return 'financial'; }
    public function getDescription(): string { return 'Match gateway totals to internal records by payment method.'; }
    public function getRequiredModule(): ?string { return 'financial-reports'; }

    public function getFilterSchema(): array
    {
        return [
            ['key'=>'date_range',     'type'=>'date_range',   'label'=>'Date Range','default'=>'this_month','required'=>true],
            ['key'=>'branch_id',      'type'=>'branch_select','label'=>'Branch'],
            ['key'=>'payment_method', 'type'=>'select',       'label'=>'Payment Method','default'=>'',
             'options'=>[
                ['value'=>'','label'=>'All Methods'],
                ['value'=>'cash','label'=>'Cash'],
                ['value'=>'card','label'=>'Card'],
                ['value'=>'jazzcash','label'=>'JazzCash'],
                ['value'=>'easypaisa','label'=>'Easypaisa'],
                ['value'=>'bank_transfer','label'=>'Bank Transfer'],
                ['value'=>'loyalty_points','label'=>'Loyalty Points'],
                ['value'=>'on_credit','label'=>'On Credit'],
            ]],
        ];
    }

    public function run(array $filters): ReportResult
    {
        $filters = array_merge($this->getDefaultFilters(), $filters);

        return $this->remember('payment-reconciliation', $filters, function () use ($filters) {
            [$start, $end] = $this->parseDateRange($filters);
            $branchId     = $this->branchId($filters);
            $method       = $filters['payment_method'] ?? null;

            // Summary by method
            $byMethod = DB::table('sale_payments as sp')
                ->join('sales as s', 's.id', '=', 'sp.sale_id')
                ->where('s.status', 'completed')
                ->whereBetween('s.sale_date', [$start->toDateString(), $end->toDateString()])
                ->when($branchId, fn($q) => $q->where('s.branch_id', $branchId))
                ->when($method, fn($q) => $q->where('sp.method', $method))
                ->groupBy('sp.method')
                ->selectRaw("
                    sp.method,
                    COUNT(DISTINCT s.id) as txn_count,
                    SUM(sp.amount) as total_amount,
                    MIN(sp.amount) as min_amount,
                    MAX(sp.amount) as max_amount,
                    AVG(sp.amount) as avg_amount
                ")
                ->orderByDesc('total_amount')
                ->get()
                ->map(fn($r) => [
                    'method'       => ucfirst(str_replace('_', ' ', $r->method)),
                    'method_slug'  => $r->method,
                    'txn_count'    => (int) $r->txn_count,
                    'total_amount' => round((float) $r->total_amount, 2),
                    'min_amount'   => round((float) $r->min_amount, 2),
                    'max_amount'   => round((float) $r->max_amount, 2),
                    'avg_amount'   => round((float) $r->avg_amount, 2),
                ]);

            // Detail rows
            $details = DB::table('sale_payments as sp')
                ->join('sales as s', 's.id', '=', 'sp.sale_id')
                ->where('s.status', 'completed')
                ->whereBetween('s.sale_date', [$start->toDateString(), $end->toDateString()])
                ->when($branchId, fn($q) => $q->where('s.branch_id', $branchId))
                ->when($method, fn($q) => $q->where('sp.method', $method))
                ->select(
                    's.sale_number',
                    's.sale_date',
                    'sp.method',
                    'sp.amount',
                    'sp.reference',
                )
                ->orderByDesc('s.sale_date')
                ->limit(500)
                ->get()
                ->map(fn($r) => [
                    'sale_number' => $r->sale_number,
                    'sale_date'   => $r->sale_date,
                    'method'      => ucfirst(str_replace('_', ' ', $r->method)),
                    'amount'      => round((float) $r->amount, 2),
                    'reference'   => $r->reference ?? '—',
                ]);

            $grandTotal = round($byMethod->sum('total_amount'), 2);

            // Credit payments received (from credit_transactions)
            $creditReceived = [];
            if (Schema::hasTable('credit_transactions')) {
                $creditReceived = DB::table('credit_transactions')
                    ->where('type', 'payment_received')
                    ->whereBetween('created_at', [$start, $end])
                    ->groupBy('payment_method')
                    ->selectRaw("COALESCE(payment_method,'other') as method, COUNT(*) as count, SUM(ABS(amount)) as total")
                    ->get()
                    ->map(fn($r) => [
                        'method'       => 'Credit Payment (' . ucfirst($r->method) . ')',
                        'txn_count'    => (int) $r->count,
                        'total_amount' => round((float) $r->total, 2),
                        'min_amount'   => 0, 'max_amount'=>0, 'avg_amount'=>0,
                    ])->all();
            }

            $allRows = $byMethod->concat($creditReceived)->values();

            $chartData = [
                'type'   => 'bar',
                'labels' => $byMethod->pluck('method')->all(),
                'series' => [['name'=>'Total Amount','data'=>$byMethod->pluck('total_amount')->all()]],
            ];

            return new ReportResult(
                summary: [
                    $this->card('Total Collected',   $grandTotal, 'money'),
                    $this->card('Payment Methods',   $byMethod->count(), 'int'),
                    $this->card('Top Method',        $byMethod->first()['method'] ?? '—', 'string'),
                ],
                rows: $allRows,
                columns: [
                    ['key'=>'method',       'label'=>'Method',       'type'=>'string'],
                    ['key'=>'txn_count',    'label'=>'Transactions', 'type'=>'int',  'align'=>'right','total'=>true],
                    ['key'=>'total_amount', 'label'=>'Total',        'type'=>'money','align'=>'right','total'=>true],
                    ['key'=>'avg_amount',   'label'=>'Avg',          'type'=>'money','align'=>'right'],
                    ['key'=>'min_amount',   'label'=>'Min',          'type'=>'money','align'=>'right'],
                    ['key'=>'max_amount',   'label'=>'Max',          'type'=>'money','align'=>'right'],
                ],
                totals: ['txn_count'=>$allRows->sum('txn_count'),'total_amount'=>round($allRows->sum('total_amount'),2)],
                chart_data: $chartData,
                meta: $this->buildMeta($filters, $start, $end, $allRows->count()),
            );
        });
    }
}
