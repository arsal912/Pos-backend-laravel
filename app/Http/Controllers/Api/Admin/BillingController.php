<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    use ApiResponse;

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

        if ($search = $request->input('search')) {
            $query->whereHas('store', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $subscriptions = $query->latest()->paginate($request->input('per_page', 15));

        return $this->paginatedResponse($subscriptions);
    }

    public function showSubscription(int $id): JsonResponse
    {
        $subscription = Subscription::with(['store', 'plan', 'payments'])->findOrFail($id);

        return $this->successResponse($subscription);
    }

    public function payments(Request $request): JsonResponse
    {
        $query = Payment::with(['store', 'subscription']);

        if ($request->filled('store_id')) {
            $query->where('store_id', $request->input('store_id'));
        }

        if ($request->filled('subscription_id')) {
            $query->where('subscription_id', $request->input('subscription_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('gateway')) {
            $query->where('gateway', $request->input('gateway'));
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhere('gateway_payment_id', 'like', "%{$search}%");
            });
        }

        $payments = $query->latest()->paginate($request->input('per_page', 15));

        return $this->paginatedResponse($payments);
    }

    public function showPayment(int $id): JsonResponse
    {
        $payment = Payment::with(['store', 'subscription'])->findOrFail($id);

        return $this->successResponse($payment);
    }

    public function events(Request $request): JsonResponse
    {
        $query = PaymentEvent::with(['store', 'subscription', 'payment']);

        if ($request->filled('store_id')) {
            $query->where('store_id', $request->input('store_id'));
        }

        if ($request->filled('subscription_id')) {
            $query->where('subscription_id', $request->input('subscription_id'));
        }

        if ($request->filled('payment_id')) {
            $query->where('payment_id', $request->input('payment_id'));
        }

        if ($request->filled('event_type')) {
            $query->where('event_type', $request->input('event_type'));
        }

        if ($search = $request->input('search')) {
            $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(data, '$')) LIKE ?", ["%{$search}%"]);
        }

        $events = $query->latest()->paginate($request->input('per_page', 15));

        return $this->paginatedResponse($events);
    }

    public function showEvent(int $id): JsonResponse
    {
        $event = PaymentEvent::with(['store', 'subscription', 'payment'])->findOrFail($id);

        return $this->successResponse($event);
    }
}
