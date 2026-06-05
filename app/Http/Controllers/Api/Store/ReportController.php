<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ReportManager $manager,
        private readonly ReportExporter $exporter,
    ) {}

    // ── List all available reports ────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->can('view-reports')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $grouped = $this->manager->listGrouped();

        // Filter out reports the user can't access (module or permission)
        // For now return all — module/permission filtering is frontend-side
        return $this->successResponse(['reports' => $grouped]);
    }

    // ── Get filter schema for a specific report ───────────────────────────────

    public function schema(Request $request, string $slug): JsonResponse
    {
        if (! $request->user()->can('view-reports')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        if (! $this->manager->has($slug)) {
            return $this->errorResponse("Report '{$slug}' not found.", 404);
        }

        $report = $this->manager->get($slug);

        return $this->successResponse([
            'slug'           => $slug,
            'name'           => $report->getName(),
            'category'       => $report->getCategory(),
            'description'    => method_exists($report, 'getDescription') ? $report->getDescription() : '',
            'filter_schema'  => $report->getFilterSchema(),
            'default_filters'=> $report->getDefaultFilters(),
        ]);
    }

    // ── Run a report (returns JSON) ───────────────────────────────────────────

    public function run(Request $request, string $slug): JsonResponse
    {
        if (! $request->user()->can('view-reports')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        if (! $this->manager->has($slug)) {
            return $this->errorResponse("Report '{$slug}' not found.", 404);
        }

        $report  = $this->manager->get($slug);
        $filters = array_merge($report->getDefaultFilters(), $request->all());

        try {
            $result = $report->run($filters);
        } catch (\Throwable $e) {
            return $this->errorResponse('Report failed: ' . $e->getMessage(), 500);
        }

        return $this->successResponse($result->toArray());
    }

    // ── Export a report ───────────────────────────────────────────────────────

    public function export(Request $request, string $slug): Response|StreamedResponse|JsonResponse
    {
        if (! $request->user()->can('export-reports')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        if (! $this->manager->has($slug)) {
            return $this->errorResponse("Report '{$slug}' not found.", 404);
        }

        $validated = $request->validate([
            'format' => 'required|in:pdf,excel,csv',
        ]);

        $report  = $this->manager->get($slug);
        $filters = array_merge($report->getDefaultFilters(), $request->except('format'));

        try {
            $result = $report->run($filters);
        } catch (\Throwable $e) {
            return $this->errorResponse('Report failed: ' . $e->getMessage(), 500);
        }

        return match ($validated['format']) {
            'pdf'   => $this->exporter->toPdf($result, $report->getName()),
            'excel' => $this->exporter->toExcel($result, $report->getName()),
            'csv'   => $this->exporter->toCsv($result, $report->getName()),
        };
    }
}
