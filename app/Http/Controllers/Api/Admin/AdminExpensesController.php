<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Super-admin cross-store expense overview.
 * Iterates all tenant databases to aggregate expense data.
 */
class AdminExpensesController extends Controller
{
    use ApiResponse;

    public function overview(Request $request): JsonResponse
    {
        $stores = Store::orderBy('name')->get();

        $platform = [
            'total'         => 0.0,
            'this_month'    => 0.0,
            'this_year'     => 0.0,
            'by_category'   => [],
            'by_method'     => [],
        ];

        $storeStats  = [];
        $now         = now();

        foreach ($stores as $store) {
            $stat = [
                'store_id'    => $store->id,
                'store_name'  => $store->name,
                'total'       => 0.0,
                'this_month'  => 0.0,
                'count'       => 0,
                'top_category'=> null,
            ];

            try {
                $store->run(function () use (&$stat, &$platform, $now) {
                    if (! DB::getSchemaBuilder()->hasTable('expenses')) return;

                    // Totals
                    $stat['total']      = (float) DB::table('expenses')->whereNull('deleted_at')->sum('amount');
                    $stat['this_month'] = (float) DB::table('expenses')->whereNull('deleted_at')
                        ->whereMonth('expense_date', $now->month)
                        ->whereYear('expense_date', $now->year)
                        ->sum('amount');
                    $stat['count'] = (int) DB::table('expenses')->whereNull('deleted_at')->count();

                    // Top category for this store
                    $topCat = DB::table('expenses')->whereNull('deleted_at')
                        ->groupBy('category')
                        ->selectRaw('category, SUM(amount) as total')
                        ->orderByDesc('total')
                        ->first();
                    $stat['top_category'] = $topCat?->category;

                    // Platform totals
                    $platform['total']      += $stat['total'];
                    $platform['this_month'] += $stat['this_month'];
                    $platform['this_year']  += (float) DB::table('expenses')->whereNull('deleted_at')
                        ->whereYear('expense_date', $now->year)->sum('amount');

                    // Platform breakdown by category
                    DB::table('expenses')->whereNull('deleted_at')
                        ->groupBy('category')
                        ->selectRaw('category, SUM(amount) as total')
                        ->get()
                        ->each(function ($row) use (&$platform) {
                            $key = $row->category;
                            $platform['by_category'][$key] = ($platform['by_category'][$key] ?? 0) + (float) $row->total;
                        });

                    // Platform breakdown by payment method
                    DB::table('expenses')->whereNull('deleted_at')
                        ->groupBy('payment_method')
                        ->selectRaw('payment_method, SUM(amount) as total')
                        ->get()
                        ->each(function ($row) use (&$platform) {
                            $key = $row->payment_method;
                            $platform['by_method'][$key] = ($platform['by_method'][$key] ?? 0) + (float) $row->total;
                        });
                });
            } catch (\Throwable) {}

            $storeStats[] = $stat;
        }

        // Sort stores by total desc
        usort($storeStats, fn ($a, $b) => $b['total'] <=> $a['total']);

        // Sort platform categories
        arsort($platform['by_category']);
        arsort($platform['by_method']);

        $platform['total']      = round($platform['total'], 2);
        $platform['this_month'] = round($platform['this_month'], 2);
        $platform['this_year']  = round($platform['this_year'], 2);

        return $this->successResponse([
            'platform' => $platform,
            'stores'   => $storeStats,
        ]);
    }
}
