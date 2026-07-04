<?php

namespace App\Reports\Financial;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CashFlowReport extends BaseReport
{
    public function getName(): string { return 'Cash Flow'; }
    public function getCategory(): string { return 'financial'; }
    public function getDescription(): string { return 'Cash in vs cash out — daily cash position.'; }
    public function getRequiredModule(): ?string { return 'financial-reports'; }

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

        return $this->remember('cash-flow', $filters, function () use ($filters) {
            [$start, $end] = $this->parseDateRange($filters);
            $branchId = $this->branchId($filters);

            // CASH IN: cash sales
            $cashSales = (float) DB::table('sale_payments as sp')
                ->join('sales as s','s.id','=','sp.sale_id')
                ->where('s.status','completed')
                ->where('sp.method','cash')
                ->whereBetween('s.sale_date',[$start->toDateString(),$end->toDateString()])
                ->when($branchId, fn($q) => $q->where('s.branch_id',$branchId))
                ->sum('sp.amount');

            // Credit payments received in cash
            $creditPaymentsCash = Schema::hasTable('credit_transactions')
                ? (float) DB::table('credit_transactions')
                    ->where('type','payment_received')
                    ->where('payment_method','cash')
                    ->whereBetween('created_at',[$start->startOfDay(),$end->endOfDay()])
                    ->sum(DB::raw('ABS(amount)'))
                : 0;

            $totalCashIn = $cashSales + $creditPaymentsCash;

            // CASH OUT: cash refunds
            $cashRefunds = Schema::hasTable('sale_returns')
                ? (float) DB::table('sale_returns')
                    ->where('status','completed')
                    ->where('refund_method','cash')
                    ->whereBetween('return_date',[$start->toDateString(),$end->toDateString()])
                    ->when($branchId, fn($q) => $q->where('branch_id',$branchId))
                    ->sum('refund_amount')
                : 0;

            // Expenses paid in cash
            $expensesCash = Schema::hasTable('expenses')
                ? (float) DB::table('expenses')
                    ->where('payment_method','cash')
                    ->whereBetween('expense_date',[$start->toDateString(),$end->toDateString()])
                    ->when($branchId, fn($q) => $q->where('branch_id',$branchId))
                    ->whereNull('deleted_at')
                    ->sum('amount')
                : 0;

            $totalCashOut = $cashRefunds + $expensesCash;
            $netCashFlow  = $totalCashIn - $totalCashOut;

            // Daily breakdown
            $dailyCashIn = DB::table('sale_payments as sp')
                ->join('sales as s','s.id','=','sp.sale_id')
                ->where('s.status','completed')
                ->where('sp.method','cash')
                ->whereBetween('s.sale_date',[$start->toDateString(),$end->toDateString()])
                ->when($branchId, fn($q) => $q->where('s.branch_id',$branchId))
                ->groupBy('s.sale_date')
                ->selectRaw('s.sale_date as date, SUM(sp.amount) as cash_in')
                ->pluck('cash_in','date');

            $dailyCashOut = Schema::hasTable('expenses')
                ? DB::table('expenses')
                    ->where('payment_method','cash')
                    ->whereBetween('expense_date',[$start->toDateString(),$end->toDateString()])
                    ->when($branchId, fn($q) => $q->where('branch_id',$branchId))
                    ->whereNull('deleted_at')
                    ->groupBy('expense_date')
                    ->selectRaw('expense_date as date, SUM(amount) as cash_out')
                    ->pluck('cash_out','date')
                : collect();

            // Merge daily data
            $allDates = collect($dailyCashIn->keys()->merge($dailyCashOut->keys())->unique()->sort()->values());
            $rows = $allDates->map(fn($date) => [
                'date'        => $date,
                'cash_in'     => round((float)($dailyCashIn[$date] ?? 0), 2),
                'cash_out'    => round((float)($dailyCashOut[$date] ?? 0), 2),
                'net'         => round((float)($dailyCashIn[$date] ?? 0) - (float)($dailyCashOut[$date] ?? 0), 2),
            ]);

            $chartData = [
                'type'   => 'area',
                'labels' => $rows->pluck('date')->map(fn($d) => date('d M',strtotime($d)))->all(),
                'series' => [
                    ['name'=>'Cash In',  'data'=>$rows->pluck('cash_in')->all()],
                    ['name'=>'Cash Out', 'data'=>$rows->pluck('cash_out')->all()],
                ],
            ];

            $summaryRows = collect([
                ['label'=>'CASH IN',                     'amount'=>'',          'notes'=>''],
                ['label'=>'  Cash Sales',                'amount'=>$cashSales,  'notes'=>''],
                ['label'=>'  Credit Payments (Cash)',    'amount'=>$creditPaymentsCash,'notes'=>''],
                ['label'=>'TOTAL CASH IN',               'amount'=>$totalCashIn,'notes'=>''],
                ['label'=>'',                            'amount'=>'',          'notes'=>''],
                ['label'=>'CASH OUT',                    'amount'=>'',          'notes'=>''],
                ['label'=>'  Cash Refunds',              'amount'=>$cashRefunds,'notes'=>''],
                ['label'=>'  Expenses (Cash)',           'amount'=>$expensesCash,'notes'=>''],
                ['label'=>'TOTAL CASH OUT',              'amount'=>$totalCashOut,'notes'=>''],
                ['label'=>'',                            'amount'=>'',          'notes'=>''],
                ['label'=>'NET CASH FLOW',               'amount'=>$netCashFlow,'notes'=>$netCashFlow >= 0 ? 'Surplus' : 'Deficit'],
            ]);

            return new ReportResult(
                summary: [
                    $this->card('Total Cash In',  $totalCashIn,  'money'),
                    $this->card('Total Cash Out', $totalCashOut, 'money'),
                    $this->card('Net Cash Flow',  $netCashFlow,  'money'),
                ],
                rows: $summaryRows,
                columns: [
                    ['key'=>'label',  'label'=>'Item',   'type'=>'string'],
                    ['key'=>'amount', 'label'=>'Amount', 'type'=>'money','align'=>'right'],
                    ['key'=>'notes',  'label'=>'Notes',  'type'=>'string'],
                ],
                chart_data: $chartData,
                meta: $this->buildMeta($filters, $start, $end, $rows->count()),
            );
        });
    }
}
