<?php

namespace App\Reports\Financial;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Profit & Loss Statement
 *
 * Structure:
 *   REVENUE        = Gross Sales - Returns
 *   COGS           = sum(sale_items.cost_at_time × quantity) - restocked returns
 *   GROSS PROFIT   = Revenue - COGS
 *   EXPENSES       = sum(expenses.amount) [if expenses table exists]
 *   NET PROFIT     = Gross Profit - Expenses
 */
class ProfitLossReport extends BaseReport
{
    public function getName(): string { return 'Profit & Loss'; }
    public function getCategory(): string { return 'financial'; }
    public function getDescription(): string { return 'P&L statement: revenue, COGS, gross profit, expenses, net profit.'; }
    public function getRequiredModule(): ?string { return 'profit-loss'; }
    public function getRequiredPermission(): ?string { return 'view-profit-loss'; }

    public function getFilterSchema(): array
    {
        return [
            ['key'=>'date_range','type'=>'date_range','label'=>'Date Range','default'=>'this_month','required'=>true],
            ['key'=>'branch_id', 'type'=>'branch_select','label'=>'Branch'],
            ['key'=>'compare',   'type'=>'select','label'=>'Compare','default'=>'0',
             'options'=>[['value'=>'0','label'=>'No comparison'],['value'=>'1','label'=>'vs Previous Period']]],
        ];
    }

    public function run(array $filters): ReportResult
    {
        $filters = array_merge($this->getDefaultFilters(), $filters);

        return $this->remember('profit-loss', $filters, function () use ($filters) {
            [$start, $end] = $this->parseDateRange($filters);
            $branchId = $this->branchId($filters);

            $data = $this->computePL($start, $end, $branchId);

            // Comparison
            $comparison = null;
            if (filter_var($filters['compare'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                [$ps, $pe] = $this->previousPeriod($start, $end);
                $prevData  = $this->computePL($ps, $pe, $branchId);
                $comparison = new ReportResult(
                    rows: collect($this->buildRows($prevData)),
                    summary: $this->buildCards($prevData),
                    meta: $this->buildMeta($filters, $ps, $pe, 0),
                );
            }

            // Monthly chart (last 12 periods within range or month-by-month)
            $monthlyData = $this->monthlyTrend($start, $end, $branchId);
            $chartData = [
                'type'   => 'bar',
                'labels' => $monthlyData->pluck('month')->all(),
                'series' => [
                    ['name'=>'Revenue',      'data'=>$monthlyData->pluck('revenue')->all()],
                    ['name'=>'COGS',         'data'=>$monthlyData->pluck('cogs')->all()],
                    ['name'=>'Net Profit',   'data'=>$monthlyData->pluck('net_profit')->all()],
                ],
            ];

            return new ReportResult(
                summary: $this->buildCards($data),
                rows: collect($this->buildRows($data)),
                columns: [
                    ['key'=>'label',  'label'=>'Line Item', 'type'=>'string'],
                    ['key'=>'amount', 'label'=>'Amount',    'type'=>'money','align'=>'right'],
                    ['key'=>'notes',  'label'=>'Notes',     'type'=>'string'],
                ],
                chart_data: $chartData,
                comparison: $comparison,
                meta: $this->buildMeta($filters, $start, $end, 10),
            );
        });
    }

    private function computePL($start, $end, ?int $branchId): array
    {
        $salesBase = $this->salesBase($start, $end, $branchId);

        // Revenue
        $grossSales    = (float) (clone $salesBase)->sum('total');
        $totalReturns  = Schema::hasTable('sale_returns')
            ? (float) DB::table('sale_returns')
                ->where('status','completed')
                ->whereBetween('return_date',[$start->toDateString(),$end->toDateString()])
                ->when($branchId, fn($q) => $q->where('branch_id',$branchId))
                ->sum('refund_amount')
            : 0;
        $netSales = $grossSales - $totalReturns;

        // COGS from sale_items
        $cogs = (float) DB::table('sale_items as si')
            ->join('sales as s','s.id','=','si.sale_id')
            ->where('s.status','completed')
            ->whereBetween('s.sale_date',[$start->toDateString(),$end->toDateString()])
            ->when($branchId, fn($q) => $q->where('s.branch_id',$branchId))
            ->sum(DB::raw('si.cost_at_time * si.quantity'));

        // Subtract COGS of restocked returns
        $returnCogs = Schema::hasTable('sale_return_items')
            ? (float) DB::table('sale_return_items as sri')
                ->join('sale_returns as sr','sr.id','=','sri.sale_return_id')
                ->where('sr.status','completed')
                ->where('sri.restock',true)
                ->whereBetween('sr.return_date',[$start->toDateString(),$end->toDateString()])
                ->when($branchId, fn($q) => $q->where('sr.branch_id',$branchId))
                ->sum(DB::raw('sri.unit_price * sri.quantity_returned'))
            : 0;

        $netCogs       = max(0, $cogs - $returnCogs);
        $grossProfit   = $netSales - $netCogs;
        $grossMarginPct= $netSales > 0 ? round($grossProfit / $netSales * 100, 1) : 0;

        // Operating expenses
        $expensesByCategory = [];
        $totalExpenses = 0;
        if (Schema::hasTable('expenses')) {
            $expRows = DB::table('expenses')
                ->whereBetween('expense_date',[$start->toDateString(),$end->toDateString()])
                ->when($branchId, fn($q) => $q->where('branch_id',$branchId))
                ->whereNull('deleted_at')
                ->groupBy('category')
                ->selectRaw('category, SUM(amount) as total')
                ->pluck('total','category');

            foreach ($expRows as $cat => $amt) {
                $expensesByCategory[$cat] = (float) $amt;
            }
            $totalExpenses = array_sum($expensesByCategory);
        }

        $netProfit    = $grossProfit - $totalExpenses;
        $netMarginPct = $netSales > 0 ? round($netProfit / $netSales * 100, 1) : 0;

        // Tax collected
        $taxCollected  = (float) (clone $salesBase)->sum('tax_amount');
        $discountsGiven= (float) (clone $salesBase)->sum('discount_amount');

        return compact(
            'grossSales','totalReturns','netSales',
            'cogs','returnCogs','netCogs',
            'grossProfit','grossMarginPct',
            'expensesByCategory','totalExpenses',
            'netProfit','netMarginPct',
            'taxCollected','discountsGiven'
        );
    }

    private function buildCards(array $d): array
    {
        return [
            $this->card('Net Sales',       $d['netSales'],     'money'),
            $this->card('Gross Profit',    $d['grossProfit'],  'money'),
            $this->card('Gross Margin',    $d['grossMarginPct'],'pct'),
            $this->card('Net Profit',      $d['netProfit'],    'money'),
            $this->card('Net Margin',      $d['netMarginPct'], 'pct'),
            $this->card('Tax Collected',   $d['taxCollected'], 'money'),
        ];
    }

    private function buildRows(array $d): array
    {
        $rows = [
            ['label'=>'REVENUE',                'amount'=>'',                        'notes'=>''],
            ['label'=>'  Gross Sales',          'amount'=>$d['grossSales'],          'notes'=>'Completed sales total'],
            ['label'=>'  (-) Returns & Refunds','amount'=>-$d['totalReturns'],       'notes'=>''],
            ['label'=>'NET SALES',              'amount'=>$d['netSales'],            'notes'=>''],
            ['label'=>'',                       'amount'=>'',                        'notes'=>''],
            ['label'=>'COST OF GOODS SOLD',     'amount'=>'',                        'notes'=>''],
            ['label'=>'  COGS (sold items)',    'amount'=>$d['cogs'],                'notes'=>'sum(cost × qty)'],
            ['label'=>'  (-) Returns (restocked)','amount'=>-$d['returnCogs'],       'notes'=>''],
            ['label'=>'NET COGS',               'amount'=>$d['netCogs'],             'notes'=>''],
            ['label'=>'',                       'amount'=>'',                        'notes'=>''],
            ['label'=>'GROSS PROFIT',           'amount'=>$d['grossProfit'],         'notes'=>$d['grossMarginPct'].'% margin'],
            ['label'=>'',                       'amount'=>'',                        'notes'=>''],
        ];

        if (! empty($d['expensesByCategory'])) {
            $rows[] = ['label'=>'OPERATING EXPENSES','amount'=>'','notes'=>''];
            foreach ($d['expensesByCategory'] as $cat => $amt) {
                $rows[] = ['label'=>"  {$cat}", 'amount'=>$amt, 'notes'=>''];
            }
            $rows[] = ['label'=>'TOTAL EXPENSES',  'amount'=>$d['totalExpenses'],  'notes'=>''];
            $rows[] = ['label'=>'',                'amount'=>'',                   'notes'=>''];
        }

        $rows[] = ['label'=>'NET PROFIT', 'amount'=>$d['netProfit'], 'notes'=>$d['netMarginPct'].'% margin'];

        return $rows;
    }

    private function monthlyTrend($start, $end, ?int $branchId)
    {
        return DB::table('sales as s')
            ->leftJoin('sale_items as si','si.sale_id','=','s.id')
            ->where('s.status','completed')
            ->whereBetween('s.sale_date',[$start->toDateString(),$end->toDateString()])
            ->when($branchId, fn($q) => $q->where('s.branch_id',$branchId))
            ->groupByRaw("DATE_FORMAT(s.sale_date,'%Y-%m')")
            ->selectRaw("
                DATE_FORMAT(s.sale_date,'%Y-%m') as month,
                SUM(s.total) as revenue,
                SUM(si.cost_at_time * si.quantity) as cogs,
                SUM(s.total) - SUM(si.cost_at_time * si.quantity) as net_profit
            ")
            ->orderByRaw("DATE_FORMAT(s.sale_date,'%Y-%m')")
            ->get()
            ->map(fn($r) => [
                'month'       => $r->month,
                'revenue'     => round((float)$r->revenue,2),
                'cogs'        => round((float)$r->cogs,2),
                'net_profit'  => round((float)$r->net_profit,2),
            ]);
    }
}
