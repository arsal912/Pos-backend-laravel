<?php

namespace App\Contracts;

use App\Models\Subscription;
use App\Models\Payment;
use Illuminate\Http\Request;

interface PaymentGatewayInterface
{
    public function createCheckoutSession(Subscription $subscription): array;

    public function handleCallback(Request $request): Payment;

    public function handleWebhook(Request $request): void;

    public function cancelSubscription(Subscription $subscription): bool;

    public function verifyPayment(string $gatewayPaymentId): array;

    public function testConnection(): bool;
}
