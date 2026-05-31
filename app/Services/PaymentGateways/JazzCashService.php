<?php

namespace App\Services\PaymentGateways;

use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class JazzCashService extends BasePaymentGateway
{
    protected string $gatewaySlug = 'jazzcash';
    protected string $gatewayName = 'JazzCash';

    // JazzCash success response code
    const SUCCESS_CODE = '000';

    // -------------------------------------------------------------------------
    // PaymentGatewayInterface
    // -------------------------------------------------------------------------

    public function createCheckoutSession(Subscription $subscription): array
    {
        $plan = $subscription->plan;

        if (($plan->currency ?? 'PKR') !== 'PKR') {
            throw new \RuntimeException('JazzCash only supports PKR currency.');
        }

        $now        = now();
        $expiry     = $now->copy()->addMinutes(30);
        $txnRef     = 'T' . $now->format('YmdHis') . strtoupper(Str::random(4));
        $amountPaisa = (int) round($plan->price * 100);

        $params = [
            'pp_Version'            => '1.1',
            'pp_TxnType'            => 'MWALLET',
            'pp_Language'           => 'EN',
            'pp_MerchantID'         => $this->credentials['merchant_id'] ?? env('JAZZCASH_MERCHANT_ID'),
            'pp_SubMerchantID'      => '',
            'pp_Password'           => $this->credentials['password'] ?? env('JAZZCASH_PASSWORD'),
            'pp_BankID'             => '',
            'pp_ProductID'          => '',
            'pp_TxnRefNo'           => $txnRef,
            'pp_Amount'             => (string) $amountPaisa,
            'pp_TxnCurrency'        => 'PKR',
            'pp_TxnDateTime'        => $now->format('YmdHis'),
            'pp_BillReference'      => 'sub-' . $subscription->id,
            'pp_Description'        => 'Subscription: ' . $plan->name,
            'pp_TxnExpiryDateTime'  => $expiry->format('YmdHis'),
            'pp_ReturnURL'          => url('/api/v1/payments/jazzcash/return'),
            'pp_IsRegisteredUser'   => 'F',
            'ppmpf_1'               => (string) $subscription->store_id,
            'ppmpf_2'               => (string) $subscription->id,
            'ppmpf_3'               => '',
            'ppmpf_4'               => '',
            'ppmpf_5'               => '',
        ];

        $params['pp_SecureHash'] = $this->buildHash($params);

        // Store the txnRef on the subscription for later lookup
        $subscription->update(['gateway_subscription_id' => $txnRef]);

        $this->logEvent([
            'store_id'        => $subscription->store_id,
            'subscription_id' => $subscription->id,
            'event_type'      => 'payment_initiated',
            'gateway'         => $this->gatewaySlug,
            'data'            => ['txn_ref' => $txnRef, 'amount_paisa' => $amountPaisa],
        ]);

        return [
            'checkout_url' => $this->paymentUrl(),
            'params'       => $params,
            'method'       => 'POST',
            'session_id'   => $txnRef,
        ];
    }

    /**
     * Called when JazzCash POSTs back to our /return URL.
     * Also usable as a direct programmatic check.
     */
    public function handleCallback(Request $request): Payment
    {
        $data = $request->all();

        $this->verifyHash($data);

        $responseCode = $data['pp_ResponseCode'] ?? '';
        $txnRef       = $data['pp_TxnRefNo'] ?? '';
        $storeId      = $data['ppmpf_1'] ?? null;
        $subscriptionId = $data['ppmpf_2'] ?? null;
        $amountPaisa  = (int) ($data['pp_Amount'] ?? 0);
        $amountPKR    = $amountPaisa / 100;
        $ppTxnId      = $data['pp_TxnRefNo'] ?? $txnRef;

        $status = $responseCode === self::SUCCESS_CODE ? 'completed' : 'failed';

        $payment = $this->createPaymentRecord([
            'gateway'            => $this->gatewaySlug,
            'gateway_payment_id' => $ppTxnId,
            'store_id'           => $storeId,
            'subscription_id'    => $subscriptionId,
            'amount'             => $amountPKR,
            'currency'           => 'PKR',
            'status'             => $status,
            'paid_at'            => $status === 'completed' ? now() : null,
            'invoice_number'     => $status === 'completed' ? $this->generateInvoiceNumber() : null,
            'failure_reason'     => $status !== 'completed' ? ($data['pp_ResponseMessage'] ?? 'Payment failed') : null,
            'gateway_response'   => $data,
        ]);

        $this->logEvent([
            'store_id'        => $storeId,
            'subscription_id' => $subscriptionId,
            'payment_id'      => $payment->id,
            'event_type'      => $status === 'completed' ? 'payment_succeeded' : 'payment_failed',
            'gateway'         => $this->gatewaySlug,
            'data'            => $data,
        ]);

        if ($status === 'completed' && $subscriptionId) {
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
     * JazzCash does not send server-to-server webhooks in the standard flow.
     * Browser return is handled by handleCallback via the /return route.
     */
    public function handleWebhook(Request $request): void
    {
        // No-op — JazzCash uses browser redirect callbacks, not async webhooks.
    }

    /**
     * JazzCash does not have a recurring billing API.
     * Cancellation is handled locally — the store owner simply won't renew.
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
        $merchantId    = $this->credentials['merchant_id'] ?? env('JAZZCASH_MERCHANT_ID');
        $password      = $this->credentials['password'] ?? env('JAZZCASH_PASSWORD');
        $integritySalt = $this->credentials['integrity_salt'] ?? env('JAZZCASH_INTEGRITY_SALT');

        if (! $merchantId || ! $password || ! $integritySalt) {
            throw new \RuntimeException('JazzCash merchant_id, password, and integrity_salt are all required.');
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Hash helpers
    // -------------------------------------------------------------------------

    /**
     * Build JazzCash SecureHash.
     *
     * Algorithm:
     *   1. Remove pp_SecureHash from params
     *   2. Sort params by key (ksort)
     *   3. Build string: {IntegritySalt}&{value1}&{value2}&... (values only, in sorted-key order)
     *   4. Return strtoupper(hash_hmac('sha256', string, IntegritySalt))
     */
    public function buildHash(array $params): string
    {
        $salt = $this->credentials['integrity_salt'] ?? env('JAZZCASH_INTEGRITY_SALT', '');

        unset($params['pp_SecureHash']);
        ksort($params);

        $hashString = $salt;
        foreach ($params as $value) {
            if ($value !== '') {
                $hashString .= '&' . $value;
            }
        }

        return strtoupper(hash_hmac('sha256', $hashString, $salt));
    }

    /**
     * Verify the pp_SecureHash on an incoming callback.
     * Throws RuntimeException if verification fails.
     */
    public function verifyHash(array $data): void
    {
        $receivedHash = $data['pp_SecureHash'] ?? '';
        $computed     = $this->buildHash($data);

        if (! hash_equals($computed, strtoupper($receivedHash))) {
            throw new \RuntimeException('JazzCash hash verification failed.');
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function paymentUrl(): string
    {
        $mode = $this->credentials['mode'] ?? env('JAZZCASH_MODE', 'sandbox');

        return $mode === 'production'
            ? 'https://payments.jazzcash.com.pk/CustomerPortal/transactionmanagement/merchantform/'
            : 'https://sandbox.jazzcash.com.pk/CustomerPortal/transactionmanagement/merchantform/';
    }

    private function computeNextBilling(Subscription $subscription): Carbon
    {
        return match ($subscription->billing_cycle) {
            'yearly' => Carbon::now()->addYear(),
            default  => Carbon::now()->addMonth(),
        };
    }
}
