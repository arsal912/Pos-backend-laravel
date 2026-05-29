<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\PaymentGateway;
use App\Services\PaymentGateways\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentGatewayController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $gateways = PaymentGateway::orderBy('sort_order')->get();

        return $this->successResponse(['payment_gateways' => $gateways]);
    }

    public function update(Request $request, string $slug): JsonResponse
    {
        $gateway = PaymentGateway::where('slug', $slug)->firstOrFail();

        $validated = $request->validate([
            'is_active' => 'sometimes|boolean',
            'is_test_mode' => 'sometimes|boolean',
            'credentials' => 'sometimes|array',
        ]);

        if (array_key_exists('is_active', $validated)) {
            $gateway->is_active = $validated['is_active'];
        }

        if (array_key_exists('is_test_mode', $validated)) {
            $gateway->is_test_mode = $validated['is_test_mode'];
        }

        if (array_key_exists('credentials', $validated)) {
            $gateway->credentials = $validated['credentials'];
        }

        $gateway->save();

        return $this->successResponse(['payment_gateway' => $gateway], 'Payment gateway updated');
    }

    public function test(string $slug, PaymentGatewayManager $manager): JsonResponse
    {
        $gateway = PaymentGateway::where('slug', $slug)->firstOrFail();
        $service = $manager->make($slug);

        try {
            $service->testConnection();
        } catch (\Throwable $e) {
            return $this->errorResponse('Gateway connection failed: ' . $e->getMessage(), 400);
        }

        return $this->successResponse(['message' => 'Connection successful']);
    }
}
