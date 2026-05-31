<?php

namespace App\Services\PaymentGateways;

use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Easypaisa v4 Direct Pay / Web Checkout integration.
 *
 * Hashing scheme (v4):
 *   1. Build sorted key=value pairs string from all request params (excl. merchantHashedReq)
 *   2. HMAC-SHA256(string, hashKey) → base64-encode raw bytes
 *
 * Note: Easypaisa has no recurring billing API. Each renewal requires a new
 * payment session. Renewal reminders are sent by `renewals:send-manual-reminders`.
 */
class EasypaisaService extends BasePaymentGateway
{
    protected string $gatewaySlug = 'easypaisa';
    protected string $gatewayName = 'Easypaisa';

    const STATUS_PAID = 'PAID';

    // -------------------------------------------------------------------------
    // PaymentGatewayInterface
    // -------------------------------------------------------------------------

    public function createCheckoutSession(Subscription $subscription): array
    {
        $plan = $subscription->plan;

        if (($plan->currency ?? 'PKR') !== 'PKR') {
            throw new \RuntimeException('Easypaisa only supports PKR currency.');
        }

        $storeId  = $this->credentials['store_id'] ?? env('EASYPAISA_STORE_ID');
        $hashKey  = $this->credentials['hash_key'] ?? env('EASYPAISA_HASH_KEY');

        if (! $storeId || ! $hashKey) {
            throw new \RuntimeException('Easypaisa store_id and hash_key are required.');
        }

        $now       = now();
        $expiry    = $now->copy()->addMinutes(30);
        $orderRef  = 'EP-' . $now->format('YmdHis') . '-' . strtoupper(Str::random(4));
        $returnUrl = url('/api/v1/payments/easypaisa/return');

        $params = [
            'amount'             => number_format($plan->price, 2, '.', ''),
            'expiryDate'         => $expiry->format('Ymd His'),   // yyyyMMdd HHmmss
            'orderRefNum'        => $orderRef,
            'paymentMethod'      => 'MA',  // MA = Mobile Account
            'postBackURL'        => $returnUrl,
            'storeId'            => $storeId,
        ];

        $params['merchantHashedReq'] = $this->buildHash($params, $hashKey);

        // Store identifiers for callback lookup
        $subscription->update([
            'gateway_subscription_id' => $orderRef,
        ]);

        // Extra fields sent to Easypaisa but NOT included in the hash
        $params['emailAddress']   = $subscription->store->email ?? '';
        $params['mobileNum']      = '';           // optional — customer mobile
        $params['tokenExpiry']    = '';
        $params['merchantPaymentMethod'] = 'MA';
        $params['paymentType']    = 'MA';
        // Custom fields for our callback
        $params['pp_storeId']     = (string) $subscription->store_id;
        $params['pp_subId']       = (string) $subscription->id;

        $this->logEvent([
            'store_id'        => $subscription->store_id,
            'subscription_id' => $subscription->id,
            'event_type'      => 'payment_initiated',
            'gateway'         => $this->gatewaySlug,
            'data'            => ['order_ref' => $orderRef, 'amount' => $plan->price],
        ]);

        return [
            'checkout_url' => $this->paymentUrl(),
            'params'       => $params,
            'method'       => 'POST',
            'session_id'   => $orderRef,
        ];
    }

    public function handleCallback(Request $request): Payment
    {
        $data = $request->all();

        $this->verifyHash($data);

        // Easypaisa uses 'orderRefNumber' in response (note: different from request 'orderRefNum')
        $orderRef      = $data['orderRefNumber'] ?? $data['orderRefNum'] ?? '';
        $status        = $data['status']  ?? 'UNPAID';
        $amount        = (float) ($data['amount'] ?? 0);
        $storeId       = $data['pp_storeId'] ?? null;
        $subscriptionId = $data['pp_subId'] ?? null;

        // Fallback: find subscription by gateway_subscription_id
        if (! $subscriptionId && $orderRef) {
            $sub = Subscription::where('gateway_subscription_id', $orderRef)->first();
            $storeId       = $storeId ?? $sub?->store_id;
            $subscriptionId = $subscriptionId ?? $sub?->id;
        }

        $paymentStatus = $status === self::STATUS_PAID ? 'completed' : 'failed';

        $payment = $this->createPaymentRecord([
            'gateway'            => $this->gatewaySlug,
            'gateway_payment_id' => $orderRef,
            'store_id'           => $storeId,
            'subscription_id'    => $subscriptionId,
            'amount'             => $amount,
            'currency'           => 'PKR',
            'status'             => $paymentStatus,
            'paid_at'            => $paymentStatus === 'completed' ? now() : null,
            'invoice_number'     => $paymentStatus === 'completed' ? $this->generateInvoiceNumber() : null,
            'failure_reason'     => $paymentStatus !== 'completed' ? ($data['desc'] ?? 'Payment failed') : null,
            'gateway_response'   => $data,
        ]);

        $this->logEvent([
            'store_id'        => $storeId,
            'subscription_id' => $subscriptionId,
            'payment_id'      => $payment->id,
            'event_type'      => $paymentStatus === 'completed' ? 'payment_succeeded' : 'payment_failed',
            'gateway'         => $this->gatewaySlug,
            'data'            => $data,
        ]);

        if ($paymentStatus === 'completed' && $subscriptionId) {
            $subscription = Subscription::find($subscriptionId);

            if ($subscription) {
                $nextBilling = $this->computeNextBilling($subscription);

                $this->updateSubscription($subscription, [
                    'status'          => 'active',
                    'starts_at'       => $subscription->starts_at ?? now(),
                    'ends_at'         => $nextBilling,
                    'next_billing_at' => $nextBilling,
                ]);

                $this->sendReceiptEmail($payment);
            }
        }

        return $payment;
    }

    /**
     * Easypaisa does not send async server-to-server webhooks.
     * Browser redirect is handled by handleCallback via the /return route.
     */
    public function handleWebhook(Request $request): void {}

    /**
     * Easypaisa has no recurring billing — cancel locally.
     */
    public function cancelSubscription(Subscription $subscription): bool
    {
        $this->updateSubscription($subscription, [
            'status'       => 'cancelled',
            'cancelled_at' => now(),
            'auto_renew'   => false,
        ]);

        return true;
    }

    public function verifyPayment(string $gatewayPaymentId): array
    {
        $payment = Payment::where('gateway', $this->gatewaySlug)
            ->where('gateway_payment_id', $gatewayPaymentId)
            ->first();

        return [
            'status'   => $payment?->status ?? 'unknown',
            'amount'   => $payment?->amount ?? 0,
            'currency' => 'PKR',
        ];
    }

    public function testConnection(): bool
    {
        $storeId = $this->credentials['store_id'] ?? env('EASYPAISA_STORE_ID');
        $hashKey = $this->credentials['hash_key'] ?? env('EASYPAISA_HASH_KEY');

        if (! $storeId || ! $hashKey) {
            throw new \RuntimeException('Easypaisa store_id and hash_key are required.');
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Hash helpers
    // -------------------------------------------------------------------------

    /**
     * Build Easypaisa merchantHashedReq.
     *
     * Algorithm (v4):
     *   1. Exclude merchantHashedReq and non-hash fields (email, mobile, pp_*)
     *   2. Sort remaining params alphabetically by key
     *   3. Build "key1=value1&key2=value2&..." string
     *   4. HMAC-SHA256(string, hashKey) → base64-encode raw output
     */
    public function buildHash(array $params, ?string $hashKey = null): string
    {
        $key = $hashKey ?? $this->credentials['hash_key'] ?? env('EASYPAISA_HASH_KEY', '');

        // Only hash the core transaction fields
        $hashable = array_filter($params, fn($k) => in_array($k, [
            'amount', 'expiryDate', 'orderRefNum', 'paymentMethod', 'postBackURL', 'storeId',
        ], true), ARRAY_FILTER_USE_KEY);

        ksort($hashable);

        $hashString = implode('&', array_map(
            fn($k, $v) => "{$k}={$v}",
            array_keys($hashable),
            array_values($hashable)
        ));

        return base64_encode(hash_hmac('sha256', $hashString, $key, true));
    }

    /**
     * Verify the merchantHashedReq on an incoming callback.
     * Throws RuntimeException on mismatch.
     */
    public function verifyHash(array $data): void
    {
        $received = $data['merchantHashedReq'] ?? '';

        // Rebuild params from callback — Easypaisa uses 'orderRefNumber' in response
        $verifyParams = [
            'amount'        => $data['amount'] ?? '',
            'expiryDate'    => $data['expiryDate'] ?? '',
            'orderRefNum'   => $data['orderRefNumber'] ?? $data['orderRefNum'] ?? '',
            'paymentMethod' => $data['paymentMethod'] ?? 'MA',
            'postBackURL'   => $data['postBackURL'] ?? url('/api/v1/payments/easypaisa/return'),
            'storeId'       => $data['storeId'] ?? ($this->credentials['store_id'] ?? ''),
        ];

        $computed = $this->buildHash($verifyParams);

        if (! hash_equals($computed, $received)) {
            throw new \RuntimeException('Easypaisa hash verification failed.');
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function paymentUrl(): string
    {
        $mode = $this->credentials['mode'] ?? env('EASYPAISA_MODE', 'sandbox');

        return $mode === 'production'
            ? 'https://easypay.easypaisa.com.pk/easypay/Index.jsf'
            : 'https://easypay.easypaisa.com.pk/easypay-sandbox/Index.jsf';
    }

    private function computeNextBilling(Subscription $subscription): Carbon
    {
        return match ($subscription->billing_cycle) {
            'yearly' => Carbon::now()->addYear(),
            default  => Carbon::now()->addMonth(),
        };
    }
}
