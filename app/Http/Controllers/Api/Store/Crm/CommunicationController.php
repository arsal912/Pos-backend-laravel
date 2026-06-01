<?php

namespace App\Http\Controllers\Api\Store\Crm;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\CommunicationLog;
use App\Models\Customer;
use App\Models\MessageTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommunicationController extends Controller
{
    use ApiResponse;

    /** GET /customers/{id}/communications — history for one customer */
    public function customerHistory(Request $request, int $customerId): JsonResponse
    {
        if (! $request->user()->can('view-customers')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        Customer::findOrFail($customerId);

        return $this->paginatedResponse(
            CommunicationLog::where('customer_id', $customerId)
                ->latest()
                ->paginate($request->input('per_page', 20))
        );
    }

    /** POST /customers/{id}/send-message — log a message (Phase 5 will send for real) */
    public function sendMessage(Request $request, int $customerId): JsonResponse
    {
        if (! $request->user()->can('send-customer-communication')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'channel'     => 'required|in:sms,email,whatsapp',
            'body'        => 'required|string',
            'subject'     => 'nullable|string|max:255',  // email only
        ]);

        $customer = Customer::findOrFail($customerId);

        // Check opt-in
        $optInKey = $validated['channel'] . '_marketing_opted_in';
        if ($customer->$optInKey === false) {
            return $this->errorResponse(
                "Customer has opted out of {$validated['channel']} communications.",
                422
            );
        }

        $recipient = match ($validated['channel']) {
            'email'    => $customer->email ?? '',
            'sms', 'whatsapp' => $customer->phone ?? '',
        };

        if (! $recipient) {
            return $this->errorResponse("Customer has no {$validated['channel']} address on file.", 422);
        }

        // Resolve merge tags
        $body = $this->resolveMergeTags($validated['body'], $customer);

        // For WhatsApp, generate wa.me link
        $providerResponse = null;
        $status           = 'skipped';
        $provider         = 'logged_only';

        if ($validated['channel'] === 'whatsapp' && $customer->phone) {
            $waPhone = preg_replace('/\D/', '', $customer->phone);
            $waLink  = "https://wa.me/{$waPhone}?text=" . urlencode($body);
            $providerResponse = ['whatsapp_link' => $waLink];
            $status   = 'sent';
            $provider = 'whatsapp_link';
        }

        $log = CommunicationLog::create([
            'customer_id'       => $customerId,
            'recipient'         => $recipient,
            'channel'           => $validated['channel'],
            'type'              => 'manual',
            'subject'           => $validated['subject'] ?? null,
            'body'              => $body,
            'status'            => $status,
            'provider'          => $provider,
            'provider_response' => $providerResponse,
            'sent_at'           => $status === 'sent' ? now() : null,
            'sent_by'           => auth()->id(),
        ]);

        $message = $validated['channel'] === 'whatsapp'
            ? 'WhatsApp link generated. Click to send via WhatsApp Web.'
            : 'Message logged. SMS/Email delivery will be enabled in Phase 5.';

        return $this->successResponse([
            'log'             => $log,
            'whatsapp_link'   => $providerResponse['whatsapp_link'] ?? null,
        ], $message, 201);
    }

    /** GET /communications — global log */
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->can('send-customer-communication')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $query = CommunicationLog::with('customer:id,name,code,phone');

        if ($request->filled('channel'))     $query->where('channel', $request->input('channel'));
        if ($request->filled('type'))        $query->where('type', $request->input('type'));
        if ($request->filled('status'))      $query->where('status', $request->input('status'));
        if ($request->filled('customer_id')) $query->where('customer_id', $request->input('customer_id'));
        if ($request->filled('date_from'))   $query->where('created_at', '>=', $request->input('date_from'));
        if ($request->filled('date_to'))     $query->where('created_at', '<=', $request->input('date_to') . ' 23:59:59');

        return $this->paginatedResponse($query->latest()->paginate($request->input('per_page', 25)));
    }

    /** GET /message-templates */
    public function templates(): JsonResponse
    {
        return $this->successResponse(['templates' => MessageTemplate::active()->orderBy('name')->get()]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolveMergeTags(string $body, Customer $customer): string
    {
        $store = app('current_store');

        return str_replace(
            ['{{name}}', '{{loyalty_points}}', '{{outstanding_credit}}', '{{store_name}}',
             '{{phone}}', '{{email}}', '{{code}}'],
            [
                $customer->name,
                number_format((float) $customer->loyalty_points_balance, 2),
                number_format((float) $customer->outstanding_balance, 2),
                $store?->name ?? config('app.name'),
                $customer->phone ?? '',
                $customer->email ?? '',
                $customer->code,
            ],
            $body
        );
    }
}
