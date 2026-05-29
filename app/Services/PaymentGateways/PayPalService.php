<?php

namespace App\Services\PaymentGateways;

use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Http\Request;

class PayPalService extends BasePaymentGateway
{
    public function createCheckoutSession(Subscription $subscription): array
    {
        throw new \RuntimeException('PayPal checkout session creation is not implemented yet.');
    }

    public function handleCallback(Request $request): Payment
    {
        throw new \RuntimeException('PayPal callback handling is not implemented yet.');
    }

    public function handleWebhook(Request $request): void
    {
        // Implement PayPal webhook handlers in Step 2.
    }

    public function cancelSubscription(Subscription $subscription): bool
    {
        return false;
    }

    public function verifyPayment(string $gatewayPaymentId): array
    {
        return [];
    }

    public function testConnection(): bool
    {
        if (empty($this->credentials)) {
            throw new \RuntimeException('PayPal credentials are not configured.');
        }

        return true;
    }
}
