<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminReportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ReportManager $manager,
        private readonly ReportExporter $exporter,
    ) {}

    public function index(): JsonResponse
    {
        $adminReports = $this->manager->list('admin');
        return $this->successResponse(['reports' => $adminReports]);
    }

    public function schema(string $slug): JsonResponse
    {
        if (! $this->manager->has($slug)) {
            return $this->errorResponse("Report '{$slug}' not found.", 404);
        }

        $report = $this->manager->get($slug);

        return $this->successResponse([
            'slug'           => $slug,
            'name'           => $report->getName(),
            'filter_schema'  => $report->getFilterSchema(),
            'default_filters'=> $report->getDefaultFilters(),
        ]);
    }

    public function run(Request $request, string $slug): JsonResponse
    {
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

    public function export(Request $request, string $slug): \Illuminate\Http\Response|StreamedResponse|JsonResponse
    {
        if (! $this->manager->has($slug)) {
            return $this->errorResponse("Report '{$slug}' not found.", 404);
        }

        $validated = $request->validate(['format' => 'required|in:pdf,excel,csv']);

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
