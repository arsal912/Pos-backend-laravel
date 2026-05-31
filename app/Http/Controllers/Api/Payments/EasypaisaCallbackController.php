<?php

namespace App\Http\Controllers\Api\Payments;

use App\Http\Controllers\Controller;
use App\Services\PaymentGateways\PaymentGatewayManager;
use Illuminate\Http\Request;

class EasypaisaCallbackController extends Controller
{
    /**
     * POST /api/v1/payments/easypaisa/callback
     *
     * Server notification endpoint (not all Easypaisa setups use this).
     * No Bearer auth — hash signature is the authentication.
     */
    public function callback(Request $request, PaymentGatewayManager $manager): \Illuminate\Http\JsonResponse
    {
        try {
            $service = $manager->make('easypaisa');
            $service->handleCallback($request);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        } catch (\Throwable) {
            return response()->json(['success' => false, 'message' => 'Callback processing failed.'], 400);
        }

        return response()->json(['success' => true]);
    }

    /**
     * POST|GET /api/v1/payments/easypaisa/return
     *
     * Easypaisa redirects the customer's browser here after payment.
     * Process the result, then redirect to the frontend success/cancel page.
     */
    public function return(Request $request, PaymentGatewayManager $manager): \Illuminate\Http\RedirectResponse
    {
        $frontendUrl = rtrim(env('FRONTEND_URL', config('app.url')), '/');

        if ($request->isMethod('GET') && $request->all() === []) {
            return redirect($frontendUrl . '/dashboard/billing');
        }

        try {
            $service = $manager->make('easypaisa');
            $payment = $service->handleCallback($request);

            if ($payment->status === 'completed') {
                return redirect(
                    $frontendUrl . '/billing/success?gateway=easypaisa&session_id=' . urlencode($payment->gateway_payment_id)
                );
            }

            return redirect(
                $frontendUrl . '/billing/cancel?gateway=easypaisa&reason=' . urlencode($payment->failure_reason ?? 'Payment failed')
            );
        } catch (\RuntimeException $e) {
            \Illuminate\Support\Facades\Log::warning('Easypaisa return verification failed', ['error' => $e->getMessage()]);
            return redirect($frontendUrl . '/billing/cancel?gateway=easypaisa&reason=verification_failed');
        } catch (\Throwable) {
            return redirect($frontendUrl . '/billing/cancel?gateway=easypaisa');
        }
    }
}
