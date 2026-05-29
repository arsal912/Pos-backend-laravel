<?php

namespace App\Services\PaymentGateways;

use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Http\Request;

class EasypaisaService extends BasePaymentGateway
{
    public function createCheckoutSession(Subscription $subscription): array
    {
        throw new \RuntimeException('Easypaisa checkout session creation is not implemented yet.');
    }

    public function handleCallback(Request $request): Payment
    {
        throw new \RuntimeException('Easypaisa callback handling is not implemented yet.');
    }

    public function handleWebhook(Request $request): void
    {
        // Easypaisa callbacks are handled in Step 4.
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
            throw new \RuntimeException('Easypaisa credentials are not configured.');
        }

        return true;
    }
}
