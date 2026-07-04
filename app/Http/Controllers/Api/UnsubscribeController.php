<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\CommunicationOptOut;
use App\Models\Store;
use App\Services\Communications\UnsubscribeUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public (no auth) endpoints for the email/SMS unsubscribe flow.
 *
 * GET  /api/v1/unsubscribe           — verify signature, return channel + recipient info for UI
 * POST /api/v1/unsubscribe/confirm   — record the opt-out after user confirms
 */
class UnsubscribeController extends Controller
{
    use ApiResponse;

    /** Validate the signed link and return display info for the frontend page. */
    public function show(Request $request): JsonResponse
    {
        $params = $this->validateParams($request);
        if ($params instanceof JsonResponse) return $params;

        [$channel, $recipient, $storeId] = $params;

        $store = Store::find($storeId);

        return $this->successResponse([
            'channel'           => $channel,
            'recipient'         => $this->maskedRecipient($channel, $recipient),
            'store_name'        => $store?->name ?? 'the store',
            'already_opted_out' => $this->isOptedOut($store, $channel, $recipient),
        ]);
    }

    /** Record the opt-out after the user has confirmed on the frontend page. */
    public function confirm(Request $request): JsonResponse
    {
        $params = $this->validateParams($request);
        if ($params instanceof JsonResponse) return $params;

        [$channel, $recipient, $storeId] = $params;

        $store = Store::find($storeId);
        if (! $store) {
            return $this->errorResponse('Store not found.', 404);
        }

        $store->run(function () use ($channel, $recipient) {
            CommunicationOptOut::firstOrCreate(
                ['channel' => $channel, 'recipient' => strtolower(trim($recipient))],
                ['reason' => 'unsubscribe_link', 'opted_out_at' => now()]
            );
        });

        return $this->successResponse(null, 'You have been unsubscribed successfully.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function validateParams(Request $request): array|JsonResponse
    {
        $channel   = $request->input('channel');
        $recipient = $request->input('r');
        $storeId   = (int) $request->input('store');
        $sig       = $request->input('sig');

        if (! in_array($channel, ['sms', 'email', 'whatsapp'])) {
            return $this->errorResponse('Invalid channel.', 400);
        }

        if (! $recipient || ! $storeId || ! $sig) {
            return $this->errorResponse('Missing required parameters.', 400);
        }

        if (! UnsubscribeUrl::verify($channel, $recipient, $storeId, $sig)) {
            return $this->errorResponse('Invalid or expired unsubscribe link.', 400);
        }

        return [$channel, $recipient, $storeId];
    }

    private function maskedRecipient(string $channel, string $recipient): string
    {
        if ($channel === 'email') {
            [$local, $domain] = explode('@', $recipient) + ['', ''];
            return substr($local, 0, 2).'****@'.$domain;
        }
        return '****'.substr($recipient, -4);
    }

    private function isOptedOut(?Store $store, string $channel, string $recipient): bool
    {
        if (! $store) return false;
        $result = false;
        $store->run(function () use ($channel, $recipient, &$result) {
            $result = CommunicationOptOut::isOptedOut($channel, $recipient);
        });
        return $result;
    }
}
