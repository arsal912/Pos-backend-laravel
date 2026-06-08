<?php

namespace App\Services\Communications\Providers;

use App\Contracts\Communications\EmailProviderInterface;
use App\Services\Communications\SendResult;
use Illuminate\Http\Request;
use Resend\Laravel\Facades\Resend;

class ResendEmailProvider implements EmailProviderInterface
{
    private string $apiKey;
    private string $defaultFrom;
    private string $defaultFromName;
    private string $webhookSecret;

    public function __construct(array $credentials)
    {
        $this->apiKey          = $credentials['api_key']          ?? env('RESEND_API_KEY', '');
        $this->defaultFrom     = $credentials['from_email']       ?? env('MAIL_FROM_ADDRESS', 'noreply@example.com');
        $this->defaultFromName = $credentials['from_name']        ?? env('MAIL_FROM_NAME', 'POS System');
        $this->webhookSecret   = $credentials['webhook_secret']   ?? env('RESEND_WEBHOOK_SECRET', '');
    }

    public function send(
        string $to,
        string $subject,
        string $htmlBody,
        string $textBody = '',
        array  $opts = [],
    ): SendResult {
        try {
            $fromName  = $opts['from_name']  ?? $this->defaultFromName;
            $fromEmail = $opts['from_email'] ?? $this->defaultFrom;
            $from      = "{$fromName} <{$fromEmail}>";

            $payload = [
                'from'    => $from,
                'to'      => [$to],
                'subject' => $subject,
                'html'    => $htmlBody,
            ];

            if ($textBody) $payload['text'] = $textBody;
            if (! empty($opts['reply_to']))    $payload['reply_to']    = $opts['reply_to'];
            if (! empty($opts['cc']))          $payload['cc']          = (array) $opts['cc'];
            if (! empty($opts['bcc']))         $payload['bcc']         = (array) $opts['bcc'];
            if (! empty($opts['attachments'])) $payload['attachments'] = $opts['attachments'];

            // Use Resend client directly (bypasses Laravel Mail)
            $resend   = new \Resend\Client($this->apiKey);
            $response = $resend->emails->send($payload);

            return SendResult::ok($response->id ?? uniqid('resend_'), 0.0, (array) $response);
        } catch (\Throwable $e) {
            return SendResult::fail($e->getMessage());
        }
    }

    public function verifyWebhook(Request $request): bool
    {
        if (! $this->webhookSecret) return true; // skip verification if not configured

        // Resend uses svix-style signatures
        $signature = $request->header('svix-signature', '');
        $timestamp = $request->header('svix-timestamp', '');
        $msgId     = $request->header('svix-id', '');

        if (! $signature || ! $timestamp) return false;

        $toSign  = "{$msgId}.{$timestamp}." . $request->getContent();
        $hmac    = base64_encode(hash_hmac('sha256', $toSign, $this->webhookSecret, true));
        $expectedSignatures = explode(' ', $signature);

        foreach ($expectedSignatures as $sig) {
            $sigValue = str_replace('v1,', '', $sig);
            if (hash_equals($sigValue, $hmac)) {
                return true;
            }
        }

        return false;
    }

    public function parseWebhook(Request $request): array
    {
        $data = $request->json()->all();
        $type = $data['type'] ?? '';

        return [
            'type'                => match ($type) {
                'email.opened'      => 'open',
                'email.clicked'     => 'click',
                'email.bounced'     => 'bounce',
                'email.complained'  => 'bounce',
                'email.unsubscribed'=> 'unsubscribe',
                default             => 'status',
            },
            'provider_message_id' => $data['data']['email_id'] ?? null,
            'email'               => $data['data']['to'][0]    ?? null,
            'timestamp'           => $data['data']['created_at'] ?? now()->toIso8601String(),
        ];
    }

    public function testConnection(): bool
    {
        try {
            $resend  = new \Resend\Client($this->apiKey);
            // Resend doesn't have a ping endpoint — try to list domains
            $domains = $resend->domains->list();
            return $domains !== null;
        } catch (\Throwable) {
            return false;
        }
    }
}
