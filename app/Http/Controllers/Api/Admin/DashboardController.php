<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\ApiLog;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Store;
use App\Models\StoreAggregate;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $totalStores = Store::count();
        $activeStores = Store::where('status', 'active')->where('is_active', true)->count();
        $suspendedStores = Store::where('status', 'suspended')->count();

        $totalRevenue = StoreAggregate::sum('total_revenue');
        $monthRevenue = StoreAggregate::sum('month_revenue');
        $todayRevenue = StoreAggregate::sum('today_revenue');

        $expiringSoon = Store::where('status', 'active')
            ->whereBetween('trial_ends_at', [now(), now()->addDays(7)])
            ->count();

        $newStoresThisMonth = Store::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $totalUsers = User::where('is_super_admin', false)->count();

        $totalSubscriptions = Subscription::count();
        $activeSubscriptions = Subscription::where('status', 'active')->count();
        $totalPayments = Payment::count();
        $completedRevenue = Payment::where('status', 'completed')->sum('amount');
        $pendingPayments = Payment::where('status', 'pending')->count();
        $failedPayments = Payment::where('status', 'failed')->count();

        $failedPayments7d = Payment::where('status', 'failed')
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        $subscriptionsExpiring7d = Subscription::where('status', 'active')
            ->where('ends_at', '<=', now()->addDays(7))
            ->where('ends_at', '>=', now())
            ->count();

        $totalBillingEvents = PaymentEvent::count();
        $billingEventsLast7Days = PaymentEvent::where('created_at', '>=', now()->subDays(7))->count();
        $billingEventsByType = PaymentEvent::selectRaw('event_type, count(*) as count')
            ->groupBy('event_type')
            ->get()
            ->mapWithKeys(fn ($item) => [$item->event_type => $item->count]);

        // Recent activity from API logs
        $recentErrors = ApiLog::errors()
            ->latest()
            ->limit(10)
            ->get(['id', 'method', 'endpoint', 'response_status', 'user_id', 'created_at']);

        $recentStores = Store::with('aggregate')
            ->latest()
            ->limit(5)
            ->get(['id', 'name', 'email', 'status', 'created_at'])
            ->map(function ($store) {
                return [
                    'id' => $store->id,
                    'name' => $store->name,
                    'email' => $store->email,
                    'status' => $store->status,
                    'created_at' => $store->created_at,
                    'aggregate' => $store->aggregate?->only([
                        'branches_count',
                        'subscriptions_count',
                        'payments_count',
                        'active_users_count',
                        'total_revenue',
                        'last_synced_at',
                    ]),
                ];
            });

        // Platform-wide Phase 4 aggregates from store_aggregates.meta
        $aggregates = \App\Models\StoreAggregate::all();

        $platformSalesToday  = $aggregates->sum(fn ($a) => data_get($a->meta, 'sales_today_amount', 0));
        $platformSalesMonth  = $aggregates->sum(fn ($a) => data_get($a->meta, 'sales_month_count', 0));
        $totalProducts       = $aggregates->sum(fn ($a) => data_get($a->meta, 'total_products', 0));
        $totalCustomers      = $aggregates->sum(fn ($a) => data_get($a->meta, 'total_customers', 0));
        $lowStockStores      = $aggregates->filter(fn ($a) => (int) data_get($a->meta, 'low_stock_count', 0) > 0)->count();

        // Top stores by today's revenue
        $topStoresByRevenue = \App\Models\StoreAggregate::with('store:id,name,slug')
            ->orderByDesc('today_revenue')
            ->limit(5)
            ->get()
            ->map(fn ($a) => [
                'store_name'    => $a->store?->name,
                'today_revenue' => (float) $a->today_revenue,
                'month_revenue' => (float) $a->month_revenue,
                'total_revenue' => (float) $a->total_revenue,
                'sales_today'   => (int) data_get($a->meta, 'sales_today_count', 0),
            ]);

        $recentBillingEvents = PaymentEvent::with(['store:id,name', 'subscription:id,store_id,plan_id'])
            ->latest()
            ->limit(5)
            ->get()
            ->map(function ($event) {
                return [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'gateway' => $event->gateway,
                    'store' => $event->store?->only(['id', 'name']),
                    'subscription_id' => $event->subscription_id,
                    'payment_id' => $event->payment_id,
                    'created_at' => $event->created_at,
                ];
            });

        return $this->successResponse([
            'stats' => [
                'total_stores' => $totalStores,
                'active_stores' => $activeStores,
                'suspended_stores' => $suspendedStores,
                'total_users' => $totalUsers,
                'central_subscriptions' => [
                    'total' => $totalSubscriptions,
                    'active' => $activeSubscriptions,
                ],
                'central_payments' => [
                    'total' => $totalPayments,
                    'completed_revenue' => $completedRevenue,
                    'pending' => $pendingPayments,
                    'failed' => $failedPayments,
                    'failed_7d' => $failedPayments7d,
                ],
                'subscriptions_expiring_7d' => $subscriptionsExpiring7d,
                'billing_events' => [
                    'total' => $totalBillingEvents,
                    'last_7_days' => $billingEventsLast7Days,
                    'by_type' => $billingEventsByType,
                ],
                'total_revenue' => $totalRevenue,
                'month_revenue' => $monthRevenue,
                'today_revenue' => $todayRevenue,
                'expiring_soon' => $expiringSoon,
                'new_stores_this_month' => $newStoresThisMonth,
                'platform_sales_today'  => $platformSalesToday,
                'platform_sales_month_count' => $platformSalesMonth,
                'platform_total_products' => $totalProducts,
                'platform_total_customers' => $totalCustomers,
                'low_stock_stores' => $lowStockStores,
            ],
            'recent_errors' => $recentErrors,
            'recent_stores' => $recentStores,
            'recent_billing_events' => $recentBillingEvents,
            'top_stores_by_revenue' => $topStoresByRevenue,
        ]);
    }

    /**
     * Sales over time (payments-based)
     */
    public function salesOverTime(Request $request): JsonResponse
    {
        $days = (int) $request->query('days', 30);
        $start = now()->subDays($days - 1)->startOfDay();

        $rows = Payment::where('status', 'completed')
            ->where('created_at', '>=', $start)
            ->selectRaw("DATE(created_at) as date, SUM(amount) as revenue, COUNT(*) as count")
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date')
            ->map(function ($r) {
                return ['revenue' => (float) $r->revenue, 'count' => (int) $r->count];
            })->toArray();

        $series = [];
        for ($d = $start->copy(); $d->lte(now()); $d->addDay()) {
            $key = $d->toDateString();
            $series[] = ['date' => $key, 'revenue' => $rows[$key]['revenue'] ?? 0.0, 'count' => $rows[$key]['count'] ?? 0];
        }

        return $this->successResponse(['series' => $series]);
    }

    public function topStoresByRevenue(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 10);
        $rows = StoreAggregate::with('store:id,name')
            ->orderByDesc('today_revenue')
            ->limit($limit)
            ->get()
            ->map(function ($a) {
                return [
                    'store_id' => $a->store_id,
                    'store_name' => $a->store?->name,
                    'today_revenue' => (float) $a->today_revenue,
                    'month_revenue' => (float) $a->month_revenue,
                    'total_revenue' => (float) $a->total_revenue,
                ];
            });

        return $this->successResponse(['top_stores' => $rows]);
    }

    public function paymentsBreakdown(): JsonResponse
    {
        $rows = Payment::where('status', 'completed')
            ->selectRaw('COALESCE(gateway, "unknown") as gateway, SUM(amount) as amount, COUNT(*) as count')
            ->groupBy('gateway')
            ->get()
            ->map(fn ($r) => ['gateway' => $r->gateway, 'amount' => (float) $r->amount, 'count' => (int) $r->count]);

        return $this->successResponse(['breakdown' => $rows]);
    }

    public function subscriptionsComparison(): JsonResponse
    {
        $thisMonth = Subscription::whereYear('created_at', now()->year)->whereMonth('created_at', now()->month)->count();
        $lastMonth = Subscription::whereYear('created_at', now()->subMonth()->year)->whereMonth('created_at', now()->subMonth()->month)->count();

        $change = $lastMonth === 0 ? null : round((($thisMonth - $lastMonth) / max(1, $lastMonth)) * 100, 2);

        return $this->successResponse(['this_month' => $thisMonth, 'last_month' => $lastMonth, 'percent_change' => $change]);
    }
}
