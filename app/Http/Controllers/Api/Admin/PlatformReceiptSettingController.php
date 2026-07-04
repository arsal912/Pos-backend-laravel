<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\PlatformReceiptSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformReceiptSettingController extends Controller
{
    use ApiResponse;

    /**
     * Get the platform-wide receipt footer (super admin only).
     */
    public function show(): JsonResponse
    {
        return $this->successResponse(['setting' => PlatformReceiptSetting::current()]);
    }

    /**
     * Update the platform-wide receipt footer. Applied to every store's
     * receipts — there is no store-level equivalent route for this.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'is_enabled'  => 'sometimes|boolean',
            'footer_text' => 'nullable|string|max:2000',
        ]);

        $setting = PlatformReceiptSetting::current();
        $setting->update($validated);

        return $this->successResponse(['setting' => $setting->fresh()], 'Platform receipt footer updated.');
    }
}
