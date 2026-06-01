<?php

namespace App\Http\Controllers\Api\Store\Crm;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Customer;
use App\Models\LoyaltySettings;
use App\Models\LoyaltyTransaction;
use App\Services\LoyaltyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoyaltyController extends Controller
{
    use ApiResponse;

    public function __construct(private LoyaltyService $loyalty) {}

    // ── Settings ──────────────────────────────────────────────────────────────

    public function getSettings(): JsonResponse
    {
        return $this->successResponse(['settings' => LoyaltySettings::current()]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        if (! $request->user()->can('manage-loyalty')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'is_enabled'                  => 'sometimes|boolean',
            'points_per_currency_unit'    => 'sometimes|numeric|min:0',
            'redemption_value'            => 'sometimes|numeric|min:0',
            'minimum_points_to_redeem'    => 'sometimes|numeric|min:0',
            'maximum_redemption_per_sale' => 'nullable|numeric|min:0|max:100',
            'points_expiry_days'          => 'nullable|integer|min:1',
            'earn_on_discounted_sales'    => 'sometimes|boolean',
            'earn_on_tax'                 => 'sometimes|boolean',
            'welcome_bonus_points'        => 'sometimes|numeric|min:0',
            'birthday_bonus_points'       => 'sometimes|numeric|min:0',
            'referral_bonus_points'       => 'sometimes|numeric|min:0',
        ]);

        $settings = LoyaltySettings::current();
        $settings->update($validated);

        return $this->successResponse(['settings' => $settings->fresh()], 'Loyalty settings updated.');
    }

    // ── Stats ─────────────────────────────────────────────────────────────────

    public function stats(): JsonResponse
    {
        if (! request()->user()->can('view-loyalty')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $settings = LoyaltySettings::current();

        $totalOutstandingPoints = (float) Customer::sum('loyalty_points_balance');
        $totalOutstandingValue  = round($totalOutstandingPoints * (float) $settings->redemption_value, 2);

        $now = now();

        $earnedThisMonth = (float) LoyaltyTransaction::whereIn('type', ['earn', 'adjust_add', 'welcome_bonus', 'birthday_bonus', 'referral_bonus'])
            ->whereMonth('created_at', $now->month)
            ->whereYear('created_at', $now->year)
            ->sum('points');

        $redeemedThisMonth = (float) LoyaltyTransaction::where('type', 'redeem')
            ->whereMonth('created_at', $now->month)
            ->whereYear('created_at', $now->year)
            ->selectRaw('ABS(SUM(points)) as total')
            ->value('total');

        $expiringThisMonth = (float) LoyaltyTransaction::where('type', 'earn')
            ->whereMonth('expires_at', $now->month)
            ->whereYear('expires_at', $now->year)
            ->where('points', '>', 0)
            ->sum('points');

        $topEarners = Customer::where('loyalty_points_balance', '>', 0)
            ->orderByDesc('loyalty_points_balance')
            ->limit(10)
            ->get(['id', 'name', 'code', 'phone', 'loyalty_points_balance']);

        return $this->successResponse([
            'total_outstanding_points' => $totalOutstandingPoints,
            'total_outstanding_value'  => $totalOutstandingValue,
            'earned_this_month'        => $earnedThisMonth,
            'redeemed_this_month'      => $redeemedThisMonth,
            'expiring_this_month'      => $expiringThisMonth,
            'top_earners'              => $topEarners,
        ]);
    }

    // ── Per-customer ──────────────────────────────────────────────────────────

    public function history(Request $request, int $customerId): JsonResponse
    {
        if (! $request->user()->can('view-loyalty')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        Customer::findOrFail($customerId);

        $query = LoyaltyTransaction::where('customer_id', $customerId);

        if ($request->filled('type')) $query->where('type', $request->input('type'));

        return $this->paginatedResponse($query->latest()->paginate($request->input('per_page', 20)));
    }

    public function manualAdjust(Request $request, int $customerId): JsonResponse
    {
        if (! $request->user()->can('manage-loyalty')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'points' => 'required|numeric|not_in:0',
            'reason' => 'required|string|max:255',
        ]);

        Customer::findOrFail($customerId);
        $tx = $this->loyalty->manualAdjust($customerId, $validated['points'], $validated['reason']);

        return $this->successResponse([
            'transaction' => $tx,
            'new_balance' => $tx->balance_after,
        ], 'Loyalty balance adjusted.');
    }

    // ── All transactions ──────────────────────────────────────────────────────

    public function allTransactions(Request $request): JsonResponse
    {
        if (! $request->user()->can('view-loyalty')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $query = LoyaltyTransaction::with('customer:id,name,code,phone');

        if ($request->filled('customer_id')) $query->where('customer_id', $request->input('customer_id'));
        if ($request->filled('type'))        $query->where('type', $request->input('type'));
        if ($request->filled('date_from'))   $query->where('created_at', '>=', $request->input('date_from'));
        if ($request->filled('date_to'))     $query->where('created_at', '<=', $request->input('date_to') . ' 23:59:59');

        return $this->paginatedResponse($query->latest()->paginate($request->input('per_page', 30)));
    }
}
