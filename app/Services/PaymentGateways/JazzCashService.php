<?php

namespace App\Services\PaymentGateways;

use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Http\Request;

class JazzCashService extends BasePaymentGateway
{
    public function createCheckoutSession(Subscription $subscription): array
    {
        throw new \RuntimeException('JazzCash checkout session creation is not implemented yet.');
    }

    public function handleCallback(Request $request): Payment
    {
        throw new \RuntimeException('JazzCash callback handling is not implemented yet.');
    }

    public function handleWebhook(Request $request): void
    {
        // JazzCash callbacks are handled in Step 3.
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
            throw new \RuntimeException('JazzCash credentials are not configured.');
        }

        return true;
    }
}
