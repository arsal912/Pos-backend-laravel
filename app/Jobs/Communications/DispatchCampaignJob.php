<?php

namespace App\Jobs\Communications;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\CustomerSegment;
use App\Models\Store;
use App\Services\Communications\CommunicationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DispatchCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;  // campaigns are not retried wholesale
    public int $timeout = 300;

    public function __construct(
        public readonly int $campaignId,
        public readonly int $tenantId,
    ) {
        $this->onQueue('communications');
    }

    public function handle(CommunicationDispatcher $dispatcher): void
    {
        $store = Store::find($this->tenantId);
        if (! $store) return;

        $store->run(function () use ($dispatcher) {
            $campaign = Campaign::find($this->campaignId);
            if (! $campaign || ! $campaign->isSendable()) return;

            $campaign->update(['status' => 'sending', 'started_at' => now()]);

            try {
                $customers = $this->resolveRecipients($campaign);
                $total     = $customers->count();
                $campaign->update(['total_recipients' => $total]);

                $sentCount    = 0;
                $failedCount  = 0;
                $skippedCount = 0;

                foreach ($customers as $customer) {
                    $recipient = $this->recipientAddress($campaign->channel, $customer);
                    if (! $recipient) { $skippedCount++; continue; }

                    $renderedBody = $this->renderBody($campaign, $customer);
                    $opts = [
                        'type'           => $campaign->type,
                        'customer_id'    => $customer->id,
                        'campaign_id'    => $campaign->id,
                        'reference_type' => 'campaign',
                        'reference_id'   => $campaign->id,
                    ];

                    try {
                        $log = match ($campaign->channel) {
                            'sms'      => $dispatcher->sendSms($recipient, $renderedBody, $opts),
                            'email'    => $dispatcher->sendEmail(
                                $recipient,
                                $this->renderSubject($campaign, $customer),
                                $renderedBody,
                                $opts
                            ),
                            'whatsapp' => $dispatcher->sendWhatsApp($recipient, $renderedBody, $opts),
                        };

                        CampaignRecipient::create([
                            'campaign_id'          => $campaign->id,
                            'customer_id'          => $customer->id,
                            'recipient'            => $recipient,
                            'communication_log_id' => $log->id,
                        ]);

                        if ($log->status === 'queued') {
                            $sentCount++;
                        } else {
                            $skippedCount++;
                        }
                    } catch (\Throwable $e) {
                        Log::warning("Campaign {$campaign->id}: failed to dispatch to {$recipient}: {$e->getMessage()}");
                        $failedCount++;
                    }
                }

                $campaign->update([
                    'status'          => 'sent',
                    'sent_count'      => $sentCount,
                    'failed_count'    => $failedCount,
                    'skipped_count'   => $skippedCount,
                    'completed_at'    => now(),
                ]);
            } catch (\Throwable $e) {
                $campaign->update(['status' => 'failed']);
                Log::error("Campaign {$campaign->id} dispatch failed: {$e->getMessage()}");
                throw $e;
            }
        });
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolveRecipients(Campaign $campaign)
    {
        $query = Customer::where('is_active', true)->whereNull('deleted_at');

        // For marketing campaigns, filter by opt-in consent
        if ($campaign->type === 'marketing') {
            $consentField = match ($campaign->channel) {
                'sms'      => 'sms_marketing_opted_in',
                'email'    => 'email_marketing_opted_in',
                'whatsapp' => 'whatsapp_marketing_opted_in',
            };
            $query->where($consentField, true);
        }

        // Filter: must have a usable address for the channel
        if ($campaign->channel === 'sms' || $campaign->channel === 'whatsapp') {
            $query->whereNotNull('phone')->where('phone', '!=', '');
        } else {
            $query->whereNotNull('email')->where('email', '!=', '');
        }

        // Audience filter
        match ($campaign->target_type) {
            'customer_group'   => $query->where('customer_group_id', $campaign->target_id),
            'customer_segment' => $this->applySegmentRules($query, $campaign->target_id),
            default            => null, // all_customers
        };

        return $query->get(['id', 'name', 'phone', 'email']);
    }

    private function applySegmentRules($query, ?int $segmentId): void
    {
        if (! $segmentId) return;
        $segment = CustomerSegment::find($segmentId);
        $segment?->applyRules($query);
    }

    private function recipientAddress(string $channel, Customer $customer): ?string
    {
        return match ($channel) {
            'sms', 'whatsapp' => $customer->phone ?: null,
            'email'           => $customer->email ?: null,
            default           => null,
        };
    }

    private function renderBody(Campaign $campaign, Customer $customer): string
    {
        $vars = array_merge(
            $campaign->variables ?? [],
            [
                'customer_name'  => $customer->name,
                'customer_email' => $customer->email ?? '',
                'customer_phone' => $customer->phone ?? '',
            ]
        );

        $body = $campaign->body;
        foreach ($vars as $key => $value) {
            $body = str_replace('{{'.$key.'}}', (string) $value, $body);
            $body = str_replace('{{ '.$key.' }}', (string) $value, $body);
        }
        return $body;
    }

    private function renderSubject(Campaign $campaign, Customer $customer): string
    {
        if (! $campaign->subject) return $campaign->name;

        $vars = array_merge($campaign->variables ?? [], ['customer_name' => $customer->name]);
        $subject = $campaign->subject;
        foreach ($vars as $key => $value) {
            $subject = str_replace('{{'.$key.'}}', (string) $value, $subject);
        }
        return $subject;
    }
}
