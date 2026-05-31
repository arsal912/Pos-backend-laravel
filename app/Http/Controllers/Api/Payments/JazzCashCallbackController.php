<?php

namespace App\Http\Controllers\Api\Payments;

use App\Http\Controllers\Controller;
use App\Services\PaymentGateways\JazzCashService;
use App\Services\PaymentGateways\PaymentGatewayManager;
use Illuminate\Http\Request;

class JazzCashCallbackController extends Controller
{
    /**
     * POST /api/v1/payments/jazzcash/callback
     *
     * Server-to-server notification (if JazzCash sends one).
     * Signature is the authentication — no Bearer token.
     */
    public function callback(Request $request, PaymentGatewayManager $manager): \Illuminate\Http\JsonResponse
    {
        try {
            /** @var JazzCashService $service */
            $service = $manager->make('jazzcash');
            $service->handleCallback($request);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Callback processing failed.'], 400);
        }

        return response()->json(['success' => true]);
    }

    /**
     * POST|GET /api/v1/payments/jazzcash/return
     *
     * JazzCash redirects the customer's browser here after payment.
     * We process the result then redirect to the frontend.
     */
    public function return(Request $request, PaymentGatewayManager $manager): \Illuminate\Http\RedirectResponse|\Illuminate\Http\Response
    {
        $frontendUrl = rtrim(env('FRONTEND_URL', config('app.url')), '/');

        // GET with no data = direct browser navigation — send to billing page
        if ($request->isMethod('GET') && $request->all() === []) {
            return redirect($frontendUrl . '/dashboard/billing');
        }

        try {
            /** @var JazzCashService $service */
            $service = $manager->make('jazzcash');
            $payment = $service->handleCallback($request);

            if ($payment->status === 'completed') {
                return redirect($frontendUrl . '/billing/success?gateway=jazzcash&session_id=' . urlencode($payment->gateway_payment_id));
            }

            return redirect($frontendUrl . '/billing/cancel?gateway=jazzcash&reason=' . urlencode($payment->failure_reason ?? 'Payment failed'));
        } catch (\RuntimeException $e) {
            // Hash mismatch or missing config — do NOT expose details
            \Illuminate\Support\Facades\Log::warning('JazzCash return verification failed', ['error' => $e->getMessage()]);
            return redirect($frontendUrl . '/billing/cancel?gateway=jazzcash&reason=verification_failed');
        } catch (\Throwable $e) {
            return redirect($frontendUrl . '/billing/cancel?gateway=jazzcash');
        }
    }
}
