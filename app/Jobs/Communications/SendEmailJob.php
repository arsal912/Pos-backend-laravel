<?php

namespace App\Jobs\Communications;

use App\Models\CommunicationLog;
use App\Models\CommunicationQuota;
use App\Models\CommunicationSetting;
use App\Models\Store;
use App\Services\Communications\CommunicationsManager;
use App\Services\Communications\UnsubscribeUrl;
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

                $sendOpts = $this->opts;

                // Apply tenant sender identity if available
                if ($this->tenantId) {
                    $settings = CommunicationSetting::current();
                    if ($settings->email_from_address) {
                        $sendOpts['from_email'] = $settings->email_from_address;
                        $sendOpts['from_name']  = $settings->email_from_name ?? config('app.name');
                    }
                }

                // Build HTML, injecting compliance footer for marketing messages
                $htmlBody = $sendOpts['html_body'] ?? $this->wrapHtml($this->body);

                $type = $log->type ?? $this->opts['type'] ?? 'manual';
                if (in_array($type, ['marketing', 'birthday', 'reminder']) && $this->tenantId) {
                    $htmlBody = $this->appendComplianceFooter($htmlBody);
                }

                $result = $emailProvider->send(
                    $this->to,
                    $this->subject,
                    $htmlBody,
                    strip_tags($htmlBody),
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
        if (str_contains($body, '<')) return $body;

        $escaped = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
        return "<div style=\"font-family:Arial,sans-serif;font-size:14px;color:#333;line-height:1.6;\">{$escaped}</div>";
    }

    private function appendComplianceFooter(string $htmlBody): string
    {
        // Don't double-append if the body already contains an unsubscribe link
        if (str_contains($htmlBody, '/unsubscribe') || str_contains($htmlBody, 'unsubscribe_url')) {
            return $htmlBody;
        }

        $unsubUrl   = $this->tenantId
            ? UnsubscribeUrl::generate('email', $this->to, $this->tenantId)
            : '#';

        $settings    = CommunicationSetting::current();
        $address     = $settings->store_physical_address
            ? e($settings->store_physical_address)
            : 'Our Store, Please contact us for our address.';

        $footer = <<<HTML

<div style="margin-top:32px;padding-top:16px;border-top:1px solid #e5e7eb;font-size:11px;color:#9ca3af;text-align:center;line-height:1.8;">
  <p>You received this email because you are a customer of our store.</p>
  <p><a href="{$unsubUrl}" style="color:#6366f1;text-decoration:underline;">Unsubscribe</a> from marketing emails.</p>
  <p>{$address}</p>
</div>
HTML;

        return $htmlBody.$footer;
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
