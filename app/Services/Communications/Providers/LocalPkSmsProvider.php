<?php

namespace App\Services\Communications\Providers;

use App\Contracts\Communications\SmsProviderInterface;
use App\Services\Communications\SendResult;
use Illuminate\Http\Request;

/**
 * Stub provider for Pakistani local SMS gateways (Telenor, Jazz, SCO bulk SMS).
 *
 * HOW TO IMPLEMENT:
 * Each PK bulk SMS provider exposes a simple HTTP GET/POST API.
 * Replace the body of send() with:
 *   $response = Http::get('https://api.your-provider.com/send', [
 *       'user'   => $this->username,
 *       'pass'   => $this->password,
 *       'msisdn' => $to,
 *       'msg'    => $body,
 *       'senderid' => $this->senderId,
 *   ]);
 *
 * Webhook verification varies by provider — most use IP whitelisting rather
 * than HMAC signatures. Implement verifyWebhook() by checking $request->ip()
 * against provider's documented IP range.
 */
class LocalPkSmsProvider implements SmsProviderInterface
{
    public function __construct(array $credentials)
    {
        // Store credentials when a real implementation is added
    }

    public function send(string $to, string $body, array $opts = []): SendResult
    {
        throw new \RuntimeException(
            'LocalPkSmsProvider is a stub. ' .
            'See class docblock for implementation guide. ' .
            'Choose a Pakistani bulk SMS provider (e.g. Wateen, MTBC, Zong SMPP) ' .
            'and replace this method body with their HTTP API call.'
        );
    }

    public function verifyWebhook(Request $request): bool { return true; }
    public function parseStatusWebhook(Request $request): array { return []; }
    public function parseInboundWebhook(Request $request): array { return []; }
    public function estimateCost(string $to, string $body): float { return 0.005; } // ~PKR 1.5 / USD 0.005
    public function testConnection(): bool { return false; }
}
