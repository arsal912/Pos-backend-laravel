<?php

namespace App\Services\WhatsApp;

use App\Models\Store;
use App\Services\Reports\ReportManager;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Generates a branded PDF for a WhatsApp report request.
 * Uses the existing Report Engine (Phase 4D) to run the query,
 * then renders it via DomPDF with store branding.
 */
class ReportPdfGenerator
{
    private const SUPPORTED = [
        'sales'        => 'sales-summary',
        'top_products' => 'top-products',
        'customers'    => 'customer-lifetime-value',
        'inventory'    => 'stock-value',
        'expenses'     => 'expense-summary',
        'revenue'      => 'profit-loss',
        'loyalty'      => 'loyalty-overview',
    ];

    public function __construct(private ReportManager $reportManager) {}

    /**
     * Run the report and generate a PDF file.
     * Returns the storage path of the generated PDF.
     */
    public function generate(
        Store  $store,
        string $reportType,
        string $dateFrom,
        string $dateTo,
        string $periodLabel
    ): string {
        // Map WhatsApp type → report slug
        $slug = self::SUPPORTED[$reportType] ?? 'sales-summary';

        // Run the report if registered in ReportManager
        $rows    = collect();
        $columns = [];
        $summary = [];

        if ($this->reportManager->has($slug)) {
            try {
                $report = $this->reportManager->get($slug);
                $result = $report->run([
                    'date_range' => 'custom',
                    'date_from'  => $dateFrom,
                    'date_to'    => $dateTo,
                ]);
                $rows    = collect($result->rows ?? []);
                $columns = $result->columns ?? [];
                $summary = $result->summary ?? [];
            } catch (\Throwable) {
                // Fallback: empty data with note
                $rows = collect();
            }
        }

        // Logo URL for PDF (local path)
        $logoBase64 = null;
        if ($store->logo && Storage::disk('local')->exists($store->logo)) {
            $logoData   = Storage::disk('local')->get($store->logo);
            $mimeType   = mime_content_type(Storage::disk('local')->path($store->logo)) ?: 'image/png';
            $logoBase64 = "data:{$mimeType};base64," . base64_encode($logoData);
        }

        // Render PDF via Blade + DomPDF
        $html = view('reports.whatsapp-pdf', [
            'store'       => $store,
            'logoBase64'  => $logoBase64,
            'reportType'  => ucwords(str_replace('_', ' ', $reportType)),
            'periodLabel' => $periodLabel,
            'dateFrom'    => Carbon::parse($dateFrom)->format('d M Y'),
            'dateTo'      => Carbon::parse($dateTo)->format('d M Y'),
            'rows'        => $rows,
            'columns'     => $columns,
            'summary'     => $summary,
            'generatedAt' => now()->format('d M Y, H:i'),
        ])->render();

        $pdf  = Pdf::loadHTML($html)->setPaper('a4', 'portrait');
        $path = "report-downloads/store-{$store->id}/" . Str::uuid() . '.pdf';

        Storage::disk('local')->put($path, $pdf->output());

        return $path;
    }
}
