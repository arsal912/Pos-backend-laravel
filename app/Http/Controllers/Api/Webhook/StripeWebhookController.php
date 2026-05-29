<?php

namespace App\Http\Controllers\Api\Webhook;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Services\PaymentGateways\PaymentGatewayManager;
use Illuminate\Http\Request;

class StripeWebhookController extends Controller
{
    use ApiResponse;

    public function handle(Request $request, PaymentGatewayManager $manager)
    {
        try {
            $stripeGateway = $manager->make('stripe');
            $stripeGateway->handleWebhook($request);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Stripe webhook failed: ' . $e->getMessage(),
            ], 400);
        }

        return response()->json(['success' => true]);
    }
}
