# Adding a New Report

Reports follow the ReportInterface pattern. Here's how to add one.

## 1. Create the Report Class

```php
// app/Reports/Sales/MyNewReport.php
namespace App\Reports\Sales;

use App\DTOs\ReportResult;
use App\Reports\BaseReport;

class MyNewReport extends BaseReport
{
    public function getName(): string { return 'My New Report'; }
    public function getCategory(): string { return 'sales'; }
    public function getDescription(): string { return 'What this report shows'; }
    public function getRequiredModule(): ?string { return 'sales-reports'; }

    public function getFilterSchema(): array
    {
        return [
            ['key' => 'date_range', 'type' => 'date_range', 'label' => 'Date Range', 'default' => 'this_month'],
        ];
    }

    public function run(array $filters): ReportResult
    {
        $filters = array_merge($this->getDefaultFilters(), $filters);
        [$start, $end] = $this->parseDateRange($filters);

        $rows = // ... your query

        return new ReportResult(
            summary: [$this->card('Total', $rows->count(), 'int')],
            rows: $rows,
            columns: [['key' => 'field', 'label' => 'Field', 'type' => 'string']],
        );
    }
}
```

## 2. Register in ReportManager

```php
// app/Services/Reports/ReportManager.php — add to the reports array:
\App\Reports\Sales\MyNewReport::class,
```

## 3. Done
The report appears automatically in the frontend at /dashboard/reports.
