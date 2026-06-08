<?php

namespace App\Http\Controllers\Api\Store\Crm;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Jobs\Communications\DispatchCampaignJob;
use App\Models\Campaign;
use App\Models\CommunicationLog;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\CustomerSegment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CampaignController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $query = Campaign::query();

        if ($request->filled('status'))  $query->where('status', $request->input('status'));
        if ($request->filled('channel')) $query->where('channel', $request->input('channel'));
        if ($request->filled('search')) {
            $term = $request->input('search');
            $query->where('name', 'like', "%{$term}%");
        }

        $campaigns = $query->orderBy('created_at', 'desc')->paginate($request->input('per_page', 20));

        return $this->paginatedResponse($campaigns);
    }

    public function show(int $id): JsonResponse
    {
        $campaign = Campaign::findOrFail($id);
        return $this->successResponse($this->withStats($campaign));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'                => 'required|string|max:150',
            'description'         => 'nullable|string|max:255',
            'channel'             => 'required|in:sms,email,whatsapp',
            'type'                => 'required|in:marketing,reminder,birthday,manual',
            'message_template_id' => 'nullable|integer|exists:message_templates,id',
            'subject'             => 'nullable|string|max:255',
            'body'                => 'required|string|max:10000',
            'variables'           => 'nullable|array',
            'target_type'         => 'required|in:all_customers,customer_group,customer_segment',
            'target_id'           => 'nullable|integer',
            'scheduled_at'        => 'nullable|date|after:now',
        ]);

        $validated['created_by'] = auth()->id();
        $validated['status']     = $validated['scheduled_at'] ? 'scheduled' : 'draft';

        $campaign = Campaign::create($validated);

        return $this->successResponse($campaign, 'Campaign created.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $campaign = Campaign::findOrFail($id);

        if (! in_array($campaign->status, ['draft', 'scheduled'])) {
            return $this->errorResponse('Only draft or scheduled campaigns can be edited.', 422);
        }

        $validated = $request->validate([
            'name'                => 'sometimes|string|max:150',
            'description'         => 'nullable|string|max:255',
            'channel'             => 'sometimes|in:sms,email,whatsapp',
            'type'                => 'sometimes|in:marketing,reminder,birthday,manual',
            'message_template_id' => 'nullable|integer|exists:message_templates,id',
            'subject'             => 'nullable|string|max:255',
            'body'                => 'sometimes|string|max:10000',
            'variables'           => 'nullable|array',
            'target_type'         => 'sometimes|in:all_customers,customer_group,customer_segment',
            'target_id'           => 'nullable|integer',
            'scheduled_at'        => 'nullable|date|after:now',
        ]);

        if (array_key_exists('scheduled_at', $validated)) {
            $validated['status'] = $validated['scheduled_at'] ? 'scheduled' : 'draft';
        }

        $campaign->update($validated);

        return $this->successResponse($campaign->fresh(), 'Campaign updated.');
    }

    public function destroy(int $id): JsonResponse
    {
        $campaign = Campaign::findOrFail($id);

        if (! in_array($campaign->status, ['draft', 'scheduled', 'cancelled', 'failed'])) {
            return $this->errorResponse('Cannot delete a campaign that has been sent or is currently sending.', 422);
        }

        $campaign->recipients()->delete();
        $campaign->delete();

        return $this->successResponse(null, 'Campaign deleted.');
    }

    public function launch(Request $request, int $id): JsonResponse
    {
        $campaign = Campaign::findOrFail($id);

        if (! $campaign->isSendable()) {
            return $this->errorResponse('Campaign cannot be launched. Current status: '.$campaign->status, 422);
        }

        if (empty(trim($campaign->body))) {
            return $this->errorResponse('Campaign body is empty.', 422);
        }

        $tenantId = app('current_store')?->id;
        if (! $tenantId) {
            return $this->errorResponse('Store context not found.', 500);
        }

        $campaign->update(['status' => 'scheduled']);  // job will update to 'sending'
        DispatchCampaignJob::dispatch($campaign->id, $tenantId);

        return $this->successResponse($campaign->fresh(), 'Campaign dispatch queued.');
    }

    public function cancel(int $id): JsonResponse
    {
        $campaign = Campaign::findOrFail($id);

        if (! $campaign->isCancellable()) {
            return $this->errorResponse('Campaign cannot be cancelled at this stage.', 422);
        }

        $campaign->update(['status' => 'cancelled']);

        return $this->successResponse($campaign, 'Campaign cancelled.');
    }

    /**
     * Return per-campaign delivery statistics from communication_logs.
     */
    public function stats(int $id): JsonResponse
    {
        $campaign = Campaign::findOrFail($id);

        $breakdown = CommunicationLog::where('campaign_id', $campaign->id)
            ->select('status', \Illuminate\Support\Facades\DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $costTotal = (float) CommunicationLog::where('campaign_id', $campaign->id)
            ->where('status', 'sent')
            ->sum('cost');

        return $this->successResponse([
            'campaign'  => $this->withStats($campaign),
            'breakdown' => $breakdown,
            'cost'      => round($costTotal, 4),
        ]);
    }

    /**
     * Estimate recipient count for a given targeting configuration (before creating/launching).
     */
    public function estimateAudience(Request $request): JsonResponse
    {
        $request->validate([
            'channel'     => 'required|in:sms,email,whatsapp',
            'type'        => 'required|in:marketing,reminder,birthday,manual',
            'target_type' => 'required|in:all_customers,customer_group,customer_segment',
            'target_id'   => 'nullable|integer',
        ]);

        $channel    = $request->input('channel');
        $type       = $request->input('type');
        $targetType = $request->input('target_type');
        $targetId   = $request->input('target_id');

        $query = Customer::where('is_active', true)->whereNull('deleted_at');

        if ($type === 'marketing') {
            $consentField = match ($channel) {
                'sms'      => 'sms_marketing_opted_in',
                'email'    => 'email_marketing_opted_in',
                'whatsapp' => 'whatsapp_marketing_opted_in',
            };
            $query->where($consentField, true);
        }

        if (in_array($channel, ['sms', 'whatsapp'])) {
            $query->whereNotNull('phone')->where('phone', '!=', '');
        } else {
            $query->whereNotNull('email')->where('email', '!=', '');
        }

        if ($targetType === 'customer_group' && $targetId) {
            $query->where('customer_group_id', $targetId);
        } elseif ($targetType === 'customer_segment' && $targetId) {
            $segment = CustomerSegment::find($targetId);
            $segment?->applyRules($query);
        }

        return $this->successResponse(['estimated_recipients' => $query->count()]);
    }

    private function withStats(Campaign $campaign): array
    {
        return array_merge($campaign->toArray(), [
            'delivery_rate' => $campaign->deliveryRate(),
        ]);
    }
}
