<?php

namespace App\Reports;

use App\DTOs\ReportResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Hello-world report to verify the pipeline (Step 1).
 * Returns hardcoded data — replace with real queries once pipeline is confirmed working.
 */
class SimpleTestReport extends BaseReport
{
    public function getName(): string { return 'Pipeline Test'; }
    public function getCategory(): string { return 'sales'; }
    public function getDescription(): string { return 'Verifies the report pipeline works end-to-end.'; }
    public function getRequiredModule(): ?string { return null; }
    public function isVisible(): bool { return false; } // Hidden from production UI

    public function getFilterSchema(): array
    {
        return [
            [
                'key'      => 'date_range',
                'type'     => 'date_range',
                'label'    => 'Date Range',
                'default'  => 'this_month',
                'required' => true,
            ],
        ];
    }

    public function run(array $filters): ReportResult
    {
        $filters = array_merge($this->getDefaultFilters(), $filters);
        [$start, $end] = $this->parseDateRange($filters);

        // Hardcoded rows to prove export / rendering works
        $rows = collect([
            ['date' => '2026-06-01', 'label' => 'Sales', 'amount' => 12500.00, 'count' => 25],
            ['date' => '2026-06-02', 'label' => 'Sales', 'amount' => 8750.50, 'count' => 18],
            ['date' => '2026-06-03', 'label' => 'Sales', 'amount' => 14200.75, 'count' => 31],
        ]);

        return new ReportResult(
            summary: [
                $this->card('Total Revenue', 35451.25),
                $this->card('Total Transactions', 74, 'int'),
                $this->card('Average Order Value', 479.07),
            ],
            rows: $rows,
            columns: [
                ['key' => 'date',   'label' => 'Date',         'type' => 'date',   'align' => 'left'],
                ['key' => 'label',  'label' => 'Type',         'type' => 'string', 'align' => 'left'],
                ['key' => 'amount', 'label' => 'Amount (Rs)',  'type' => 'money',  'align' => 'right', 'total' => true],
                ['key' => 'count',  'label' => 'Transactions', 'type' => 'int',    'align' => 'right', 'total' => true],
            ],
            totals: ['amount' => 35451.25, 'count' => 74],
            chart_data: [
                'type'   => 'line',
                'labels' => ['Jun 1', 'Jun 2', 'Jun 3'],
                'series' => [
                    ['name' => 'Revenue', 'data' => [12500.00, 8750.50, 14200.75]],
                ],
            ],
            meta: $this->buildMeta($filters, $start, $end, $rows->count()),
        );
    }
}
