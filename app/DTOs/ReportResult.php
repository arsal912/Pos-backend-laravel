<?php

namespace App\DTOs;

use Illuminate\Support\Collection;

final class ReportResult
{
    public function __construct(
        /** Key KPI cards: [['label' => 'Total Revenue', 'value' => '12,500', 'raw' => 12500, 'trend' => 15.2]] */
        public readonly array $summary = [],

        /** Main data rows as a flat Collection or array of arrays */
        public readonly Collection|array $rows = [],

        /** For grouped/sectioned reports: ['group_key' => ['label', 'rows', 'subtotals']] */
        public readonly array $groups = [],

        /** Chart data for recharts: ['labels' => [], 'series' => [['name', 'data' => []]]] */
        public readonly array $chart_data = [],

        /** Previous-period result for comparison (same structure as this, without comparison itself) */
        public readonly ?ReportResult $comparison = null,

        /** Meta info: filters_used, generated_at, row_count, timezone, currency */
        public readonly array $meta = [],

        /** Column definitions: [['key', 'label', 'type' (money/number/percent/date/string), 'align', 'total']] */
        public readonly array $columns = [],

        /** Optional totals row matching column keys */
        public readonly array $totals = [],
    ) {}

    public function toArray(): array
    {
        return [
            'summary'    => $this->summary,
            'rows'       => $this->rows instanceof Collection ? $this->rows->values()->all() : $this->rows,
            'groups'     => $this->groups,
            'chart_data' => $this->chart_data,
            'comparison' => $this->comparison?->toArray(),
            'meta'       => $this->meta,
            'columns'    => $this->columns,
            'totals'     => $this->totals,
        ];
    }
}
