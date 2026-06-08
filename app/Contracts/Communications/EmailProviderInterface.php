<?php

namespace App\Contracts\Communications;

use App\Services\Communications\SendResult;
use Illuminate\Http\Request;

interface EmailProviderInterface
{
    /**
     * @param array $attachments  [['name'=>'file.pdf','content'=>base64,'type'=>'application/pdf']]
     */
    public function send(
        string $to,
        string $subject,
        string $htmlBody,
        string $textBody = '',
        array  $opts = [],   // cc, bcc, from_name, from_email, reply_to, attachments
    ): SendResult;

    public function verifyWebhook(Request $request): bool;

    /**
     * Parse open/click/bounce/unsubscribe webhook.
     * Returns ['type' (open|click|bounce|unsubscribe), 'provider_message_id', 'email', 'timestamp']
     */
    public function parseWebhook(Request $request): array;

    public function testConnection(): bool;
}
