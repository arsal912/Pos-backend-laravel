<?php

namespace App\Reports\Sales;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;
use Illuminate\Support\Facades\DB;

class SalesByHourReport extends BaseReport
{
    public function getName(): string { return 'Sales by Hour'; }
    public function getCategory(): string { return 'sales'; }
    public function getDescription(): string { return 'Hourly heatmap showing peak trading times.'; }
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

        return $this->remember('sales-by-hour', $filters, function () use ($filters) {
            [$start, $end] = $this->parseDateRange($filters);
            $branchId = $this->branchId($filters);

            $raw = $this->salesBase($start, $end, $branchId)
                ->selectRaw("
                    HOUR(created_at) as hour,
                    DAYOFWEEK(sale_date) as dow,
                    COUNT(*) as transactions,
                    SUM(total) as revenue
                ")
                ->groupByRaw('HOUR(created_at), DAYOFWEEK(sale_date)')
                ->orderByRaw('hour, dow')
                ->get();

            $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            $rows = collect();

            for ($h = 0; $h < 24; $h++) {
                $rowData = ['hour' => sprintf('%02d:00', $h)];
                for ($d = 1; $d <= 7; $d++) {
                    $cell = $raw->where('hour', $h)->where('dow', $d)->first();
                    $rowData[$days[$d - 1]] = $cell ? (int) $cell->transactions : 0;
                    $rowData[$days[$d - 1] . '_rev'] = $cell ? round((float) $cell->revenue, 2) : 0;
                }
                $rowData['total'] = array_sum(array_map(fn($d) => $rowData[$d], $days));
                $rows->push($rowData);
            }

            $cols = [['key'=>'hour','label'=>'Hour','type'=>'string']];
            foreach ($days as $d) {
                $cols[] = ['key'=>$d, 'label'=>$d, 'type'=>'int', 'align'=>'right'];
            }
            $cols[] = ['key'=>'total','label'=>'Total','type'=>'int','align'=>'right','total'=>true];

            return new ReportResult(
                summary: [
                    $this->card('Date Range', $start->toDateString() . ' → ' . $end->toDateString(), 'string'),
                    $this->card('Peak Hour', $this->peakHour($raw), 'string'),
                ],
                rows: $rows,
                columns: $cols,
                meta: $this->buildMeta($filters, $start, $end, 24),
            );
        });
    }

    private function peakHour($raw): string
    {
        $byHour = $raw->groupBy('hour')->map(fn($g) => $g->sum('transactions'));
        if ($byHour->isEmpty()) return '—';
        $h = $byHour->sortDesc()->keys()->first();
        return sprintf('%02d:00 – %02d:00', $h, $h + 1);
    }
}
