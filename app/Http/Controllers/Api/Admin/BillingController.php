<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Store;
use App\Models\Subscription;
use App\Services\PaymentGateways\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    use ApiResponse;

    // -------------------------------------------------------------------------
    // Read endpoints
    // -------------------------------------------------------------------------

    public function subscriptions(Request $request): JsonResponse
    {
        $query = Subscription::with(['store', 'plan']);

        if ($request->filled('store_id')) {
            $query->where('store_id', $request->input('store_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('payment_gateway')) {
            $query->where('payment_gateway', $request->input('payment_gateway'));
        }
        if ($request->filled('plan_id')) {
            $query->where('plan_id', $request->input('plan_id'));
        }
        if ($days = $request->input('expiring_days')) {
            $query->where('ends_at', '<=', now()->addDays((int) $days))
                  ->where('ends_at', '>=', now())
                  ->where('status', 'active');
        }
        if ($search = $request->input('search')) {
            $query->whereHas('store', fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }

        return $this->paginatedResponse($query->latest()->paginate($request->input('per_page', 15)));
    }

    public function showSubscription(int $id): JsonResponse
    {
        return $this->successResponse(Subscription::with(['store', 'plan', 'payments'])->findOrFail($id));
    }

    public function payments(Request $request): JsonResponse
    {
        $query = Payment::with(['store', 'subscription.plan']);

        if ($request->filled('store_id'))       $query->where('store_id', $request->input('store_id'));
        if ($request->filled('subscription_id'))$query->where('subscription_id', $request->input('subscription_id'));
        if ($request->filled('status'))         $query->where('status', $request->input('status'));
        if ($request->filled('gateway'))        $query->where('gateway', $request->input('gateway'));

        if ($request->filled('date_from')) {
            $query->where('paid_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('paid_at', '<=', $request->input('date_to') . ' 23:59:59');
        }
        if ($request->filled('amount_min')) {
            $query->where('amount', '>=', $request->input('amount_min'));
        }
        if ($request->filled('amount_max')) {
            $query->where('amount', '<=', $request->input('amount_max'));
        }
        if ($search = $request->input('search')) {
            $query->where(fn ($q) => $q->where('invoice_number', 'like', "%{$search}%")
                ->orWhere('gateway_payment_id', 'like', "%{$search}%"));
        }

        return $this->paginatedResponse($query->latest('paid_at')->paginate($request->input('per_page', 15)));
    }

    public function showPayment(int $id): JsonResponse
    {
        return $this->successResponse(Payment::with(['store', 'subscription.plan'])->findOrFail($id));
    }

    public function events(Request $request): JsonResponse
    {
        $query = PaymentEvent::with(['store', 'subscription', 'payment']);

        if ($request->filled('store_id'))       $query->where('store_id', $request->input('store_id'));
        if ($request->filled('subscription_id'))$query->where('subscription_id', $request->input('subscription_id'));
        if ($request->filled('payment_id'))     $query->where('payment_id', $request->input('payment_id'));
        if ($request->filled('event_type'))     $query->where('event_type', $request->input('event_type'));
        if ($search = $request->input('search')) {
            $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(data, '$')) LIKE ?", ["%{$search}%"]);
        }

        return $this->paginatedResponse($query->latest()->paginate($request->input('per_page', 15)));
    }

    public function showEvent(int $id): JsonResponse
    {
        return $this->successResponse(PaymentEvent::with(['store', 'subscription', 'payment'])->findOrFail($id));
    }

    // -------------------------------------------------------------------------
    // Subscription management actions (customer support tools)
    // -------------------------------------------------------------------------

    public function extendSubscription(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['days' => 'required|integer|min:1|max:365']);

        $subscription = Subscription::with('store')->findOrFail($id);

        $base  = ($subscription->ends_at && $subscription->ends_at->isFuture())
            ? $subscription->ends_at
            : now();

        $subscription->ends_at        = $base->addDays($validated['days']);
        $subscription->next_billing_at = $subscription->ends_at;

        if ($subscription->status === 'expired') {
            $subscription->status = 'active';
            // Also reactivate the store if it was suspended due to expiry
            if ($subscription->store?->status === 'expired') {
                // status is excluded from Store::$fillable — assign directly.
                $subscription->store->status    = 'active';
                $subscription->store->is_active = true;
                $subscription->store->save();
            }
        }

        $subscription->save();

        PaymentEvent::create([
            'store_id'        => $subscription->store_id,
            'subscription_id' => $subscription->id,
            'event_type'      => 'subscription_renewed',
            'gateway'         => 'manual',
            'data'            => ['action' => 'admin_extend', 'days' => $validated['days'], 'by' => auth()->id()],
        ]);

        return $this->successResponse(['subscription' => $subscription->fresh('plan')], "Extended by {$validated['days']} days.");
    }

    public function cancelSubscription(int $id, PaymentGatewayManager $manager): JsonResponse
    {
        $subscription = Subscription::findOrFail($id);

        if ($subscription->gateway_subscription_id && $subscription->payment_gateway) {
            try {
                $manager->make($subscription->payment_gateway)->cancelSubscription($subscription);
            } catch (\Throwable) {
                // Log but don't block admin cancel
            }
        }

        $subscription->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        PaymentEvent::create([
            'store_id'        => $subscription->store_id,
            'subscription_id' => $subscription->id,
            'event_type'      => 'subscription_cancelled',
            'gateway'         => $subscription->payment_gateway ?? 'manual',
            'data'            => ['action' => 'admin_cancel', 'by' => auth()->id()],
        ]);

        return $this->successResponse(['subscription' => $subscription->fresh()], 'Subscription cancelled.');
    }

    public function reactivateSubscription(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['days' => 'sometimes|integer|min:1|max:365']);

        $subscription = Subscription::with('store')->findOrFail($id);

        $days = $validated['days'] ?? 30;
        $subscription->status        = 'active';
        $subscription->cancelled_at  = null;
        $subscription->grace_period_ends_at = null;
        $subscription->ends_at       = now()->addDays($days);
        $subscription->next_billing_at = $subscription->ends_at;
        $subscription->save();

        if ($subscription->store?->status === 'expired') {
            // status is excluded from Store::$fillable — assign directly.
            $subscription->store->status    = 'active';
            $subscription->store->is_active = true;
            $subscription->store->save();
        }

        PaymentEvent::create([
            'store_id'        => $subscription->store_id,
            'subscription_id' => $subscription->id,
            'event_type'      => 'subscription_renewed',
            'gateway'         => 'manual',
            'data'            => ['action' => 'admin_reactivate', 'days' => $days, 'by' => auth()->id()],
        ]);

        return $this->successResponse(['subscription' => $subscription->fresh('plan')], 'Subscription reactivated.');
    }

    // -------------------------------------------------------------------------
    // Payment refund
    // -------------------------------------------------------------------------

    public function refundPayment(Request $request, int $id, PaymentGatewayManager $manager): JsonResponse
    {
        $payment = Payment::with(['store', 'subscription'])->findOrFail($id);

        if ($payment->status !== 'completed') {
            return $this->errorResponse('Only completed payments can be refunded.', 400);
        }
        if ($payment->refunded_at) {
            return $this->errorResponse('Payment has already been refunded.', 400);
        }

        $refundAmount = (float) ($request->input('amount') ?? $payment->amount);
        $gateway      = $payment->gateway;

        // Gateways that support API refunds
        if (in_array($gateway, ['stripe', 'paypal'])) {
            try {
                $this->processGatewayRefund($payment, $refundAmount, $gateway, $manager);
            } catch (\Throwable $e) {
                return $this->errorResponse('Refund failed: ' . $e->getMessage(), 400);
            }
        }

        // For all gateways, mark payment as refunded locally
        $payment->update([
            'status'        => 'refunded',
            'refunded_at'   => now(),
            'refund_amount' => $refundAmount,
        ]);

        PaymentEvent::create([
            'store_id'        => $payment->store_id,
            'subscription_id' => $payment->subscription_id,
            'payment_id'      => $payment->id,
            'event_type'      => 'payment_refunded',
            'gateway'         => $gateway,
            'data'            => ['amount' => $refundAmount, 'by' => auth()->id()],
        ]);

        $note = in_array($gateway, ['jazzcash', 'easypaisa'])
            ? ' Note: Manual refund required — ' . ucfirst($gateway) . ' does not support API refunds.'
            : '';

        return $this->successResponse(['payment' => $payment->fresh()], 'Payment refunded.' . $note);
    }

    private function processGatewayRefund(Payment $payment, float $amount, string $gateway, PaymentGatewayManager $manager): void
    {
        if ($gateway === 'stripe') {
            /** @var \App\Services\PaymentGateways\StripeService $stripe */
            $stripe = $manager->make('stripe');
            $stripe->refund($payment, $amount);
        }
        // PayPal refund can be added similarly once PayPalService has a refund() method
    }

    // -------------------------------------------------------------------------
    // Stats summary for dashboard
    // -------------------------------------------------------------------------

    public function stats(): JsonResponse
    {
        $now = now();

        return $this->successResponse([
            'revenue_this_month'     => Payment::where('status', 'completed')
                ->whereMonth('paid_at', $now->month)->whereYear('paid_at', $now->year)
                ->sum('amount'),
            'total_transactions'     => Payment::count(),
            'success_rate'           => $this->computeSuccessRate(),
            'refunds_this_month'     => Payment::where('status', 'refunded')
                ->whereMonth('refunded_at', $now->month)->whereYear('refunded_at', $now->year)
                ->count(),
            'failed_payments_7d'     => Payment::where('status', 'failed')
                ->where('created_at', '>=', $now->copy()->subDays(7))
                ->count(),
            'subscriptions_expiring_7d' => Subscription::where('status', 'active')
                ->where('ends_at', '<=', $now->copy()->addDays(7))
                ->where('ends_at', '>=', $now)
                ->count(),
        ]);
    }

    /**
     * GET /admin/billing/subscription-report
     *
     * Categorises every store into one of:
     *   paid      — active subscription, amount > 0, has a successful payment this billing period
     *   free      — active subscription but amount = 0 (free plan / trial)
     *   pending   — subscription status = 'pending' (awaiting first payment)
     *   expiring  — active but ends_at within 7 days
     *   defaulter — subscription expired or grace period passed, store still "active"
     *   cancelled — subscription cancelled
     *   no_sub    — store has no subscription record at all
     */
    public function subscriptionReport(Request $request): JsonResponse
    {
        $now = now();
        $monthStart = $now->copy()->startOfMonth();

        // All stores with their latest subscription + plan
        $stores = Store::with(['activeSubscription.plan', 'subscriptions' => fn ($q) =>
                $q->latest()->limit(1)
            ])
            ->orderBy('name')
            ->get();

        $report = [
            'paid'      => [],
            'trial'     => [],
            'free'      => [],
            'pending'   => [],
            'expiring'  => [],
            'defaulter' => [],
            'cancelled' => [],
            'no_sub'    => [],
        ];

        $summary = [
            'paid'      => 0,
            'trial'     => 0,
            'free'      => 0,
            'pending'   => 0,
            'expiring'  => 0,
            'defaulter' => 0,
            'cancelled' => 0,
            'no_sub'    => 0,
            'total_stores' => $stores->count(),
            'mrr'       => 0.0, // Monthly Recurring Revenue (paid plans)
        ];

        foreach ($stores as $store) {
            $sub  = $store->activeSubscription ?? $store->subscriptions->first();
            $plan = $sub?->plan;

            $isOnTrial     = $store->trial_ends_at && $store->trial_ends_at->isFuture();
            $trialDaysLeft = $isOnTrial ? max(0, (int) $now->diffInDays($store->trial_ends_at, false)) : null;

            $row = [
                'store_id'          => $store->id,
                'store_name'        => $store->name,
                'store_email'       => $store->email,
                'subscription_id'   => $sub?->id,
                'store_status'      => $store->status,
                'registered_at'     => $store->created_at,           // NEW: store registration date
                'plan_name'         => $plan?->name ?? 'None',
                'amount'            => $sub ? (float) $sub->amount : 0.0,
                'currency'          => $sub?->currency ?? 'PKR',
                'billing_cycle'     => $sub?->billing_cycle ?? null,
                'ends_at'           => $sub?->ends_at,
                'next_billing_at'   => $sub?->next_billing_at,
                'payment_gateway'   => $sub?->payment_gateway,
                'sub_status'        => $sub?->status ?? 'none',
                'trial_ends_at'     => $store->trial_ends_at,        // NEW: trial expiry date
                'is_on_trial'       => $isOnTrial,                   // NEW: is currently in trial
                'trial_days_left'   => $trialDaysLeft,               // NEW: days remaining on trial
                'last_payment'      => null,
                'days_until_expiry' => $sub?->ends_at ? (int) $now->diffInDays($sub->ends_at, false) : null,
            ];

            // Last successful payment for this store this month
            $lastPayment = Payment::where('store_id', $store->id)
                ->where('status', 'completed')
                ->where('paid_at', '>=', $monthStart)
                ->latest('paid_at')
                ->first();

            if ($lastPayment) {
                $row['last_payment'] = [
                    'amount'  => (float) $lastPayment->amount,
                    'paid_at' => $lastPayment->paid_at,
                    'gateway' => $lastPayment->gateway,
                ];
            }

            // Classify — trial checked first so active-trial stores show in Trial, not Paid/Free
            if (! $sub) {
                $bucket = $isOnTrial ? 'trial' : 'no_sub';
            } elseif ($sub->status === 'cancelled') {
                $bucket = 'cancelled';
            } elseif ($sub->status === 'expired' || $store->status === 'expired') {
                $bucket = 'defaulter';
            } elseif ($sub->status === 'pending') {
                $bucket = $isOnTrial ? 'trial' : 'pending';
            } elseif ($isOnTrial && (float) $sub->amount == 0) {
                // Free plan + active trial → Trial bucket
                $bucket = 'trial';
            } elseif ((float) $sub->amount == 0) {
                $bucket = 'free';
            } elseif ($sub->ends_at && $sub->ends_at->diffInDays($now, false) <= 0 && $sub->ends_at->diffInDays($now, false) > -7) {
                $bucket = 'expiring';
            } elseif ($sub->status === 'active' && (float) $sub->amount > 0) {
                $bucket = 'paid';
                $summary['mrr'] += (float) $sub->amount;
            } else {
                $bucket = 'no_sub';
            }

            $report[$bucket][] = $row;
            $summary[$bucket]++;
        }

        $summary['mrr'] = round($summary['mrr'], 2);

        return $this->successResponse([
            'summary'    => $summary,
            'report'     => $report,
            'generated_at' => $now->toIso8601String(),
            'period'     => $monthStart->format('F Y'),
        ]);
    }

    /**
     * POST /admin/billing/subscriptions/{id}/mark-unpaid
     * Manually marks an active subscription as pending (unpaid).
     * Useful for cash/bank stores that miss a payment cycle.
     */
    public function markUnpaid(int $id): JsonResponse
    {
        $subscription = Subscription::with('store')->findOrFail($id);

        $subscription->update(['status' => 'pending']);

        PaymentEvent::create([
            'store_id'        => $subscription->store_id,
            'subscription_id' => $subscription->id,
            'event_type'      => 'subscription_updated',
            'gateway'         => 'manual',
            'data'            => ['action' => 'admin_mark_unpaid', 'by' => auth()->id()],
        ]);

        return $this->successResponse(
            ['subscription' => $subscription->fresh('plan')],
            'Subscription marked as unpaid (pending).'
        );
    }

    private function computeSuccessRate(): float
    {
        $total     = Payment::whereIn('status', ['completed', 'failed'])->count();
        $completed = Payment::where('status', 'completed')->count();
        return $total > 0 ? round(($completed / $total) * 100, 1) : 0.0;
    }
}
