<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\PaymentGateway;
use App\Services\PaymentGateways\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

        // Accept boolean in any shape JSON, form, or proxy may send
        if ($request->has('is_active')) {
            $gateway->is_active = filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN);
        }

        if ($request->has('is_test_mode')) {
            $gateway->is_test_mode = filter_var($request->input('is_test_mode'), FILTER_VALIDATE_BOOLEAN);
        }

        if ($request->has('credentials')) {
            $creds = $request->input('credentials');
            if (is_array($creds)) {
                $gateway->credentials = $creds;
            }
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
