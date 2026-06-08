<?php

namespace App\Contracts\Communications;

use App\Services\Communications\SendResult;
use Illuminate\Http\Request;

interface SmsProviderInterface
{
    public function send(string $to, string $body, array $opts = []): SendResult;

    /** Verify the request signature from the provider's webhook. */
    public function verifyWebhook(Request $request): bool;

    /**
     * Parse a delivery-status webhook and return normalised data.
     * Returns ['provider_message_id', 'status' (delivered|failed), 'error']
     */
    public function parseStatusWebhook(Request $request): array;

    /**
     * Parse an inbound message (e.g. STOP reply).
     * Returns ['from', 'body']
     */
    public function parseInboundWebhook(Request $request): array;

    /** Estimate cost of a single message to a given number in USD. */
    public function estimateCost(string $to, string $body): float;

    /** Quick connectivity test — send a test SMS to the configured test number. */
    public function testConnection(): bool;
}
