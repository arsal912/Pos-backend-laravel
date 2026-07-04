<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\CommunicationProvider;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Super-admin view of platform-wide communication activity.
 * Iterates all tenant databases to aggregate stats.
 */
class AdminCommunicationsController extends Controller
{
    use ApiResponse;

    /**
     * Platform-wide overview: provider status + per-store stats.
     */
    public function overview(): JsonResponse
    {
        // 1. Provider status (central DB)
        $providers = CommunicationProvider::orderBy('channel')->orderBy('is_default', 'desc')->get([
            'id', 'channel', 'provider_slug', 'is_active', 'is_default', 'updated_at',
        ]);

        // 2. Per-store stats (iterate tenant DBs)
        $stores     = Store::orderBy('name')->get();
        $storeStats = [];
        $platform   = [
            'total_sent'     => 0,
            'total_failed'   => 0,
            'total_skipped'  => 0,
            'total_cost'     => 0.0,
            'by_channel'     => ['sms' => 0, 'email' => 0, 'whatsapp' => 0],
            'opt_out_total'  => 0,
        ];

        foreach ($stores as $store) {
            $stat = [
                'store_id'   => $store->id,
                'store_name' => $store->name,
                'sent'       => 0,
                'failed'     => 0,
                'skipped'    => 0,
                'cost'       => 0.0,
                'opt_outs'   => 0,
                'by_channel' => ['sms' => 0, 'email' => 0, 'whatsapp' => 0],
            ];

            try {
                $store->run(function () use (&$stat, &$platform) {
                    $since = now()->subDays(30);

                    // Aggregate log counts
                    $rows = DB::table('communication_logs')
                        ->where('created_at', '>=', $since)
                        ->select('channel', 'status', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(cost) as cost_sum'))
                        ->groupBy('channel', 'status')
                        ->get();

                    foreach ($rows as $row) {
                        $ch = $row->channel;
                        if ($row->status === 'sent') {
                            $stat['sent']            += $row->cnt;
                            $stat['cost']            += (float) ($row->cost_sum ?? 0);
                            $stat['by_channel'][$ch]  = ($stat['by_channel'][$ch] ?? 0) + $row->cnt;
                            $platform['total_sent']  += $row->cnt;
                            $platform['total_cost']  += (float) ($row->cost_sum ?? 0);
                            $platform['by_channel'][$ch] = ($platform['by_channel'][$ch] ?? 0) + $row->cnt;
                        } elseif ($row->status === 'failed') {
                            $stat['failed']          += $row->cnt;
                            $platform['total_failed']+= $row->cnt;
                        } else {
                            $stat['skipped']         += $row->cnt;
                            $platform['total_skipped']+= $row->cnt;
                        }
                    }

                    $stat['opt_outs']          = DB::table('communication_opt_outs')->count();
                    $platform['opt_out_total'] += $stat['opt_outs'];
                    $stat['cost']               = round($stat['cost'], 4);
                });
            } catch (\Throwable) {
                // Tenant DB may be unreachable — skip gracefully
            }

            $storeStats[] = $stat;
        }

        // Sort stores by sent desc
        usort($storeStats, fn ($a, $b) => $b['sent'] - $a['sent']);
        $platform['total_cost'] = round($platform['total_cost'], 4);

        return $this->successResponse([
            'providers'    => $providers,
            'platform'     => $platform,
            'stores'       => $storeStats,
            'window_days'  => 30,
        ]);
    }

    /**
     * Recent communication logs for a specific tenant store (for drill-down).
     */
    public function storeLogs(Request $request, int $storeId): JsonResponse
    {
        $store = Store::findOrFail($storeId);
        $logs  = [];

        $store->run(function () use (&$logs, $request) {
            $q = DB::table('communication_logs')
                ->orderBy('created_at', 'desc')
                ->limit(100);

            if ($request->filled('channel')) $q->where('channel', $request->input('channel'));
            if ($request->filled('status'))  $q->where('status',  $request->input('status'));

            $logs = $q->get([
                'id', 'recipient', 'channel', 'type', 'subject',
                'status', 'provider', 'cost', 'sent_at', 'created_at',
                'error_message', 'campaign_id',
            ])->toArray();
        });

        return $this->successResponse([
            'store_id'   => $storeId,
            'store_name' => $store->name,
            'logs'       => $logs,
        ]);
    }

    /**
     * Return per-store quota snapshot for all active tenants.
     */
    public function quotas(): JsonResponse
    {
        $stores = Store::orderBy('name')->get();
        $result = [];

        foreach ($stores as $store) {
            $quota = null;
            try {
                $store->run(function () use (&$quota) {
                    $quota = DB::table('communication_quotas')->first();
                });
            } catch (\Throwable) {}

            $result[] = [
                'store_id'   => $store->id,
                'store_name' => $store->name,
                'quota'      => $quota,
            ];
        }

        return $this->successResponse($result);
    }
}
