<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Jobs\SyncStoreAggregate;
use App\Models\Store;
use App\Models\StoreAggregate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $query = Store::with(['activeSubscription.plan'])
            ->withCount(['users', 'branches']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $stores = $query->latest()->paginate($request->input('per_page', 15));

        return $this->paginatedResponse($stores);
    }

    public function show(int $id): JsonResponse
    {
        $store = Store::with([
            'users:id,name,email,store_id,is_active',
            'branches',
            'activeSubscription.plan',
            'subscriptions.plan',
        ])
            ->withCount(['users', 'branches'])
            ->findOrFail($id);

        return $this->successResponse($store);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,active,suspended,expired',
            'reason' => 'nullable|string',
        ]);

        $store = Store::findOrFail($id);
        $store->update([
            'status' => $validated['status'],
            'is_active' => $validated['status'] === 'active',
        ]);

        return $this->successResponse($store, 'Store status updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $store = Store::findOrFail($id);
        $store->delete();

        return $this->successResponse(null, 'Store deleted');
    }

    /**
     * Login as the store owner (impersonation) — returns a token for that user.
     */
    public function impersonate(int $id): JsonResponse
    {
        $store = Store::with('users')->findOrFail($id);

        if (! $store->database()->manager()->databaseExists($store->database()->getName())) {
            return $this->errorResponse('Tenant database is not available for this store.', 500);
        }

        $owner = $store->users()->whereHas('roles', fn ($q) => $q->where('name', 'store-owner'))->first();

        if (!$owner) {
            return $this->errorResponse('Store owner not found.', 404);
        }

        $token = $owner->createToken('impersonation_' . now()->timestamp)->plainTextToken;

        return $this->successResponse([
            'token' => $token,
            'token_type' => 'Bearer',
            'impersonating' => [
                'user_id' => $owner->id,
                'user_name' => $owner->name,
                'store_id' => $store->id,
                'store_name' => $store->name,
            ],
        ], 'Impersonation token generated');
    }

    /**
     * Per-store analytics — queries tenant DB on demand.
     * GET /admin/stores/{id}/analytics
     */
    public function analytics(int $id, Request $request): JsonResponse
    {
        $store     = Store::findOrFail($id);
        $aggregate = StoreAggregate::where('store_id', $id)->first();
        $days      = (int) $request->input('days', 30);

        $salesByDay   = [];
        $topProducts  = [];
        $customerGrowth = [];

        try {
            $store->run(function () use ($days, &$salesByDay, &$topProducts, &$customerGrowth) {
                if (! DB::getSchemaBuilder()->hasTable('sales')) {
                    return;
                }

                // Daily sales for last N days
                $salesByDay = DB::table('sales')
                    ->where('status', 'completed')
                    ->whereDate('sale_date', '>=', now()->subDays($days)->toDateString())
                    ->groupBy('sale_date')
                    ->select('sale_date', DB::raw('COUNT(*) as count'), DB::raw('SUM(total) as amount'))
                    ->orderBy('sale_date')
                    ->get()
                    ->map(fn ($r) => ['date' => $r->sale_date, 'count' => (int) $r->count, 'amount' => (float) $r->amount])
                    ->toArray();

                // Top 10 products this month
                if (DB::getSchemaBuilder()->hasTable('sale_items')) {
                    $topProducts = DB::table('sale_items')
                        ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                        ->where('sales.status', 'completed')
                        ->whereMonth('sales.sale_date', now()->month)
                        ->whereYear('sales.sale_date', now()->year)
                        ->groupBy('sale_items.product_name')
                        ->select(
                            'sale_items.product_name',
                            DB::raw('SUM(sale_items.quantity) as qty_sold'),
                            DB::raw('SUM(sale_items.line_total) as revenue')
                        )
                        ->orderByDesc('revenue')
                        ->limit(10)
                        ->get()
                        ->toArray();
                }

                // Customer growth (new customers per day)
                if (DB::getSchemaBuilder()->hasTable('customers')) {
                    $customerGrowth = DB::table('customers')
                        ->whereNull('deleted_at')
                        ->whereDate('created_at', '>=', now()->subDays($days)->toDateString())
                        ->groupBy(DB::raw('DATE(created_at)'))
                        ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
                        ->orderBy('date')
                        ->get()
                        ->map(fn ($r) => ['date' => $r->date, 'count' => (int) $r->count])
                        ->toArray();
                }
            });
        } catch (\Throwable $e) {
            // Return whatever data we got
        }

        // Trigger a fresh sync in the background
        SyncStoreAggregate::dispatch($store);

        return $this->successResponse([
            'store'           => $store->only(['id', 'name', 'slug', 'email', 'status']),
            'aggregate'       => $aggregate,
            'sales_by_day'    => $salesByDay,
            'top_products'    => $topProducts,
            'customer_growth' => $customerGrowth,
        ]);
    }
}
