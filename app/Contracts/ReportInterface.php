<?php

namespace App\Contracts;

use App\DTOs\ReportResult;

interface ReportInterface
{
    /** Human-readable name shown in the UI. */
    public function getName(): string;

    /** Category: sales | inventory | financial | customer | tax | admin */
    public function getCategory(): string;

    /** Default filter values applied when none are provided. */
    public function getDefaultFilters(): array;

    /**
     * Schema describing each filter so the frontend can build the filter UI.
     * Each item: ['key', 'type', 'label', 'default', 'required', 'options']
     */
    public function getFilterSchema(): array;

    /**
     * Run the report with the given filters.
     * Filters are merged with getDefaultFilters() before being passed here.
     */
    public function run(array $filters): ReportResult;
}
