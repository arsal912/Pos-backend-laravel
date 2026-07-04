<?php

namespace App\Services\PaymentGateways;

use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Stripe\Checkout\Session as StripeSession;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;

class StripeService extends BasePaymentGateway
{
    protected string $gatewaySlug = 'stripe';
    protected string $gatewayName = 'Stripe';
    protected ?StripeClient $client = null;

    public function createCheckoutSession(Subscription $subscription): array
    {
        $client = $this->getStripeClient();
        $plan = $subscription->plan;

        if (! $plan) {
            throw new \RuntimeException('Subscription plan is required for Stripe checkout.');
        }

        $mode = in_array($plan->billing_cycle, ['monthly', 'yearly']) ? 'subscription' : 'payment';
        $currency = $this->normalizeCurrency($plan->currency ?? 'USD');

        $lineItem = [
            'price_data' => [
                'currency' => $currency,
                'product_data' => [
                    'name' => $plan->name,
                    'description' => $plan->description ?? $plan->name,
                ],
                'unit_amount' => (int) round($plan->price * 100),
            ],
            'quantity' => 1,
        ];

        if ($mode === 'subscription') {
            $lineItem['price_data']['recurring'] = [
                'interval' => $plan->billing_cycle === 'yearly' ? 'year' : 'month',
            ];
        }

        try {
            $session = $client->checkout->sessions->create([
                'payment_method_types' => ['card'],
                'mode' => $mode,
                'customer_email' => $subscription->store->email,
                'line_items' => [$lineItem],
                'client_reference_id' => (string) $subscription->id,
                'metadata' => [
                    'gateway' => $this->gatewaySlug,
                    'store_id' => $subscription->store_id,
                    'subscription_id' => $subscription->id,
                    'plan_id' => $plan->id,
                ],
                'success_url' => rtrim(env('FRONTEND_URL', config('app.url')), '/') . '/billing/success?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => rtrim(env('FRONTEND_URL', config('app.url')), '/') . '/billing/cancel',
            ]);
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Stripe checkout creation failed: ' . $e->getMessage());
        }

        return [
            'session_id' => $session->id,
            'checkout_url' => $session->url,
            'mode' => $mode,
        ];
    }

    public function handleCallback(Request $request): Payment
    {
        $sessionId = $request->input('session_id') ?? $request->input('checkout_session_id');

        if (! $sessionId) {
            throw new \RuntimeException('Stripe session_id is required for callback handling.');
        }

        $session = $this->getStripeClient()->checkout->sessions->retrieve($sessionId, [
            'expand' => ['payment_intent', 'subscription'],
        ]);

        $gatewayPaymentId = $session->payment_intent ?? $session->id;
        $payment = Payment::where('gateway_payment_id', $gatewayPaymentId)->first();

        if (! $payment && $session->payment_status === 'paid') {
            $metadata = (array) ($session->metadata ?? []);

            $payment = $this->createPaymentRecord([
                'gateway' => $this->gatewaySlug,
                'gateway_payment_id' => $gatewayPaymentId,
                'store_id' => $metadata['store_id'] ?? null,
                'subscription_id' => $metadata['subscription_id'] ?? null,
                'amount' => ($session->amount_total ?? 0) / 100,
                'currency' => strtoupper($session->currency ?? 'USD'),
                'status' => 'completed',
                'paid_at' => now(),
                'invoice_number' => $this->generateInvoiceNumber(),
                'gateway_response' => $session->toArray(),
            ]);
        }

        if (! $payment) {
            throw new \RuntimeException('Payment record not found for Stripe callback.');
        }

        return $payment;
    }

    public function handleWebhook(Request $request): void
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature');
        $webhookSecret = $this->credentials['webhook_secret'] ?? null;

        if (! $webhookSecret) {
            throw new \RuntimeException('Stripe webhook secret is not configured.');
        }

        try {
            $event = Webhook::constructEvent($payload, $signature, $webhookSecret);
        } catch (SignatureVerificationException $e) {
            throw new \RuntimeException('Stripe webhook signature verification failed: ' . $e->getMessage());
        } catch (\UnexpectedValueException $e) {
            throw new \RuntimeException('Invalid Stripe webhook payload: ' . $e->getMessage());
        }

        $object = $event->data->object;

        match ($event->type) {
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded' => $this->handleCheckoutSessionCompleted($object),
            'checkout.session.async_payment_failed' => $this->handleCheckoutSessionFailed($object),
            'invoice.payment_succeeded' => $this->handleInvoicePaymentSucceeded($object),
            'invoice.payment_failed' => $this->handleInvoicePaymentFailed($object),
            'customer.subscription.updated' => $this->handleCustomerSubscriptionUpdated($object),
            'customer.subscription.deleted' => $this->handleCustomerSubscriptionDeleted($object),
            default => null,
        };
    }

    protected function handleCheckoutSessionFailed($session): void
    {
        $metadata = (array) ($session->metadata ?? []);
        $gatewayPaymentId = $session->payment_intent ?? $session->id;
        $storeId = $metadata['store_id'] ?? null;
        $subscriptionId = $metadata['subscription_id'] ?? null;

        $payment = $this->createPaymentRecord([
            'gateway' => $this->gatewaySlug,
            'gateway_payment_id' => $gatewayPaymentId,
            'store_id' => $storeId,
            'subscription_id' => $subscriptionId,
            'amount' => ($session->amount_total ?? $session->amount_subtotal ?? 0) / 100,
            'currency' => strtoupper($session->currency ?? 'USD'),
            'status' => 'failed',
            'paid_at' => null,
            'failure_reason' => $session->payment_status ?? 'failed',
            'gateway_response' => $session->toArray(),
        ]);

        $this->logEvent([
            'store_id' => $storeId,
            'subscription_id' => $subscriptionId,
            'payment_id' => $payment->id,
            'event_type' => 'checkout.session.async_payment_failed',
            'gateway' => $this->gatewaySlug,
            'data' => $session->toArray(),
        ]);

        if ($subscriptionId) {
            $subscription = Subscription::find($subscriptionId);

            if ($subscription) {
                $this->updateSubscription($subscription, [
                    'status' => 'pending',
                ]);
            }
        }
    }

    protected function handleCustomerSubscriptionUpdated($stripeSubscription): void
    {
        $subscription = Subscription::where('gateway_subscription_id', $stripeSubscription->id)->first();

        if (! $subscription) {
            return;
        }

        $status = match ($stripeSubscription->status) {
            'active' => 'active',
            'past_due', 'unpaid' => 'pending',
            'canceled', 'cancelled' => 'cancelled',
            default => $subscription->status,
        };

        $updatedData = [
            'status' => $status,
            'next_billing_at' => isset($stripeSubscription->current_period_end)
                ? Carbon::createFromTimestamp($stripeSubscription->current_period_end)
                : $subscription->next_billing_at,
            'ends_at' => isset($stripeSubscription->current_period_end)
                ? Carbon::createFromTimestamp($stripeSubscription->current_period_end)
                : $subscription->ends_at,
        ];

        $this->updateSubscription($subscription, $updatedData);

        $this->logEvent([
            'store_id' => $subscription->store_id,
            'subscription_id' => $subscription->id,
            'event_type' => 'customer.subscription.updated',
            'gateway' => $this->gatewaySlug,
            'data' => $stripeSubscription->toArray(),
        ]);
    }

    protected function handleCustomerSubscriptionDeleted($stripeSubscription): void
    {
        $subscription = Subscription::where('gateway_subscription_id', $stripeSubscription->id)->first();

        if (! $subscription) {
            return;
        }

        $this->updateSubscription($subscription, [
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'ends_at' => isset($stripeSubscription->current_period_end)
                ? Carbon::createFromTimestamp($stripeSubscription->current_period_end)
                : now(),
        ]);

        $this->logEvent([
            'store_id' => $subscription->store_id,
            'subscription_id' => $subscription->id,
            'event_type' => 'customer.subscription.deleted',
            'gateway' => $this->gatewaySlug,
            'data' => $stripeSubscription->toArray(),
        ]);
    }

    public function cancelSubscription(Subscription $subscription): bool
    {
        if (! $subscription->gateway_subscription_id) {
            return false;
        }

        try {
            $this->getStripeClient()->subscriptions->cancel($subscription->gateway_subscription_id);

            return true;
        } catch (ApiErrorException $e) {
            return false;
        }
    }

    public function verifyPayment(string $gatewayPaymentId): array
    {
        $paymentIntent = $this->getStripeClient()->paymentIntents->retrieve($gatewayPaymentId);

        return [
            'status' => $paymentIntent->status,
            'amount' => $paymentIntent->amount / 100,
            'currency' => strtoupper($paymentIntent->currency),
            'raw' => $paymentIntent->toArray(),
        ];
    }

    public function createCustomerPortalSession(string $customerId): string
    {
        $client = $this->getStripeClient();
        $frontendUrl = rtrim(env('FRONTEND_URL', config('app.url')), '/');

        try {
            $session = $client->billingPortal->sessions->create([
                'customer'   => $customerId,
                'return_url' => $frontendUrl . '/dashboard/billing',
            ]);
            return $session->url;
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Stripe portal session failed: ' . $e->getMessage());
        }
    }

    public function refund(\App\Models\Payment $payment, float $amount): void
    {
        $client = $this->getStripeClient();

        if (! $payment->gateway_payment_id) {
            throw new \RuntimeException('No gateway payment ID to refund.');
        }

        try {
            $client->refunds->create([
                'payment_intent' => $payment->gateway_payment_id,
                'amount'         => (int) round($amount * 100),
            ]);
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Stripe refund failed: ' . $e->getMessage());
        }
    }

    public function changePlan(Subscription $subscription, \App\Models\Plan $newPlan): bool
    {
        if (! $subscription->gateway_subscription_id) {
            return false;
        }

        $client = $this->getStripeClient();

        try {
            $stripeSub = $client->subscriptions->retrieve($subscription->gateway_subscription_id);
            $itemId = $stripeSub->items->data[0]->id ?? null;

            if (! $itemId || ! $newPlan->stripe_price_id) {
                return false;
            }

            $client->subscriptions->update($subscription->gateway_subscription_id, [
                'items' => [['id' => $itemId, 'price' => $newPlan->stripe_price_id]],
                'proration_behavior' => 'create_prorations',
            ]);

            return true;
        } catch (ApiErrorException $e) {
            return false;
        }
    }

    public function testConnection(): bool
    {
        $client = $this->getStripeClient();
        $balance = $client->balance->retrieve();

        return isset($balance->available);
    }

    protected function handleCheckoutSessionCompleted(StripeSession $session): void
    {
        $metadata = (array) ($session->metadata ?? []);
        $gatewayPaymentId = $session->payment_intent ?? $session->id;
        $storeId = $metadata['store_id'] ?? null;
        $subscriptionId = $metadata['subscription_id'] ?? null;

        $payment = $this->createPaymentRecord([
            'gateway' => $this->gatewaySlug,
            'gateway_payment_id' => $gatewayPaymentId,
            'store_id' => $storeId,
            'subscription_id' => $subscriptionId,
            'amount' => ($session->amount_total ?? $session->amount_subtotal ?? 0) / 100,
            'currency' => strtoupper($session->currency ?? 'USD'),
            'status' => $session->payment_status === 'paid' ? 'completed' : 'pending',
            'paid_at' => $session->payment_status === 'paid' ? now() : null,
            'invoice_number' => $session->payment_status === 'paid' ? $this->generateInvoiceNumber() : null,
            'gateway_response' => $session->toArray(),
        ]);

        $this->logEvent([
            'store_id' => $storeId,
            'subscription_id' => $subscriptionId,
            'payment_id' => $payment->id,
            'event_type' => 'checkout.session.completed',
            'gateway' => $this->gatewaySlug,
            'data' => $session->toArray(),
        ]);

        if ($subscriptionId && $session->payment_status === 'paid') {
            $subscription = Subscription::find($subscriptionId);

            if ($subscription) {
                $period = $this->buildSubscriptionPeriod($subscription);

                $this->updateSubscription($subscription, [
                    'status' => 'active',
                    'gateway_customer_id' => $session->customer,
                    'gateway_subscription_id' => $session->subscription,
                    'starts_at' => now(),
                    'ends_at' => $period['ends_at'],
                    'next_billing_at' => $period['next_billing_at'],
                    'amount' => ($session->amount_total ?? $session->amount_subtotal ?? 0) / 100,
                    'currency' => strtoupper($session->currency ?? 'USD'),
                ]);
            }

            $this->sendReceiptEmail($payment);
        }
    }

    protected function handleInvoicePaymentSucceeded($invoice): void
    {
        $stripeSubscriptionId = $invoice->subscription ?? null;
        $subscription = Subscription::where('gateway_subscription_id', $stripeSubscriptionId)->first();
        $gatewayPaymentId = $invoice->payment_intent ?? $invoice->id;

        $payment = $this->createPaymentRecord([
            'gateway' => $this->gatewaySlug,
            'gateway_payment_id' => $gatewayPaymentId,
            'store_id' => $subscription?->store_id,
            'subscription_id' => $subscription?->id,
            'amount' => ($invoice->amount_paid ?? 0) / 100,
            'currency' => strtoupper($invoice->currency ?? 'USD'),
            'status' => 'completed',
            'paid_at' => now(),
            'invoice_number' => $this->generateInvoiceNumber(),
            'gateway_response' => $invoice->toArray(),
        ]);

        $this->logEvent([
            'store_id' => $subscription?->store_id,
            'subscription_id' => $subscription?->id,
            'payment_id' => $payment->id,
            'event_type' => 'invoice.payment_succeeded',
            'gateway' => $this->gatewaySlug,
            'data' => $invoice->toArray(),
        ]);

        if ($subscription) {
            $nextBillingAt = $this->getInvoiceNextBillingAt($invoice);

            $this->updateSubscription($subscription, [
                'status' => 'active',
                'ends_at' => $nextBillingAt,
                'next_billing_at' => $nextBillingAt,
            ]);

            $this->sendReceiptEmail($payment);
        }
    }

    protected function handleInvoicePaymentFailed($invoice): void
    {
        $stripeSubscriptionId = $invoice->subscription ?? null;
        $subscription = Subscription::where('gateway_subscription_id', $stripeSubscriptionId)->first();
        $gatewayPaymentId = $invoice->payment_intent ?? $invoice->id;

        $payment = $this->createPaymentRecord([
            'gateway' => $this->gatewaySlug,
            'gateway_payment_id' => $gatewayPaymentId,
            'store_id' => $subscription?->store_id,
            'subscription_id' => $subscription?->id,
            'amount' => ($invoice->amount_due ?? 0) / 100,
            'currency' => strtoupper($invoice->currency ?? 'USD'),
            'status' => 'failed',
            'paid_at' => null,
            'failure_reason' => $invoice->status,
            'gateway_response' => $invoice->toArray(),
        ]);

        $this->logEvent([
            'store_id' => $subscription?->store_id,
            'subscription_id' => $subscription?->id,
            'payment_id' => $payment->id,
            'event_type' => 'invoice.payment_failed',
            'gateway' => $this->gatewaySlug,
            'data' => $invoice->toArray(),
        ]);

        if ($subscription) {
            $this->updateSubscription($subscription, [
                'status' => 'pending',
                'grace_period_ends_at' => now()->addDays(3),
            ]);

            try {
                \Illuminate\Support\Facades\Mail::to($subscription->store->email)
                    ->send(new \App\Mail\PaymentFailed($subscription, $invoice->status ?? null));
            } catch (\Throwable) {
                // Non-fatal — payment event already logged
            }
        }
    }

    protected function buildSubscriptionPeriod(Subscription $subscription): array
    {
        if ($subscription->billing_cycle === 'yearly') {
            return [
                'ends_at' => Carbon::now()->addYear(),
                'next_billing_at' => Carbon::now()->addYear(),
            ];
        }

        if ($subscription->billing_cycle === 'monthly') {
            return [
                'ends_at' => Carbon::now()->addMonth(),
                'next_billing_at' => Carbon::now()->addMonth(),
            ];
        }

        return [
            'ends_at' => null,
            'next_billing_at' => null,
        ];
    }

    protected function getInvoiceNextBillingAt($invoice)
    {
        if (isset($invoice->lines->data[0]->period->end)) {
            return Carbon::createFromTimestamp($invoice->lines->data[0]->period->end);
        }

        return Carbon::now()->addMonth();
    }

    protected function normalizeCurrency(string $currency): string
    {
        return strtolower($currency);
    }

    protected function getStripeClient(): StripeClient
    {
        if ($this->client) {
            return $this->client;
        }

        $secretKey = $this->credentials['secret_key'] ?? $this->credentials['api_key'] ?? null;

        if (! $secretKey) {
            throw new \RuntimeException('Stripe secret key is not configured.');
        }

        $this->client = new StripeClient($secretKey);

        return $this->client;
    }
}
