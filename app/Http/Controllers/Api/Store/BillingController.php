<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\PaymentGateway;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\PaymentGateways\PaymentGatewayManager;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    use ApiResponse;

    public function gateways(): \Illuminate\Http\JsonResponse
    {
        $gateways = PaymentGateway::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['name', 'slug', 'logo', 'supported_currencies', 'supports_subscription', 'is_test_mode']);

        return $this->successResponse(['payment_gateways' => $gateways]);
    }

    public function plans(): \Illuminate\Http\JsonResponse
    {
        $plans = Plan::where('is_active', true)
            ->orderBy('price')
            ->get();

        return $this->successResponse(['plans' => $plans]);
    }

    public function subscription(): \Illuminate\Http\JsonResponse
    {
        $store = auth()->user()->store;

        if (! $store) {
            return $this->notFoundResponse('Store not found.');
        }

        $subscription = $store->activeSubscription ?? $store->subscriptions()->latest()->first();

        return $this->successResponse(['subscription' => $subscription]);
    }

    public function checkout(Request $request, PaymentGatewayManager $manager): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'gateway' => 'required|string|exists:payment_gateways,slug',
            'plan_id' => 'required|integer|exists:plans,id',
        ]);

        $gateway = PaymentGateway::where('slug', $validated['gateway'])->first();

        if (! $gateway || ! $gateway->is_active) {
            return $this->errorResponse('Selected payment gateway is not available.', 400);
        }

        $plan = Plan::findOrFail($validated['plan_id']);
        $store = auth()->user()->store;

        if (! $store) {
            return $this->notFoundResponse('Store not found.');
        }

        $subscription = Subscription::create([
            'store_id' => $store->id,
            'plan_id' => $plan->id,
            'status' => 'pending',
            'payment_gateway' => $gateway->slug,
            'billing_cycle' => $plan->billing_cycle,
            'amount' => $plan->price,
            'currency' => $plan->currency,
            'auto_renew' => $plan->billing_cycle !== 'lifetime',
            'starts_at' => now(),
        ]);

        try {
            $paymentGateway = $manager->for($gateway->slug);
            $checkout = $paymentGateway->createCheckoutSession($subscription);
        } catch (\Throwable $e) {
            return $this->errorResponse('Unable to create checkout session: ' . $e->getMessage(), 500);
        }

        return $this->successResponse([
            'subscription' => $subscription,
            'checkout' => $checkout,
        ], 'Checkout session created.');
    }

    public function checkoutStatus(Request $request, PaymentGatewayManager $manager): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'gateway' => 'required|string|exists:payment_gateways,slug',
            'session_id' => 'required|string',
        ]);

        $gateway = PaymentGateway::where('slug', $validated['gateway'])->first();

        if (! $gateway) {
            return $this->errorResponse('Selected payment gateway does not exist.', 400);
        }

        try {
            $paymentGateway = $manager->make($gateway->slug);
            $payment = $paymentGateway->handleCallback($request);
        } catch (\Throwable $e) {
            return $this->errorResponse('Unable to resolve checkout status: ' . $e->getMessage(), 400);
        }

        return $this->successResponse([
            'payment' => $payment,
            'subscription' => $payment->subscription,
        ], 'Checkout session status resolved.');
    }

    public function cancel(Request $request, PaymentGatewayManager $manager): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'subscription_id' => 'required|integer|exists:subscriptions,id',
        ]);

        $subscription = Subscription::findOrFail($validated['subscription_id']);

        if ($subscription->status !== 'active') {
            return $this->errorResponse('Only active subscriptions can be cancelled.', 400);
        }

        try {
            $gateway = $manager->make($subscription->payment_gateway ?? 'stripe');
            $cancelled = $gateway->cancelSubscription($subscription);
        } catch (\Throwable $e) {
            return $this->errorResponse('Unable to cancel subscription: ' . $e->getMessage(), 500);
        }

        if (! $cancelled) {
            return $this->errorResponse('Subscription cancellation failed.', 500);
        }

        $subscription->status = 'cancelled';
        $subscription->cancelled_at = now();
        $subscription->save();

        return $this->successResponse(['subscription' => $subscription], 'Subscription cancelled.');
    }
}
