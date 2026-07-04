<?php

namespace App\Reports\Financial;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * End-of-day summary — designed for single-date thermal print.
 */
class DailySummaryReport extends BaseReport
{
    public function getName(): string { return 'Daily Summary'; }
    public function getCategory(): string { return 'financial'; }
    public function getDescription(): string { return 'End-of-day summary: sales, payments, cash drawer, top products.'; }
    public function getRequiredModule(): ?string { return 'financial-reports'; }

    public function getFilterSchema(): array
    {
        return [
            ['key'=>'date',      'type'=>'date',         'label'=>'Date',   'default'=>now()->toDateString(),'required'=>true],
            ['key'=>'branch_id', 'type'=>'branch_select','label'=>'Branch'],
        ];
    }

    public function getDefaultFilters(): array
    {
        return ['date' => now()->toDateString(), 'branch_id' => null];
    }

    public function run(array $filters): ReportResult
    {
        $filters  = array_merge($this->getDefaultFilters(), $filters);
        $date     = $filters['date'] ?? now()->toDateString();
        $branchId = $this->branchId($filters);

        // No caching — daily summary is always fresh
        $salesBase = DB::table('sales')
            ->where('status', 'completed')
            ->whereDate('sale_date', $date)
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId));

        $grossRevenue  = (float) (clone $salesBase)->sum('total');
        $txnCount      = (int)   (clone $salesBase)->count();
        $taxCollected  = (float) (clone $salesBase)->sum('tax_amount');
        $discounts     = (float) (clone $salesBase)->sum('discount_amount');

        // Returns
        $returnsAmount = Schema::hasTable('sale_returns')
            ? (float) DB::table('sale_returns')
                ->where('status', 'completed')
                ->whereDate('return_date', $date)
                ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
                ->sum('refund_amount')
            : 0;

        $netRevenue = $grossRevenue - $returnsAmount;

        // Payment breakdown
        $payments = DB::table('sale_payments as sp')
            ->join('sales as s', 's.id', '=', 'sp.sale_id')
            ->where('s.status', 'completed')
            ->whereDate('s.sale_date', $date)
            ->when($branchId, fn($q) => $q->where('s.branch_id', $branchId))
            ->groupBy('sp.method')
            ->selectRaw('sp.method, SUM(sp.amount) as total')
            ->get();

        // Top 5 products
        $top5 = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.status', 'completed')
            ->whereDate('s.sale_date', $date)
            ->when($branchId, fn($q) => $q->where('s.branch_id', $branchId))
            ->groupBy('si.product_name')
            ->selectRaw('si.product_name, SUM(si.quantity) as qty, SUM(si.line_total) as revenue')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get();

        // Cash drawer session
        $drawer = Schema::hasTable('cash_drawer_sessions')
            ? DB::table('cash_drawer_sessions')
                ->whereDate('opened_at', $date)
                ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
                ->orderByDesc('opened_at')
                ->first()
            : null;

        // Expenses for the day
        $expenses = Schema::hasTable('expenses')
            ? (float) DB::table('expenses')
                ->whereDate('expense_date', $date)
                ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
                ->whereNull('deleted_at')
                ->sum('amount')
            : 0;

        // Build structured rows for the report
        $rows = collect();

        // Sales section
        $rows->push(['section'=>'SALES SUMMARY', 'label'=>'Transactions',         'value'=>$txnCount,    'fmt'=>'int']);
        $rows->push(['section'=>'',              'label'=>'Gross Revenue',         'value'=>$grossRevenue,'fmt'=>'money']);
        $rows->push(['section'=>'',              'label'=>'Discounts Given',       'value'=>$discounts,   'fmt'=>'money']);
        $rows->push(['section'=>'',              'label'=>'Tax Collected',         'value'=>$taxCollected,'fmt'=>'money']);
        $rows->push(['section'=>'',              'label'=>'Returns & Refunds',     'value'=>$returnsAmount,'fmt'=>'money']);
        $rows->push(['section'=>'',              'label'=>'Net Revenue',           'value'=>$netRevenue,  'fmt'=>'money']);

        // Payments
        foreach ($payments as $p) {
            $rows->push(['section'=>'PAYMENT BREAKDOWN',
                'label'=> ucfirst(str_replace('_',' ', $p->method)),
                'value'=> round((float)$p->total, 2), 'fmt'=>'money']);
        }

        // Cash drawer
        if ($drawer) {
            $rows->push(['section'=>'CASH DRAWER',
                'label'=>'Opening Balance', 'value'=>round((float)$drawer->opening_balance,2),'fmt'=>'money']);
            if ($drawer->closing_balance !== null) {
                $rows->push(['section'=>'','label'=>'Closing Balance',  'value'=>round((float)$drawer->closing_balance,2),'fmt'=>'money']);
                $rows->push(['section'=>'','label'=>'Expected Balance', 'value'=>round((float)$drawer->expected_balance,2),'fmt'=>'money']);
                $rows->push(['section'=>'','label'=>'Over / Short',     'value'=>round((float)$drawer->over_short,2),'fmt'=>'money']);
            }
        }

        if ($expenses > 0) {
            $rows->push(['section'=>'EXPENSES','label'=>'Cash Expenses Paid','value'=>$expenses,'fmt'=>'money']);
        }

        // Top 5 products
        foreach ($top5 as $p) {
            $rows->push(['section'=>'TOP 5 PRODUCTS',
                'label' => mb_substr($p->product_name, 0, 35),
                'value' => round((float) $p->revenue, 2),
                'fmt'   => 'money']);
        }

        return new ReportResult(
            summary: [
                $this->card('Date',         $date,        'string'),
                $this->card('Transactions', $txnCount,    'int'),
                $this->card('Net Revenue',  $netRevenue,  'money'),
                $this->card('Tax Collected',$taxCollected,'money'),
            ],
            rows: $rows,
            columns: [
                ['key'=>'section','label'=>'Section', 'type'=>'string'],
                ['key'=>'label',  'label'=>'Item',    'type'=>'string'],
                ['key'=>'value',  'label'=>'Value',   'type'=>'money','align'=>'right'],
            ],
            meta: array_merge(['report_date'=>$date], $this->buildMeta($filters, Carbon::parse($date), Carbon::parse($date), $rows->count())),
        );
    }
}
