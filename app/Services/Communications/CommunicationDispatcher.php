<?php

namespace App\Services\Communications;

use App\Jobs\Communications\SendEmailJob;
use App\Jobs\Communications\SendSmsJob;
use App\Jobs\Communications\SendWhatsAppJob;
use App\Models\CommunicationLog;
use App\Models\CommunicationOptOut;
use App\Models\CommunicationQuota;

/**
 * The single entry point for ALL outbound communications.
 *
 * What it does:
 *   1. Checks opt-out table → logs 'skipped_opted_out' and returns
 *   2. Checks daily quota → logs 'quota_exceeded' and returns
 *   3. Creates CommunicationLog with status='queued'
 *   4. Dispatches the appropriate job on the 'communications' queue
 *
 * Tenant-aware: call from within tenant context for customer messages.
 * For system emails (verification, password reset), call without tenant context.
 */
class CommunicationDispatcher
{
    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Queue an SMS for delivery.
     *
     * @param array $opts  customer_id, reference_type, reference_id, type, sent_by
     */
    public function sendSms(string $to, string $body, array $opts = []): CommunicationLog
    {
        $to = CommunicationOptOut::normalise($to, 'sms');
        return $this->dispatch('sms', $to, $body, null, $opts);
    }

    /**
     * Queue an email for delivery.
     *
     * @param array $opts  html_body, attachments, from_name, from_email, reply_to, cc, bcc,
     *                     customer_id, reference_type, reference_id, type, sent_by
     */
    public function sendEmail(string $to, string $subject, string $body, array $opts = []): CommunicationLog
    {
        $to = CommunicationOptOut::normalise($to, 'email');
        return $this->dispatch('email', $to, $body, $subject, $opts);
    }

    /**
     * Queue a WhatsApp message for delivery.
     *
     * @param array $opts  template_name, template_variables, customer_id, reference_type,
     *                     reference_id, type, sent_by
     */
    public function sendWhatsApp(string $to, string $body, array $opts = []): CommunicationLog
    {
        $to = CommunicationOptOut::normalise($to, 'whatsapp');
        return $this->dispatch('whatsapp', $to, $body, null, $opts);
    }

    /**
     * Check if a recipient has opted out of a channel.
     */
    public function isOptedOut(string $channel, string $recipient): bool
    {
        return CommunicationOptOut::isOptedOut($channel, $recipient);
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function dispatch(string $channel, string $to, string $body, ?string $subject, array $opts): CommunicationLog
    {
        $type       = $opts['type']           ?? 'manual';
        $customerId = $opts['customer_id']    ?? null;
        $sentBy     = $opts['sent_by']        ?? auth()->id();
        $refType    = $opts['reference_type'] ?? null;
        $refId      = $opts['reference_id']   ?? null;
        $campaignId = $opts['campaign_id']    ?? null;

        $baseLog = [
            'customer_id'    => $customerId,
            'recipient'      => $to,
            'channel'        => $channel,
            'type'           => $type,
            'subject'        => $subject,
            'body'           => $body,
            'sent_by'        => $sentBy,
            'reference_type' => $refType,
            'reference_id'   => $refId,
            'campaign_id'    => $campaignId,
        ];

        // 1. Opt-out check
        if (CommunicationOptOut::isOptedOut($channel, $to)) {
            return CommunicationLog::create(array_merge($baseLog, [
                'status'   => 'skipped',
                'provider' => 'opted_out',
            ]));
        }

        // 2. Quota check (only applies inside tenant context)
        if ($this->inTenantContext()) {
            $quota = CommunicationQuota::current();
            if (! $quota->hasQuota($channel)) {
                return CommunicationLog::create(array_merge($baseLog, [
                    'status'   => 'skipped',
                    'provider' => 'quota_exceeded',
                ]));
            }
        }

        // 3. Create log with status='queued'
        $log = CommunicationLog::create(array_merge($baseLog, [
            'status'   => 'queued',
            'provider' => null,
        ]));

        // 4. Dispatch job
        $tenantId = $this->inTenantContext() ? app('current_store')?->id : null;

        $job = match ($channel) {
            'sms'      => new SendSmsJob($log->id, $to, $body, $opts, $tenantId),
            'email'    => new SendEmailJob($log->id, $to, $subject ?? '', $body, $opts, $tenantId),
            'whatsapp' => new SendWhatsAppJob($log->id, $to, $body, $opts, $tenantId),
        };

        dispatch($job)->onQueue('communications');

        return $log;
    }

    private function inTenantContext(): bool
    {
        return app()->bound('current_store') && app('current_store') !== null;
    }
}
