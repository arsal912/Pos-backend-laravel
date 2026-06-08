<?php

namespace App\Http\Controllers\Api\Webhook;

use App\Models\CommunicationLog;
use App\Models\CommunicationOptOut;
use App\Models\Store;
use App\Services\Communications\CommunicationsManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Handles inbound webhooks from communication providers.
 * All endpoints are outside auth middleware — signature is the authentication.
 * Returns 400 (not 500) on signature failure to avoid leaking errors.
 */
class CommunicationsWebhookController
{
    public function __construct(private CommunicationsManager $manager) {}

    // ── SMS (Twilio) ──────────────────────────────────────────────────────────

    /**
     * POST /api/v1/webhooks/communications/sms
     *
     * Handles:
     *  - Delivery status callbacks (delivered/failed)
     *  - Inbound STOP messages → opt-out
     */
    public function sms(Request $request): Response
    {
        try {
            $provider = $this->manager->make('sms');

            if (! $provider->verifyWebhook($request)) {
                Log::warning('SMS webhook signature failed', ['ip' => $request->ip()]);
                return response('', 400);
            }
        } catch (\Throwable $e) {
            // No provider configured — accept but do nothing
            return response('', 200);
        }

        // Delivery status update
        $statusData = $provider->parseStatusWebhook($request);
        if (! empty($statusData['provider_message_id'])) {
            $this->updateLogByMessageId($statusData['provider_message_id'], $statusData['status'] ?? null);
        }

        // Inbound STOP message
        $inbound = $provider->parseInboundWebhook($request);
        if (! empty($inbound['from']) && strtoupper(trim($inbound['body'] ?? '')) === 'STOP') {
            $this->recordOptOut('sms', $inbound['from'], 'STOP reply');
        }

        return response('', 200);
    }

    // ── Email (Resend) ────────────────────────────────────────────────────────

    /**
     * POST /api/v1/webhooks/communications/email
     *
     * Handles: open, click, bounce, unsubscribe events
     */
    public function email(Request $request): Response
    {
        try {
            $provider = $this->manager->make('email');

            if (! $provider->verifyWebhook($request)) {
                Log::warning('Email webhook signature failed', ['ip' => $request->ip()]);
                return response('', 400);
            }
        } catch (\Throwable) {
            return response('', 200);
        }

        $event = $provider->parseWebhook($request);
        $msgId = $event['provider_message_id'] ?? null;

        switch ($event['type'] ?? '') {
            case 'open':
                if ($msgId) {
                    CommunicationLog::where('provider_message_id', $msgId)
                        ->whereNull('opened_at')
                        ->update(['opened_at' => now()]);
                }
                break;

            case 'click':
                if ($msgId) {
                    CommunicationLog::where('provider_message_id', $msgId)
                        ->whereNull('clicked_at')
                        ->update(['clicked_at' => now()]);
                }
                break;

            case 'bounce':
                // Hard bounce — opt out to prevent future sends
                if (! empty($event['email'])) {
                    $this->recordOptOut('email', $event['email'], 'Hard bounce');
                }
                if ($msgId) {
                    CommunicationLog::where('provider_message_id', $msgId)
                        ->update(['status' => 'failed', 'error_message' => 'Bounced']);
                }
                break;

            case 'unsubscribe':
                if (! empty($event['email'])) {
                    $this->recordOptOut('email', $event['email'], 'Unsubscribe');
                }
                break;
        }

        return response('', 200);
    }

    // ── WhatsApp (Twilio) ─────────────────────────────────────────────────────

    public function whatsapp(Request $request): Response
    {
        try {
            $provider = $this->manager->make('whatsapp');

            if (! $provider->verifyWebhook($request)) {
                Log::warning('WhatsApp webhook signature failed', ['ip' => $request->ip()]);
                return response('', 400);
            }
        } catch (\Throwable) {
            return response('', 200);
        }

        $event = $provider->parseWebhook($request);

        // Delivery status
        if (($event['type'] ?? '') === 'status') {
            $msgId  = $event['provider_message_id'] ?? null;
            $status = $event['status'] ?? null;
            if ($msgId && $status) {
                $this->updateLogByMessageId($msgId, $status);
            }
        }

        // Inbound STOP
        if (($event['type'] ?? '') === 'inbound') {
            $from = $event['from'] ?? null;
            $body = strtoupper(trim($event['body'] ?? ''));
            if ($from && $body === 'STOP') {
                $this->recordOptOut('whatsapp', $from, 'STOP reply');
            }
        }

        return response('', 200);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function updateLogByMessageId(string $messageId, ?string $status): void
    {
        $log = CommunicationLog::where('provider_message_id', $messageId)->first();
        if (! $log) return;

        $updates = [];
        if ($status === 'delivered') {
            $updates['delivered_at'] = now();
            $updates['status']       = 'sent';
        } elseif (in_array($status, ['failed', 'undelivered'])) {
            $updates['status'] = 'failed';
        }

        if ($updates) $log->update($updates);
    }

    /**
     * Record opt-out in the correct tenant DB by looking up
     * which tenant sent the original message to this recipient.
     */
    private function recordOptOut(string $channel, string $recipient, string $reason): void
    {
        $normalised = CommunicationOptOut::normalise($recipient, $channel);

        // Find the tenant that last messaged this recipient
        $log = CommunicationLog::where('channel', $channel)
            ->where('recipient', $normalised)
            ->latest()
            ->first();

        if ($log) {
            // Already in tenant context if log was found in current DB
            CommunicationOptOut::updateOrCreate(
                ['channel' => $channel, 'recipient' => $normalised],
                ['reason' => $reason, 'opted_out_at' => now()]
            );
        } else {
            // Search across all tenant DBs
            Store::chunk(20, function ($stores) use ($channel, $normalised, $reason) {
                foreach ($stores as $store) {
                    try {
                        $store->run(function () use ($channel, $normalised, $reason) {
                            $exists = CommunicationLog::where('channel', $channel)
                                ->where('recipient', $normalised)
                                ->exists();
                            if ($exists) {
                                CommunicationOptOut::updateOrCreate(
                                    ['channel' => $channel, 'recipient' => $normalised],
                                    ['reason' => $reason, 'opted_out_at' => now()]
                                );
                            }
                        });
                    } catch (\Throwable) {}
                }
            });
        }
    }
}
