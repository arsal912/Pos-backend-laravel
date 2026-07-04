<?php

namespace App\Jobs\Communications;

use App\Models\CommunicationLog;
use App\Models\CommunicationQuota;
use App\Models\Store;
use App\Services\Communications\CommunicationsManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendWhatsAppJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 60;

    public function __construct(
        public readonly int     $logId,
        public readonly string  $to,
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
                $wa       = $manager->whatsapp();
                $template = $this->opts['template_name'] ?? null;
                $vars     = $this->opts['template_variables'] ?? [];

                $result = $template
                    ? $wa->sendTemplate($this->to, $template, $vars, $this->opts)
                    : $wa->sendMessage($this->to, $this->body, $this->opts);

                if ($result->success) {
                    $log->update([
                        'status'              => 'sent',
                        'provider'            => 'twilio-whatsapp',
                        'provider_message_id' => $result->providerMessageId,
                        'cost'                => $result->cost,
                        'sent_at'             => now(),
                        'provider_response'   => $result->raw,
                    ]);

                    if ($this->tenantId) {
                        CommunicationQuota::current()->increment('whatsapp');
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
