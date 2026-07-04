<?php

namespace App\Contracts\Communications;

use App\Services\Communications\SendResult;
use Illuminate\Http\Request;

interface WhatsAppProviderInterface
{
    /** Send a free-form text message (only valid within 24h service window). */
    public function sendMessage(string $to, string $body, array $opts = []): SendResult;

    /**
     * Send an approved template message (can be sent anytime).
     * @param array $variables  Template variable substitutions
     */
    public function sendTemplate(string $to, string $templateName, array $variables = [], array $opts = []): SendResult;

    public function verifyWebhook(Request $request): bool;

    /**
     * Parse a status/inbound webhook.
     * Returns ['type' (status|inbound), 'from', 'body', 'provider_message_id', 'status']
     */
    public function parseWebhook(Request $request): array;

    public function testConnection(): bool;
}
