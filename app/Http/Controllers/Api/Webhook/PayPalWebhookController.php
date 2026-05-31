<?php

namespace App\Http\Controllers\Api\Webhook;

use App\Http\Controllers\Controller;
use App\Services\PaymentGateways\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayPalWebhookController extends Controller
{
    public function handle(Request $request, PaymentGatewayManager $manager): JsonResponse
    {
        try {
            $paypal = $manager->make('paypal');
            $paypal->handleWebhook($request);
        } catch (\RuntimeException $e) {
            // Signature failure or config issue — return 400, not 500
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'PayPal webhook processing failed.',
            ], 400);
        }

        return response()->json(['success' => true]);
    }
}
