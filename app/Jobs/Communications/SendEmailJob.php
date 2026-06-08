<?php

namespace App\Jobs\Communications;

use App\Models\CommunicationLog;
use App\Models\CommunicationQuota;
use App\Models\CommunicationSetting;
use App\Models\Store;
use App\Services\Communications\CommunicationsManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 120;

    public function __construct(
        public readonly int     $logId,
        public readonly string  $to,
        public readonly string  $subject,
        public readonly string  $body,
        public readonly array   $opts     = [],
        public readonly ?int    $tenantId = null,
    ) {
        $this->onQueue('communications');
    }

    public function handle(CommunicationsManager $manager): void
    {
        $log = CommunicationLog::find($this->logId);
        if (! $log || $log->status !== 'queued') return;

        $run = function () use ($manager, $log) {
            try {
                $emailProvider = $manager->email();

                // Apply tenant sender identity if available
                $sendOpts = $this->opts;
                if ($this->tenantId) {
                    $settings = CommunicationSetting::current();
                    if ($settings->email_from_address) {
                        $sendOpts['from_email'] = $settings->email_from_address;
                        $sendOpts['from_name']  = $settings->email_from_name ?? config('app.name');
                    }
                }

                // Build HTML from body (wrap if plain text, otherwise use as-is)
                $htmlBody = $sendOpts['html_body'] ?? $this->wrapHtml($this->body);

                $result = $emailProvider->send(
                    $this->to,
                    $this->subject,
                    $htmlBody,
                    strip_tags($this->body),
                    $sendOpts,
                );

                if ($result->success) {
                    $log->update([
                        'status'              => 'sent',
                        'provider'            => 'resend',
                        'provider_message_id' => $result->providerMessageId,
                        'cost'                => $result->cost,
                        'sent_at'             => now(),
                        'provider_response'   => $result->raw,
                    ]);

                    if ($this->tenantId) {
                        CommunicationQuota::current()->increment('email');
                    }
                } else {
                    $this->handleFailure($log, $result->error ?? 'Send failed');
                }
            } catch (\Throwable $e) {
                $this->handleFailure($log, $e->getMessage());
                throw $e;
            }
        };

        if ($this->tenantId) {
            $store = Store::find($this->tenantId);
            $store?->run($run) ?? $run();
        } else {
            $run();
        }
    }

    private function wrapHtml(string $body): string
    {
        // If already looks like HTML, use as-is
        if (str_contains($body, '<')) return $body;

        // Wrap plain text
        $escaped = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
        return "<div style=\"font-family:Arial,sans-serif;font-size:14px;color:#333;\">{$escaped}</div>";
    }

    private function handleFailure(CommunicationLog $log, string $error): void
    {
        $retryCount = ($log->retry_count ?? 0) + 1;
        $isFinal    = $retryCount >= $this->tries;

        $log->update([
            'status'        => $isFinal ? 'failed' : 'queued',
            'error_message' => $error,
            'retry_count'   => $retryCount,
            'next_retry_at' => $isFinal ? null : now()->addSeconds($this->backoff * $retryCount),
        ]);
    }
}
