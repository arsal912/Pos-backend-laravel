<?php

namespace App\Services\Communications\Providers;

use App\Contracts\Communications\WhatsAppProviderInterface;
use App\Services\Communications\SendResult;
use Illuminate\Http\Request;
use Twilio\Rest\Client as TwilioClient;
use Twilio\Security\RequestValidator;

class TwilioWhatsAppProvider implements WhatsAppProviderInterface
{
    private TwilioClient $client;
    private string $accountSid;
    private string $authToken;
    private string $fromNumber;  // e.g. "whatsapp:+14155238886" (Twilio sandbox)

    public function __construct(array $credentials)
    {
        $this->accountSid  = $credentials['account_sid']  ?? env('TWILIO_ACCOUNT_SID', '');
        $this->authToken   = $credentials['auth_token']   ?? env('TWILIO_AUTH_TOKEN', '');
        $this->fromNumber  = $credentials['whatsapp_from'] ?? env('TWILIO_WHATSAPP_FROM', 'whatsapp:+14155238886');

        $this->client = new TwilioClient($this->accountSid, $this->authToken);
    }

    public function sendMessage(string $to, string $body, array $opts = []): SendResult
    {
        try {
            $toWa   = str_starts_with($to, 'whatsapp:') ? $to : "whatsapp:{$to}";
            $fromWa = $opts['from'] ?? $this->fromNumber;

            $message = $this->client->messages->create($toWa, [
                'from' => $fromWa,
                'body' => $body,
            ]);

            return SendResult::ok($message->sid, (float) ($message->price ?? 0));
        } catch (\Twilio\Exceptions\TwilioException $e) {
            return SendResult::fail($e->getMessage());
        }
    }

    public function sendTemplate(string $to, string $templateName, array $variables = [], array $opts = []): SendResult
    {
        // Twilio WhatsApp templates use content SIDs or pre-approved template bodies
        // For simplicity, format the body and send as a regular message
        $body = $templateName; // In production, look up template content
        foreach ($variables as $key => $value) {
            $body = str_replace("{{{$key}}}", $value, $body);
        }
        return $this->sendMessage($to, $body, $opts);
    }

    public function verifyWebhook(Request $request): bool
    {
        $validator = new RequestValidator($this->authToken);
        return $validator->validate(
            $request->header('X-Twilio-Signature', ''),
            $request->fullUrl(),
            $request->all()
        );
    }

    public function parseWebhook(Request $request): array
    {
        $body = $request->input('Body', '');
        $from = $request->input('From', '');

        // Clean whatsapp: prefix
        $cleanFrom = preg_replace('/^whatsapp:/', '', $from);

        return [
            'type'                => $request->has('MessageSid') ? 'inbound' : 'status',
            'from'                => $cleanFrom,
            'body'                => $body,
            'provider_message_id' => $request->input('MessageSid'),
            'status'              => $request->input('MessageStatus'),
        ];
    }

    public function testConnection(): bool
    {
        try {
            $account = $this->client->api->v2010->accounts($this->accountSid)->fetch();
            return ! empty($account->sid);
        } catch (\Throwable) {
            return false;
        }
    }
}
