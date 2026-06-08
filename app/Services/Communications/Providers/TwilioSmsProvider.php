<?php

namespace App\Services\Communications\Providers;

use App\Contracts\Communications\SmsProviderInterface;
use App\Services\Communications\SendResult;
use Illuminate\Http\Request;
use Twilio\Rest\Client as TwilioClient;
use Twilio\Security\RequestValidator;

class TwilioSmsProvider implements SmsProviderInterface
{
    private TwilioClient $client;
    private string $accountSid;
    private string $authToken;
    private string $fromNumber;
    private string $testNumber;

    public function __construct(array $credentials)
    {
        $this->accountSid  = $credentials['account_sid']  ?? env('TWILIO_ACCOUNT_SID', '');
        $this->authToken   = $credentials['auth_token']   ?? env('TWILIO_AUTH_TOKEN', '');
        $this->fromNumber  = $credentials['from_number']  ?? env('TWILIO_FROM_NUMBER', '');
        $this->testNumber  = $credentials['test_number']  ?? env('TWILIO_TEST_NUMBER', $this->fromNumber);

        $this->client = new TwilioClient($this->accountSid, $this->authToken);
    }

    public function send(string $to, string $body, array $opts = []): SendResult
    {
        try {
            $from = $opts['from'] ?? $this->fromNumber;

            $message = $this->client->messages->create($to, [
                'from' => $from,
                'body' => $body,
            ]);

            return SendResult::ok(
                $message->sid,
                (float) ($message->price ?? 0),
                ['status' => $message->status, 'direction' => $message->direction]
            );
        } catch (\Twilio\Exceptions\TwilioException $e) {
            return SendResult::fail($e->getMessage());
        }
    }

    public function verifyWebhook(Request $request): bool
    {
        $validator = new RequestValidator($this->authToken);
        $url       = $request->fullUrl();
        $signature = $request->header('X-Twilio-Signature', '');
        $params    = $request->all();

        return $validator->validate($signature, $url, $params);
    }

    public function parseStatusWebhook(Request $request): array
    {
        return [
            'provider_message_id' => $request->input('MessageSid'),
            'status'              => $request->input('MessageStatus'),
            'error'               => $request->input('ErrorMessage'),
        ];
    }

    public function parseInboundWebhook(Request $request): array
    {
        return [
            'from' => $request->input('From'),
            'body' => $request->input('Body'),
        ];
    }

    public function estimateCost(string $to, string $body): float
    {
        // Twilio SMS to Pakistan: ~$0.0065/segment; very rough estimate
        $segments = (int) ceil(mb_strlen($body) / 160);
        return $segments * 0.0065;
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
