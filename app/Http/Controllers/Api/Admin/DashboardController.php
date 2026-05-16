<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\ApiLog;
use App\Models\Payment;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $totalStores = Store::count();
        $activeStores = Store::where('status', 'active')->where('is_active', true)->count();
        $suspendedStores = Store::where('status', 'suspended')->count();

        $totalRevenue = Payment::where('status', 'completed')->sum('amount');
        $monthRevenue = Payment::where('status', 'completed')
            ->whereMonth('paid_at', now()->month)
            ->whereYear('paid_at', now()->year)
            ->sum('amount');
        $todayRevenue = Payment::where('status', 'completed')
            ->whereDate('paid_at', today())
            ->sum('amount');

        $expiringSoon = Subscription::where('status', 'active')
            ->whereBetween('ends_at', [now(), now()->addDays(7)])
            ->count();

        $newStoresThisMonth = Store::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $totalUsers = User::where('is_super_admin', false)->count();

        // Recent activity from API logs
        $recentErrors = ApiLog::errors()
            ->latest()
            ->limit(10)
            ->get(['id', 'method', 'endpoint', 'response_status', 'user_id', 'created_at']);

        return $this->successResponse([
            'stats' => [
                'total_stores' => $totalStores,
                'active_stores' => $activeStores,
                'suspended_stores' => $suspendedStores,
                'total_users' => $totalUsers,
                'total_revenue' => $totalRevenue,
                'month_revenue' => $monthRevenue,
                'today_revenue' => $todayRevenue,
                'expiring_soon' => $expiringSoon,
                'new_stores_this_month' => $newStoresThisMonth,
            ],
            'recent_errors' => $recentErrors,
            'recent_stores' => Store::latest()->limit(5)->get(['id', 'name', 'email', 'status', 'created_at']),
        ]);
    }
}
