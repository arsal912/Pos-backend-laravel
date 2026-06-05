<?php

namespace App\Reports\Financial;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ExpensesReport extends BaseReport
{
    public function getName(): string { return 'Expenses Analysis'; }
    public function getCategory(): string { return 'financial'; }
    public function getDescription(): string { return 'Expense breakdown by category, date, and payment method.'; }
    public function getRequiredModule(): ?string { return 'financial-reports'; }

    public function getFilterSchema(): array
    {
        return [
            ['key'=>'date_range', 'type'=>'date_range',   'label'=>'Date Range','default'=>'this_month','required'=>true],
            ['key'=>'branch_id',  'type'=>'branch_select','label'=>'Branch'],
            ['key'=>'category',   'type'=>'text',         'label'=>'Category'],
            ['key'=>'payment_method','type'=>'select',    'label'=>'Payment Method','default'=>'',
             'options'=>[
                ['value'=>'','label'=>'All'],
                ['value'=>'cash','label'=>'Cash'],
                ['value'=>'card','label'=>'Card'],
                ['value'=>'bank_transfer','label'=>'Bank Transfer'],
                ['value'=>'cheque','label'=>'Cheque'],
            ]],
        ];
    }

    public function run(array $filters): ReportResult
    {
        $filters = array_merge($this->getDefaultFilters(), $filters);

        return $this->remember('expenses-analysis', $filters, function () use ($filters) {
            [$start, $end] = $this->parseDateRange($filters);
            $branchId     = $this->branchId($filters);

            if (! Schema::hasTable('expenses')) {
                return new ReportResult(
                    summary: [$this->card('Note', 'No expenses recorded yet', 'string')],
                    meta: $this->buildMeta($filters, $start, $end, 0),
                );
            }

            $query = DB::table('expenses')
                ->whereBetween('expense_date', [$start->toDateString(), $end->toDateString()])
                ->whereNull('deleted_at')
                ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
                ->when($filters['category'] ?? null, fn($q, $c) => $q->where('category', 'like', "%{$c}%"))
                ->when($filters['payment_method'] ?? null, fn($q, $m) => $q->where('payment_method', $m));

            $rows = (clone $query)
                ->select('expense_date', 'category', 'description', 'amount', 'payment_method', 'reference', 'notes')
                ->orderByDesc('expense_date')
                ->get()
                ->map(fn($r) => [
                    'expense_date'   => $r->expense_date,
                    'category'       => $r->category,
                    'description'    => $r->description,
                    'amount'         => round((float) $r->amount, 2),
                    'payment_method' => ucfirst(str_replace('_', ' ', $r->payment_method)),
                    'reference'      => $r->reference ?? '',
                ]);

            $totalExpenses = round($rows->sum('amount'), 2);

            // By category breakdown
            $byCategory = $rows->groupBy('category')
                ->map(fn($g, $cat) => ['category'=>$cat, 'total'=>round($g->sum('amount'),2)])
                ->sortByDesc('total')->values();

            $chartData = [
                'type'   => 'pie',
                'labels' => $byCategory->pluck('category')->all(),
                'series' => [['name'=>'Amount','data'=>$byCategory->pluck('total')->all()]],
            ];

            return new ReportResult(
                summary: [
                    $this->card('Total Expenses',  $totalExpenses, 'money'),
                    $this->card('Expense Count',   $rows->count(), 'int'),
                    $this->card('Top Category',    $byCategory->first()['category'] ?? '—', 'string'),
                ],
                rows: $rows,
                columns: [
                    ['key'=>'expense_date',  'label'=>'Date',       'type'=>'date'],
                    ['key'=>'category',      'label'=>'Category',   'type'=>'string'],
                    ['key'=>'description',   'label'=>'Description','type'=>'string'],
                    ['key'=>'amount',        'label'=>'Amount',     'type'=>'money','align'=>'right','total'=>true],
                    ['key'=>'payment_method','label'=>'Method',     'type'=>'string'],
                    ['key'=>'reference',     'label'=>'Reference',  'type'=>'string'],
                ],
                totals: ['amount' => $totalExpenses],
                chart_data: $chartData,
                meta: $this->buildMeta($filters, $start, $end, $rows->count()),
            );
        });
    }
}
