<?php

namespace App\Services\PaymentGateways;

use App\Contracts\PaymentGatewayInterface;
use App\Models\PaymentGateway;
use Illuminate\Contracts\Container\BindingResolutionException;

class PaymentGatewayManager
{
    public function for(string $gatewaySlug): PaymentGatewayInterface
    {
        $gateway = PaymentGateway::where('slug', $gatewaySlug)->firstOrFail();

        if (! $gateway->is_active) {
            throw new \RuntimeException("Payment gateway {$gatewaySlug} is not active.");
        }

        return $this->make($gatewaySlug);
    }

    public function make(string $gatewaySlug): PaymentGatewayInterface
    {
        $gateway = PaymentGateway::where('slug', $gatewaySlug)->firstOrFail();
        $credentials = $gateway->credentials ?? [];

        return $this->resolveGateway($gatewaySlug, $credentials);
    }

    protected function resolveGateway(string $gatewaySlug, array $credentials): BasePaymentGateway
    {
        return match ($gatewaySlug) {
            'stripe' => new StripeService($credentials),
            'paypal' => new PayPalService($credentials),
            'jazzcash' => new JazzCashService($credentials),
            'easypaisa' => new EasypaisaService($credentials),
            'manual' => new ManualPaymentService($credentials),
            default => throw new \InvalidArgumentException("Unsupported gateway: {$gatewaySlug}"),
        };
    }
}
