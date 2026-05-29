<?php

namespace App\Services\PaymentGateways;

use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Http\Request;

class ManualPaymentService extends BasePaymentGateway
{
    public function createCheckoutSession(Subscription $subscription): array
    {
        return [
            'redirect_url' => '',
            'gateway_session_id' => '',
        ];
    }

    public function handleCallback(Request $request): Payment
    {
        throw new \RuntimeException('Manual payment callback is not supported.');
    }

    public function handleWebhook(Request $request): void
    {
        // Manual payments do not support webhooks.
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
        return true;
    }
}
