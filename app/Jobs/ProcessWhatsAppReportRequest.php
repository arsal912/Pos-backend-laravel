<?php

namespace App\Jobs;

use App\Models\Store;
use App\Models\WhatsAppReportRequest;
use App\Services\Communications\CommunicationsManager;
use App\Services\WhatsApp\ReportParser;
use App\Services\WhatsApp\ReportPdfGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Processes an inbound WhatsApp message:
 *   1. Sends acknowledgement ("Processing your request...")
 *   2. Uses Claude AI to parse the natural language into a report request
 *   3. Runs the report using the existing report engine
 *   4. Generates a branded PDF via DomPDF
 *   5. Creates a signed download token (expires 24h)
 *   6. Sends the download link back via WhatsApp
 */
class ProcessWhatsAppReportRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 120;

    public function __construct(
        public readonly int    $storeId,
        public readonly int    $requestId,
        public readonly string $fromNumber,
        public readonly string $toNumber,   // the store's WhatsApp number (e.g. whatsapp:+14155238886)
        public readonly string $message,
    ) {
        $this->onQueue('communications');
    }

    public function handle(
        ReportParser       $parser,
        ReportPdfGenerator $pdfGenerator,
        CommunicationsManager $comms,
    ): void {
        $store   = Store::find($this->storeId);
        $request = null;

        $store?->run(function () use (&$request) {
            $request = WhatsAppReportRequest::find($this->requestId);
        });

        if (! $store || ! $request) return;

        $store->run(function () use ($request, $store, $parser, $pdfGenerator, $comms) {
            $request->update(['status' => 'processing']);

            // Step 1: Send acknowledgement
            try {
                $comms->whatsapp()->sendMessage(
                    $this->fromNumber,
                    "⏳ Got it! Generating your *{$request->message}* — I'll have your report ready in a moment...",
                    ['from' => $this->toNumber]
                );
            } catch (\Throwable) { /* non-fatal — continue */ }

            // Step 2: Parse with Claude AI
            $parsed = $parser->parse($this->message, $store->name);

            if (! $parsed) {
                $this->sendAndFail($request, $comms, "Sorry, I couldn't understand your request. Please try something like:\n\n• *Sales report this month*\n• *Inventory report*\n• *Top products last 30 days*");
                return;
            }

            $request->update(['ai_response' => $parsed]);

            // Handle clarification needed
            if ($parsed['clarification_needed'] ?? false) {
                $comms->whatsapp()->sendMessage(
                    $this->fromNumber,
                    '❓ ' . ($parsed['clarification_question'] ?? 'Could you please clarify the date range?'),
                    ['from' => $this->toNumber]
                );
                $request->update(['status' => 'completed']);
                return;
            }

            // Not a report request — send friendly reply
            if (! ($parsed['is_report_request'] ?? false)) {
                $reply = $parsed['friendly_reply'] ?? "Hi! I can generate reports for you. Try:\n• *Sales report this week*\n• *Inventory stock report*\n• *Top products this month*\n• *Customer report last 30 days*";
                $comms->whatsapp()->sendMessage($this->fromNumber, $reply, ['from' => $this->toNumber]);
                $request->update(['status' => 'completed']);
                return;
            }

            $request->update([
                'report_type'  => $parsed['report_type'],
                'date_from'    => $parsed['date_from'],
                'date_to'      => $parsed['date_to'],
                'period_label' => $parsed['period_label'],
            ]);

            // Step 3: Generate PDF
            try {
                $pdfPath = $pdfGenerator->generate(
                    $store,
                    $parsed['report_type'],
                    $parsed['date_from'],
                    $parsed['date_to'],
                    $parsed['period_label']
                );
            } catch (\Throwable $e) {
                Log::error('WhatsApp PDF generation failed', ['error' => $e->getMessage()]);
                $this->sendAndFail($request, $comms, "Sorry, I couldn't generate the report. Please try again later.");
                return;
            }

            // Step 4: Create signed download token (expires 24 hours)
            $token    = Str::uuid()->toString();
            $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');
            $downloadUrl = "{$frontendUrl}/report-download/{$token}";

            $request->update([
                'status'               => 'completed',
                'pdf_path'             => $pdfPath,
                'download_token'       => $token,
                'download_expires_at'  => now()->addHours(24),
            ]);

            // Step 5: Send the download link
            $reportLabel  = ucwords(str_replace('_', ' ', $parsed['report_type']));
            $periodLabel  = $parsed['period_label'];
            $expiryTime   = now()->addHours(24)->format('d M Y, H:i');

            $successMsg = "✅ *{$store->name} — {$reportLabel} Report*\n"
                        . "📅 Period: {$periodLabel}\n\n"
                        . "👆 Download your report (PDF):\n{$downloadUrl}\n\n"
                        . "_Link expires: {$expiryTime}_";

            $comms->whatsapp()->sendMessage($this->fromNumber, $successMsg, ['from' => $this->toNumber]);
        });
    }

    private function sendAndFail(WhatsAppReportRequest $request, CommunicationsManager $comms, string $message): void
    {
        $request->update(['status' => 'failed', 'error' => $message]);
        try {
            $comms->whatsapp()->sendMessage($this->fromNumber, "❌ {$message}", ['from' => $this->toNumber]);
        } catch (\Throwable) {}
    }
}
