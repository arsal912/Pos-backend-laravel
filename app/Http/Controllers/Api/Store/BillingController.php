<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\PaymentGateways\PaymentGatewayManager;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

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
        // Use model-class form of Rule::exists so validation queries the
        // central DB (from the model's $connection) even inside tenant context.
        $validated = $request->validate([
            'gateway' => ['required', 'string', Rule::exists(PaymentGateway::class, 'slug')],
            'plan_id' => ['required', 'integer', Rule::exists(Plan::class, 'id')],
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
            'subscription_id' => ['required', 'integer', Rule::exists(Subscription::class, 'id')],
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

    public function payments(Request $request): \Illuminate\Http\JsonResponse
    {
        $store = auth()->user()->store;

        if (! $store) {
            return $this->notFoundResponse('Store not found.');
        }

        $payments = Payment::where('store_id', $store->id)
            ->with('subscription.plan')
            ->latest('paid_at')
            ->paginate($request->input('per_page', 10));

        return $this->paginatedResponse($payments);
    }

    public function invoice(int $paymentId): \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
    {
        $store = auth()->user()->store;
        $payment = Payment::where('id', $paymentId)
            ->where('store_id', $store?->id)
            ->with(['subscription.plan', 'subscription.store'])
            ->firstOrFail();

        if (! $payment->invoice_number) {
            return $this->errorResponse('Invoice not available for this payment.', 404);
        }

        $path = "invoices/{$payment->store_id}/{$payment->invoice_number}.pdf";

        if (Storage::exists($path)) {
            return response(Storage::get($path), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"{$payment->invoice_number}.pdf\"",
            ]);
        }

        // Generate on-demand if not cached on disk
        $pdf = Pdf::loadView('invoices.default', ['payment' => $payment]);
        $pdfContent = $pdf->output();
        Storage::put($path, $pdfContent);

        return response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$payment->invoice_number}.pdf\"",
        ]);
    }

    public function verifySession(Request $request, PaymentGatewayManager $manager): \Illuminate\Http\JsonResponse
    {
        $sessionId = $request->input('session_id');

        if (! $sessionId) {
            return $this->errorResponse('session_id is required.', 422);
        }

        // Find payment by gateway session or payment ID
        $payment = Payment::where('gateway_payment_id', $sessionId)
            ->orWhere('gateway_payment_id', 'like', '%' . $sessionId . '%')
            ->with(['subscription.plan'])
            ->first();

        if ($payment) {
            return $this->successResponse([
                'status' => $payment->status,
                'payment' => $payment,
                'subscription' => $payment->subscription,
            ]);
        }

        // Not found yet — ask Stripe directly via handleCallback
        try {
            $request->merge(['session_id' => $sessionId]);
            $gateway = $manager->make('stripe');
            $payment = $gateway->handleCallback($request);

            return $this->successResponse([
                'status' => $payment->status,
                'payment' => $payment,
                'subscription' => $payment->subscription,
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('Session not found or not yet processed.', 404);
        }
    }

    public function changePlan(Request $request, PaymentGatewayManager $manager): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'integer', Rule::exists(Plan::class, 'id')],
        ]);

        $store = auth()->user()->store;

        if (! $store) {
            return $this->notFoundResponse('Store not found.');
        }

        $subscription = $store->activeSubscription;

        if (! $subscription) {
            return $this->errorResponse('No active subscription found.', 404);
        }

        $newPlan = Plan::findOrFail($validated['plan_id']);

        // Same plan — no-op
        if ($subscription->plan_id === $newPlan->id) {
            return $this->errorResponse('Already on this plan.', 400);
        }

        // For Stripe subscriptions that have a gateway_subscription_id, attempt
        // to update via Stripe API so billing is prorated correctly.
        if ($subscription->payment_gateway === 'stripe' && $subscription->gateway_subscription_id) {
            try {
                /** @var \App\Services\PaymentGateways\StripeService $stripe */
                $stripe = $manager->make('stripe');
                $result = $stripe->changePlan($subscription, $newPlan);

                if ($result) {
                    $subscription->plan_id = $newPlan->id;
                    $subscription->amount = $newPlan->price;
                    $subscription->save();

                    return $this->successResponse(['subscription' => $subscription->fresh('plan')], 'Plan updated.');
                }
            } catch (\Throwable $e) {
                // Fall through to local-only update
            }
        }

        // For other gateways or if Stripe update fails, update locally and
        // let the next billing cycle pick up the new plan.
        $subscription->plan_id = $newPlan->id;
        $subscription->amount = $newPlan->price;
        $subscription->save();

        return $this->successResponse(['subscription' => $subscription->fresh('plan')], 'Plan updated.');
    }
}
