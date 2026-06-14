<?php

namespace App\Http\Controllers\Api\Store\Crm;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\CreditTransaction;
use App\Models\Customer;
use App\Services\CreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreditController extends Controller
{
    use ApiResponse;

    public function __construct(private CreditService $credit) {}

    // ── Per-customer ──────────────────────────────────────────────────────────

    public function history(Request $request, int $customerId): JsonResponse
    {
        if (! $request->user()->can('manage-customer-credit')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        Customer::findOrFail($customerId);

        $query = CreditTransaction::where('customer_id', $customerId);
        if ($request->filled('type')) $query->where('type', $request->input('type'));

        return $this->paginatedResponse($query->latest()->paginate($request->input('per_page', 20)));
    }

    public function recordPayment(Request $request, int $customerId): JsonResponse
    {
        if (! $request->user()->can('manage-customer-credit')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'amount'         => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,card,bank_transfer,jazzcash,easypaisa,other',
            'notes'          => 'nullable|string',
            'reference'      => 'nullable|string',
        ]);

        Customer::findOrFail($customerId);

        $tx = $this->credit->recordPayment(
            $customerId,
            $validated['amount'],
            $validated['payment_method'],
            $validated['notes'] ?? null,
            $validated['reference'] ?? null
        );

        $customer = Customer::find($customerId);

        return $this->successResponse([
            'transaction'       => $tx,
            'outstanding_balance' => $customer?->outstanding_balance,
        ], 'Payment recorded.');
    }

    // ── Reports ───────────────────────────────────────────────────────────────

    public function outstanding(Request $request): JsonResponse
    {
        if (! $request->user()->can('manage-customer-credit')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $query = Customer::where('outstanding_balance', '>', 0)
            ->with('group:id,name,color');

        if ($request->filled('group_id')) $query->where('customer_group_id', $request->input('group_id'));
        if ($request->filled('min_amount')) $query->where('outstanding_balance', '>=', $request->input('min_amount'));

        $customers = $query->orderByDesc('outstanding_balance')
            ->paginate($request->input('per_page', 20));

        $totalOutstanding = Customer::where('outstanding_balance', '>', 0)->sum('outstanding_balance');

        return $this->paginatedResponse($customers, 'Success', 200, [
            'total_outstanding' => (float) $totalOutstanding,
        ]);
    }

    public function aging(Request $request): JsonResponse
    {
        if (! $request->user()->can('manage-customer-credit')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $now = now();

        $buckets = [
            'current'    => ['customers' => 0, 'amount' => 0.0, 'label' => '0–30 days'],
            'days_31_60' => ['customers' => 0, 'amount' => 0.0, 'label' => '31–60 days'],
            'days_61_90' => ['customers' => 0, 'amount' => 0.0, 'label' => '61–90 days'],
            'days_90_plus'=> ['customers' => 0, 'amount' => 0.0, 'label' => '90+ days'],
        ];

        // Aggregate credit sales by age bucket
        $raw = CreditTransaction::where('type', 'sale_on_credit')
            ->select('customer_id', 'amount', 'created_at')
            ->get();

        $customerBuckets = [];
        foreach ($raw as $tx) {
            $days = (int) $tx->created_at->diffInDays($now);
            $bucket = match (true) {
                $days <= 30  => 'current',
                $days <= 60  => 'days_31_60',
                $days <= 90  => 'days_61_90',
                default      => 'days_90_plus',
            };
            $customerBuckets[$tx->customer_id][$bucket] = ($customerBuckets[$tx->customer_id][$bucket] ?? 0) + (float) $tx->amount;
        }

        foreach ($customerBuckets as $customerId => $cb) {
            foreach ($cb as $bucket => $amount) {
                if ($amount > 0 && isset($buckets[$bucket])) {
                    $buckets[$bucket]['customers']++;
                    $buckets[$bucket]['amount'] += $amount;
                }
            }
        }

        return $this->successResponse(['aging' => $buckets]);
    }

    public function payments(Request $request): JsonResponse
    {
        if (! $request->user()->can('manage-customer-credit')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $query = CreditTransaction::with('customer:id,name,code,phone')
            ->where('type', 'payment_received');

        if ($request->filled('customer_id')) $query->where('customer_id', $request->input('customer_id'));
        if ($request->filled('date_from'))   $query->where('created_at', '>=', $request->input('date_from'));
        if ($request->filled('date_to'))     $query->where('created_at', '<=', $request->input('date_to') . ' 23:59:59');
        if ($request->filled('payment_method')) $query->where('payment_method', $request->input('payment_method'));

        $payments = $query->latest()->paginate($request->input('per_page', 20));

        $now = now();
        $stats = [
            'today'     => (float) CreditTransaction::where('type','payment_received')->whereDate('created_at', $now->toDateString())->selectRaw('ABS(SUM(amount)) as t')->value('t'),
            'this_week' => (float) CreditTransaction::where('type','payment_received')->where('created_at','>=', $now->startOfWeek())->selectRaw('ABS(SUM(amount)) as t')->value('t'),
            'this_month'=> (float) CreditTransaction::where('type','payment_received')->whereMonth('created_at',$now->month)->whereYear('created_at',$now->year)->selectRaw('ABS(SUM(amount)) as t')->value('t'),
        ];

        return $this->paginatedResponse($payments, 'Success', 200, ['stats' => $stats]);
    }
}
