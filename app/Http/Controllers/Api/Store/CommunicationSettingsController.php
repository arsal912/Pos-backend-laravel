<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\CommunicationLog;
use App\Models\CommunicationOptOut;
use App\Models\CommunicationQuota;
use App\Models\CommunicationSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommunicationSettingsController extends Controller
{
    use ApiResponse;

    // ── Settings (sender identity) ────────────────────────────────────────────

    public function getSettings(): JsonResponse
    {
        return $this->successResponse(['settings' => CommunicationSetting::current()]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        if (! $request->user()->can('manage-settings')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'sms_sender_id'            => 'nullable|string|max:11|alpha_num',
            'email_from_address'       => 'nullable|email|max:255',
            'email_from_name'          => 'nullable|string|max:100',
            'store_physical_address'   => 'nullable|string|max:500',
        ]);

        $settings = CommunicationSetting::current();
        $settings->update($validated);

        return $this->successResponse(['settings' => $settings->fresh()], 'Communication settings updated.');
    }

    // ── Usage & Quota ─────────────────────────────────────────────────────────

    public function getUsage(): JsonResponse
    {
        $quota = CommunicationQuota::current();

        // Cost this month from communication_logs
        $now = now();
        $costThisMonth = (float) CommunicationLog::whereMonth('created_at', $now->month)
            ->whereYear('created_at', $now->year)
            ->where('status', 'sent')
            ->sum('cost');

        $sentThisMonth = CommunicationLog::whereMonth('created_at', $now->month)
            ->whereYear('created_at', $now->year)
            ->where('status', 'sent')
            ->select('channel', DB::raw('COUNT(*) as count'))
            ->groupBy('channel')
            ->pluck('count', 'channel');

        return $this->successResponse([
            'quota'         => $quota,
            'sent_this_month' => $sentThisMonth,
            'cost_this_month' => round($costThisMonth, 4),
            'channels' => [
                'sms' => [
                    'sent_today'    => $quota->sms_sent_today,
                    'daily_quota'   => $quota->sms_daily_quota,
                    'resets_at'     => $quota->sms_quota_resets_at,
                    'sent_month'    => $sentThisMonth['sms'] ?? 0,
                ],
                'email' => [
                    'sent_today'    => $quota->email_sent_today,
                    'daily_quota'   => $quota->email_daily_quota,
                    'resets_at'     => $quota->email_quota_resets_at,
                    'sent_month'    => $sentThisMonth['email'] ?? 0,
                ],
                'whatsapp' => [
                    'sent_today'    => $quota->whatsapp_sent_today,
                    'daily_quota'   => $quota->whatsapp_daily_quota,
                    'resets_at'     => $quota->whatsapp_quota_resets_at,
                    'sent_month'    => $sentThisMonth['whatsapp'] ?? 0,
                ],
            ],
        ]);
    }

    // ── Opt-outs ──────────────────────────────────────────────────────────────

    public function getOptOuts(Request $request): JsonResponse
    {
        $query = CommunicationOptOut::query();

        if ($request->filled('channel'))  $query->where('channel', $request->input('channel'));
        if ($request->filled('search')) {
            $term = $request->input('search');
            $query->where('recipient', 'like', "%{$term}%");
        }

        return $this->paginatedResponse($query->latest('opted_out_at')->paginate($request->input('per_page', 20)));
    }

    /**
     * Remove an opt-out (manually re-opt-in a recipient).
     * Requires explicit permission — should be used sparingly.
     */
    public function deleteOptOut(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('manage-customer-groups')) { // reusing existing permission
            return $this->errorResponse('Unauthorized.', 403);
        }

        $optOut = CommunicationOptOut::findOrFail($id);
        $optOut->delete();

        return $this->successResponse(null, "Opt-out removed. {$optOut->recipient} can now receive {$optOut->channel} messages.");
    }
}
