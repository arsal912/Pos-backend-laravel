<?php

namespace App\Services\PaymentGateways;

use App\Models\Payment;
use App\Models\Subscription;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

class PayPalService extends BasePaymentGateway
{
    protected string $gatewaySlug = 'paypal';
    protected string $gatewayName = 'PayPal';

    private ?string $accessToken = null;
    private Client $http;

    public function __construct(array $credentials = [])
    {
        parent::__construct($credentials);
        $this->http = new Client(['timeout' => 30]);
    }

    // -------------------------------------------------------------------------
    // PaymentGatewayInterface
    // -------------------------------------------------------------------------

    public function createCheckoutSession(Subscription $subscription): array
    {
        $plan = $subscription->plan;

        if (! $plan) {
            throw new \RuntimeException('Subscription plan is required.');
        }

        if (! $plan->paypal_plan_id) {
            throw new \RuntimeException(
                "Plan \"{$plan->name}\" has no PayPal plan ID. Run php artisan paypal:sync-plans first."
            );
        }

        $token = $this->getAccessToken();
        $frontendUrl = rtrim(env('FRONTEND_URL', config('app.url')), '/');

        $payload = [
            'plan_id'    => $plan->paypal_plan_id,
            'custom_id'  => $subscription->store_id . ':' . $subscription->id,
            'subscriber' => [
                'email_address' => $subscription->store->email ?? '',
            ],
            'application_context' => [
                'brand_name'          => config('app.name'),
                'user_action'         => 'SUBSCRIBE_NOW',
                'payment_method'      => ['payer_selected' => 'PAYPAL', 'payee_preferred' => 'IMMEDIATE_PAYMENT_REQUIRED'],
                'return_url'          => $frontendUrl . '/billing/success?gateway=paypal',
                'cancel_url'          => $frontendUrl . '/billing/cancel',
            ],
        ];

        $response = $this->post('/v1/billing/subscriptions', $payload);

        $approvalLink = collect($response['links'] ?? [])
            ->firstWhere('rel', 'approve');

        if (! $approvalLink) {
            throw new \RuntimeException('PayPal did not return an approval link.');
        }

        $this->logEvent([
            'store_id'        => $subscription->store_id,
            'subscription_id' => $subscription->id,
            'event_type'      => 'payment_initiated',
            'gateway'         => $this->gatewaySlug,
            'data'            => $response,
        ]);

        return [
            'session_id'    => $response['id'],
            'checkout_url'  => $approvalLink['href'],
            'gateway'       => 'paypal',
        ];
    }

    public function handleCallback(Request $request): Payment
    {
        $paypalSubId = $request->input('subscription_id') ?? $request->input('token');

        if (! $paypalSubId) {
            throw new \RuntimeException('PayPal subscription_id not found in callback.');
        }

        $paypalSub = $this->get('/v1/billing/subscriptions/' . $paypalSubId);
        $customId  = $paypalSub['custom_id'] ?? '';

        [$storeId, $subscriptionId] = array_pad(explode(':', $customId, 2), 2, null);

        $payment = Payment::where('gateway', $this->gatewaySlug)
            ->where('gateway_payment_id', $paypalSubId)
            ->first();

        if (! $payment && ($paypalSub['status'] ?? '') === 'ACTIVE') {
            $payment = $this->createPaymentRecord([
                'gateway'            => $this->gatewaySlug,
                'gateway_payment_id' => $paypalSubId,
                'store_id'           => $storeId,
                'subscription_id'    => $subscriptionId,
                'amount'             => $paypalSub['billing_info']['last_payment']['amount']['value'] ?? 0,
                'currency'           => $paypalSub['billing_info']['last_payment']['amount']['currency_code'] ?? 'USD',
                'status'             => 'completed',
                'paid_at'            => now(),
                'invoice_number'     => $this->generateInvoiceNumber(),
                'gateway_response'   => $paypalSub,
            ]);

            if ($subscriptionId) {
                $sub = Subscription::find($subscriptionId);
                if ($sub) {
                    $nextBilling = isset($paypalSub['billing_info']['next_billing_time'])
                        ? Carbon::parse($paypalSub['billing_info']['next_billing_time'])
                        : $this->computeNextBilling($sub);

                    $this->updateSubscription($sub, [
                        'status'                   => 'active',
                        'gateway_customer_id'      => $paypalSub['subscriber']['payer_id'] ?? null,
                        'gateway_subscription_id'  => $paypalSubId,
                        'starts_at'                => now(),
                        'ends_at'                  => $nextBilling,
                        'next_billing_at'           => $nextBilling,
                    ]);

                    $this->sendReceiptEmail($payment);
                }
            }
        }

        if (! $payment) {
            throw new \RuntimeException('Payment record not found for PayPal callback.');
        }

        return $payment;
    }

    public function handleWebhook(Request $request): void
    {
        $this->verifyWebhookSignature($request);

        $body      = json_decode($request->getContent(), true) ?? [];
        $eventType = $body['event_type'] ?? '';
        $resource  = $body['resource'] ?? [];

        match ($eventType) {
            'BILLING.SUBSCRIPTION.ACTIVATED'        => $this->onSubscriptionActivated($resource),
            'PAYMENT.SALE.COMPLETED'                => $this->onSaleCompleted($resource),
            'BILLING.SUBSCRIPTION.PAYMENT.FAILED'   => $this->onSubscriptionPaymentFailed($resource),
            'BILLING.SUBSCRIPTION.CANCELLED',
            'BILLING.SUBSCRIPTION.EXPIRED'          => $this->onSubscriptionCancelled($resource),
            'BILLING.SUBSCRIPTION.UPDATED'          => $this->onSubscriptionUpdated($resource),
            default => null,
        };
    }

    public function cancelSubscription(Subscription $subscription): bool
    {
        if (! $subscription->gateway_subscription_id) {
            return false;
        }

        try {
            $this->post(
                "/v1/billing/subscriptions/{$subscription->gateway_subscription_id}/cancel",
                ['reason' => 'Customer requested cancellation']
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function verifyPayment(string $gatewayPaymentId): array
    {
        $sub = $this->get('/v1/billing/subscriptions/' . $gatewayPaymentId);

        return [
            'status'   => $sub['status'] ?? 'UNKNOWN',
            'amount'   => $sub['billing_info']['last_payment']['amount']['value'] ?? 0,
            'currency' => $sub['billing_info']['last_payment']['amount']['currency_code'] ?? 'USD',
            'raw'      => $sub,
        ];
    }

    public function testConnection(): bool
    {
        $this->getAccessToken(); // throws on bad credentials
        return true;
    }

    // -------------------------------------------------------------------------
    // Webhook event handlers
    // -------------------------------------------------------------------------

    private function onSubscriptionActivated(array $resource): void
    {
        $paypalSubId = $resource['id'] ?? null;
        $customId    = $resource['custom_id'] ?? '';

        [$storeId, $subscriptionId] = array_pad(explode(':', $customId, 2), 2, null);

        $subscription = $subscriptionId
            ? Subscription::find($subscriptionId)
            : Subscription::where('gateway_subscription_id', $paypalSubId)->first();

        if (! $subscription) {
            return;
        }

        $nextBilling = isset($resource['billing_info']['next_billing_time'])
            ? Carbon::parse($resource['billing_info']['next_billing_time'])
            : $this->computeNextBilling($subscription);

        $this->updateSubscription($subscription, [
            'status'                  => 'active',
            'gateway_subscription_id' => $paypalSubId,
            'gateway_customer_id'     => $resource['subscriber']['payer_id'] ?? null,
            'starts_at'               => now(),
            'ends_at'                 => $nextBilling,
            'next_billing_at'          => $nextBilling,
        ]);

        $this->logEvent([
            'store_id'        => $subscription->store_id,
            'subscription_id' => $subscription->id,
            'event_type'      => 'subscription_renewed',
            'gateway'         => $this->gatewaySlug,
            'data'            => $resource,
        ]);
    }

    private function onSaleCompleted(array $resource): void
    {
        $paypalSubId = $resource['billing_agreement_id'] ?? null;
        $saleId      = $resource['id'] ?? null;

        if (! $paypalSubId || ! $saleId) {
            return;
        }

        $subscription = Subscription::where('gateway_subscription_id', $paypalSubId)->first();

        $payment = $this->createPaymentRecord([
            'gateway'            => $this->gatewaySlug,
            'gateway_payment_id' => $saleId,
            'store_id'           => $subscription?->store_id,
            'subscription_id'    => $subscription?->id,
            'amount'             => $resource['amount']['total'] ?? 0,
            'currency'           => strtoupper($resource['amount']['currency'] ?? 'USD'),
            'status'             => 'completed',
            'paid_at'            => now(),
            'invoice_number'     => $this->generateInvoiceNumber(),
            'gateway_response'   => $resource,
        ]);

        $this->logEvent([
            'store_id'        => $subscription?->store_id,
            'subscription_id' => $subscription?->id,
            'payment_id'      => $payment->id,
            'event_type'      => 'payment_succeeded',
            'gateway'         => $this->gatewaySlug,
            'data'            => $resource,
        ]);

        if ($subscription) {
            $nextBilling = $this->computeNextBilling($subscription);
            $this->updateSubscription($subscription, [
                'status'         => 'active',
                'ends_at'        => $nextBilling,
                'next_billing_at' => $nextBilling,
            ]);

            $this->sendReceiptEmail($payment);
        }
    }

    private function onSubscriptionPaymentFailed(array $resource): void
    {
        $paypalSubId  = $resource['id'] ?? null;
        $subscription = Subscription::where('gateway_subscription_id', $paypalSubId)->first();

        if (! $subscription) {
            return;
        }

        $this->updateSubscription($subscription, [
            'status'               => 'pending',
            'grace_period_ends_at' => now()->addDays(3),
        ]);

        $this->logEvent([
            'store_id'        => $subscription->store_id,
            'subscription_id' => $subscription->id,
            'event_type'      => 'payment_failed',
            'gateway'         => $this->gatewaySlug,
            'data'            => $resource,
        ]);

        try {
            Mail::to($subscription->store->email)
                ->send(new \App\Mail\PaymentFailed($subscription));
        } catch (\Throwable) {
            // Non-fatal
        }
    }

    private function onSubscriptionCancelled(array $resource): void
    {
        $paypalSubId  = $resource['id'] ?? null;
        $subscription = Subscription::where('gateway_subscription_id', $paypalSubId)->first();

        if (! $subscription) {
            return;
        }

        $this->updateSubscription($subscription, [
            'status'       => 'cancelled',
            'cancelled_at' => now(),
        ]);

        $this->logEvent([
            'store_id'        => $subscription->store_id,
            'subscription_id' => $subscription->id,
            'event_type'      => 'subscription_cancelled',
            'gateway'         => $this->gatewaySlug,
            'data'            => $resource,
        ]);
    }

    private function onSubscriptionUpdated(array $resource): void
    {
        $paypalSubId  = $resource['id'] ?? null;
        $subscription = Subscription::where('gateway_subscription_id', $paypalSubId)->first();

        if (! $subscription) {
            return;
        }

        $status = match ($resource['status'] ?? '') {
            'ACTIVE'                          => 'active',
            'SUSPENDED', 'APPROVAL_PENDING'   => 'pending',
            'CANCELLED', 'EXPIRED'            => 'cancelled',
            default                           => $subscription->status,
        };

        $this->updateSubscription($subscription, ['status' => $status]);

        $this->logEvent([
            'store_id'        => $subscription->store_id,
            'subscription_id' => $subscription->id,
            'event_type'      => 'subscription_renewed',
            'gateway'         => $this->gatewaySlug,
            'data'            => $resource,
        ]);
    }

    // -------------------------------------------------------------------------
    // Webhook signature verification
    // -------------------------------------------------------------------------

    private function verifyWebhookSignature(Request $request): void
    {
        $webhookId = $this->credentials['webhook_id'] ?? null;

        if (! $webhookId) {
            throw new \RuntimeException('PayPal webhook_id is not configured.');
        }

        $token = $this->getAccessToken();

        $payload = [
            'auth_algo'        => $request->header('PAYPAL-AUTH-ALGO'),
            'cert_url'         => $request->header('PAYPAL-CERT-URL'),
            'transmission_id'  => $request->header('PAYPAL-TRANSMISSION-ID'),
            'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
            'transmission_time'=> $request->header('PAYPAL-TRANSMISSION-TIME'),
            'webhook_id'       => $webhookId,
            'webhook_event'    => json_decode($request->getContent(), true),
        ];

        $baseUrl  = $this->baseUrl();
        $response = $this->http->post("{$baseUrl}/v1/notifications/verify-webhook-signature", [
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Content-Type'  => 'application/json',
            ],
            'json' => $payload,
        ]);

        $result = json_decode((string) $response->getBody(), true);

        if (($result['verification_status'] ?? '') !== 'SUCCESS') {
            throw new \RuntimeException('PayPal webhook signature verification failed.');
        }
    }

    // -------------------------------------------------------------------------
    // PayPal REST API helpers
    // -------------------------------------------------------------------------

    private function getAccessToken(): string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        $clientId     = $this->credentials['client_id'] ?? env('PAYPAL_CLIENT_ID');
        $clientSecret = $this->credentials['client_secret'] ?? env('PAYPAL_CLIENT_SECRET');

        if (! $clientId || ! $clientSecret) {
            throw new \RuntimeException('PayPal client_id and client_secret are required.');
        }

        $response = $this->http->post($this->baseUrl() . '/v1/oauth2/token', [
            'auth'        => [$clientId, $clientSecret],
            'form_params' => ['grant_type' => 'client_credentials'],
        ]);

        $data = json_decode((string) $response->getBody(), true);

        if (empty($data['access_token'])) {
            throw new \RuntimeException('Failed to obtain PayPal access token.');
        }

        $this->accessToken = $data['access_token'];

        return $this->accessToken;
    }

    private function post(string $path, array $body = []): array
    {
        $token    = $this->getAccessToken();
        $response = $this->http->post($this->baseUrl() . $path, [
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Content-Type'  => 'application/json',
            ],
            'json' => $body,
        ]);

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    private function get(string $path): array
    {
        $token    = $this->getAccessToken();
        $response = $this->http->get($this->baseUrl() . $path, [
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Content-Type'  => 'application/json',
            ],
        ]);

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    private function baseUrl(): string
    {
        $mode = $this->credentials['mode'] ?? env('PAYPAL_MODE', 'sandbox');

        return $mode === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    private function computeNextBilling(Subscription $subscription): Carbon
    {
        return match ($subscription->billing_cycle) {
            'yearly'  => Carbon::now()->addYear(),
            default   => Carbon::now()->addMonth(),
        };
    }
}
