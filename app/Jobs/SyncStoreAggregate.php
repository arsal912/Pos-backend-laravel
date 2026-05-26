<?php

namespace App\Jobs;

use App\Models\Branch;
use App\Models\Payment;
use App\Models\Store;
use App\Models\StoreAggregate;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncStoreAggregate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Store $store;

    public function __construct(Store $store)
    {
        $this->store = $store;
    }

    public function handle(): void
    {
        $activeUsersCount = User::where('store_id', $this->store->id)
            ->where('is_active', true)
            ->count();

        $branchesCount = 0;
        $subscriptionsCount = 0;
        $paymentsCount = 0;
        $totalRevenue = 0;
        $monthRevenue = 0;
        $todayRevenue = 0;
        $lastPaymentAt = null;

        try {
            tenancy()->initialize($this->store);

            $branchesCount = Branch::count();
            $subscriptionsCount = Subscription::count();
            $paymentsCount = Payment::count();
            $totalRevenue = (float) Payment::where('status', 'completed')->sum('amount');
            $monthRevenue = (float) Payment::where('status', 'completed')
                ->whereMonth('paid_at', now()->month)
                ->whereYear('paid_at', now()->year)
                ->sum('amount');
            $todayRevenue = (float) Payment::where('status', 'completed')
                ->whereDate('paid_at', today())
                ->sum('amount');
            $lastPaymentAt = Payment::orderByDesc('paid_at')->value('paid_at');
        } finally {
            tenancy()->end();
        }

        StoreAggregate::updateOrCreate(
            ['store_id' => $this->store->id],
            [
                'tenant_database' => $this->store->database()->getName(),
                'branches_count' => $branchesCount,
                'subscriptions_count' => $subscriptionsCount,
                'payments_count' => $paymentsCount,
                'active_users_count' => $activeUsersCount,
                'total_revenue' => $totalRevenue,
                'month_revenue' => $monthRevenue,
                'today_revenue' => $todayRevenue,
                'last_payment_at' => $lastPaymentAt,
                'last_synced_at' => now(),
                'meta' => [
                    'store_slug' => $this->store->slug,
                    'store_status' => $this->store->status,
                ],
            ]
        );
    }
}
