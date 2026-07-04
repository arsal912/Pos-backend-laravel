<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\CommunicationLog;
use App\Models\ScheduledReport;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduledReportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ReportManager $manager,
        private readonly ReportExporter $exporter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->can('view-reports')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $schedules = ScheduledReport::orderBy('name')->get();

        return $this->successResponse(['schedules' => $schedules]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->can('export-reports')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'name'             => 'required|string|max:255',
            'report_slug'      => 'required|string',
            'filters'          => 'sometimes|array',
            'schedule'         => 'required|in:daily,weekly,monthly',
            'recipient_emails' => 'required|array|min:1',
            'recipient_emails.*'=> 'email',
            'formats'          => 'sometimes|array',
            'formats.*'        => 'in:pdf,excel,csv',
            'is_active'        => 'sometimes|boolean',
        ]);

        if (! $this->manager->has($validated['report_slug'])) {
            return $this->errorResponse("Report '{$validated['report_slug']}' not found.", 404);
        }

        $validated['created_by'] = auth()->id();
        $schedule = ScheduledReport::create($validated);

        return $this->successResponse(['schedule' => $schedule], 'Scheduled report created.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('export-reports')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $schedule  = ScheduledReport::findOrFail($id);
        $validated = $request->validate([
            'name'             => 'sometimes|string|max:255',
            'filters'          => 'sometimes|array',
            'schedule'         => 'sometimes|in:daily,weekly,monthly',
            'recipient_emails' => 'sometimes|array|min:1',
            'recipient_emails.*'=> 'email',
            'formats'          => 'sometimes|array',
            'is_active'        => 'sometimes|boolean',
        ]);

        $schedule->update($validated);

        return $this->successResponse(['schedule' => $schedule->fresh()], 'Updated.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('export-reports')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        ScheduledReport::findOrFail($id)->delete();

        return $this->successResponse(null, 'Deleted.');
    }

    /**
     * Manually trigger a scheduled report now.
     * In Phase 5, this will send real emails.
     * For now it logs to communication_logs with status='skipped'.
     */
    public function sendNow(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('export-reports')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $schedule = ScheduledReport::findOrFail($id);

        try {
            $report  = $this->manager->get($schedule->report_slug);
            $filters = array_merge($report->getDefaultFilters(), $schedule->filters ?? []);
            $result  = $report->run($filters);

            // Log message for each recipient and format
            $formats = $schedule->formats ?? ['pdf'];
            foreach ($schedule->recipient_emails as $email) {
                $body = "Scheduled Report: {$schedule->name}\n"
                      . "Period: " . ($result->meta['date_from'] ?? '') . " → " . ($result->meta['date_to'] ?? '') . "\n"
                      . "Rows: " . $result->meta['row_count'] . "\n\n"
                      . implode("\n", array_map(fn($c) => $c['label'] . ': ' . $c['value'], $result->summary));

                CommunicationLog::create([
                    'customer_id'    => null,
                    'recipient'      => $email,
                    'channel'        => 'email',
                    'type'           => 'transactional',
                    'subject'        => "Report: {$schedule->name} — " . ($result->meta['date_from'] ?? now()->toDateString()),
                    'body'           => $body,
                    'status'         => 'skipped',
                    'provider'       => 'logged_only',
                    'sent_at'        => now(),
                    'sent_by'        => auth()->id(),
                    'reference_type' => 'scheduled_report',
                    'reference_id'   => $schedule->id,
                ]);
            }

            $schedule->update([
                'last_sent_at' => now(),
                'last_status'  => 'success',
                'last_error'   => null,
            ]);

            return $this->successResponse([
                'schedule'     => $schedule->fresh(),
                'rows_exported'=> $result->meta['row_count'],
                'recipients'   => count($schedule->recipient_emails),
                'note'         => 'Report logged. Email delivery requires Phase 5 email provider configuration.',
            ], 'Report dispatched and logged.');
        } catch (\Throwable $e) {
            $schedule->update(['last_status' => 'error', 'last_error' => $e->getMessage()]);
            return $this->errorResponse('Report dispatch failed: ' . $e->getMessage(), 500);
        }
    }
}
