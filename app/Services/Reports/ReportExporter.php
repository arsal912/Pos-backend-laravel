<?php

namespace App\Services\Reports;

use App\DTOs\ReportResult;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExporter
{
    // ── PDF ───────────────────────────────────────────────────────────────────

    public function toPdf(ReportResult $result, string $reportName, string $view = 'reports.generic'): Response
    {
        $html = view($view, [
            'result'     => $result,
            'reportName' => $reportName,
            'store'      => app('current_store'),
        ])->render();

        $pdf = Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait')
            ->setOption('dpi', 150);

        $filename = $this->slug($reportName) . '-' . now()->format('Y-m-d') . '.pdf';

        return response($pdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    // ── Excel (XLSX) ──────────────────────────────────────────────────────────

    public function toExcel(ReportResult $result, string $reportName): StreamedResponse
    {
        $filename = $this->slug($reportName) . '-' . now()->format('Y-m-d') . '.xlsx';

        return Excel::download(
            new GenericExcelExport($result, $reportName),
            $filename,
            ExcelFormat::XLSX
        );
    }

    // ── CSV ───────────────────────────────────────────────────────────────────

    public function toCsv(ReportResult $result, string $reportName): Response
    {
        $filename = $this->slug($reportName) . '-' . now()->format('Y-m-d') . '.csv';

        $rows    = $result->rows instanceof Collection ? $result->rows->all() : $result->rows;
        $columns = $result->columns;

        $csv = '';

        // Header row from columns definition
        if ($columns) {
            $csv .= implode(',', array_map(
                fn ($col) => '"' . str_replace('"', '""', $col['label']) . '"',
                $columns
            )) . "\n";
        } elseif (! empty($rows)) {
            $csv .= implode(',', array_map(
                fn ($key) => '"' . str_replace('"', '""', $key) . '"',
                array_keys((array) $rows[0])
            )) . "\n";
        }

        // Data rows
        foreach ($rows as $row) {
            $row = (array) $row;
            if ($columns) {
                $rowData = array_map(fn ($col) => $row[$col['key']] ?? '', $columns);
            } else {
                $rowData = array_values($row);
            }
            $csv .= implode(',', array_map(
                fn ($val) => '"' . str_replace('"', '""', (string) ($val ?? '')) . '"',
                $rowData
            )) . "\n";
        }

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function slug(string $name): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
    }
}

// ── Inline Excel export class ─────────────────────────────────────────────────

class GenericExcelExport implements
    FromCollection, WithHeadings, WithTitle,
    ShouldAutoSize, WithStyles, WithStrictNullComparison
{
    public function __construct(
        private readonly ReportResult $result,
        private readonly string $reportName,
    ) {}

    public function collection(): Collection
    {
        $rows    = $this->result->rows instanceof Collection
            ? $this->result->rows
            : collect($this->result->rows);
        $columns = $this->result->columns;

        return $rows->map(function ($row) use ($columns) {
            $row = (array) $row;
            if ($columns) {
                $mapped = [];
                foreach ($columns as $col) {
                    $mapped[] = $row[$col['key']] ?? null;
                }
                return $mapped;
            }
            return array_values($row);
        });
    }

    public function headings(): array
    {
        if ($this->result->columns) {
            return array_column($this->result->columns, 'label');
        }

        $rows = $this->result->rows instanceof Collection
            ? $this->result->rows
            : collect($this->result->rows);

        if ($rows->isEmpty()) return [];

        return array_keys((array) $rows->first());
    }

    public function title(): string
    {
        return substr($this->reportName, 0, 31); // Excel sheet name max 31 chars
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 11]],
        ];
    }
}
