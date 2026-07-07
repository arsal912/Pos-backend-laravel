<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\WeighingScaleSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WeighingScaleSettingController extends Controller
{
    use ApiResponse;

    /**
     * Get the store's weighing-scale settings.
     */
    public function show(): JsonResponse
    {
        return $this->successResponse(['setting' => WeighingScaleSetting::current()]);
    }

    /**
     * Update the store's weighing-scale settings.
     *
     * connection_mode only accepts "manual" today — there is no hardware/
     * serial integration. Any other value (e.g. "serial") is rejected with
     * a clear validation error rather than silently accepted or ignored.
     */
    public function update(Request $request): JsonResponse
    {
        if (! $request->user()->can('manage-settings')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'default_weight_unit' => 'sometimes|in:g,kg',
            'connection_mode'      => [
                'sometimes',
                'in:manual',
            ],
        ], [
            'connection_mode.in' => 'Only manual weight entry is supported right now. Serial/USB scale support is coming soon.',
        ]);

        $setting = WeighingScaleSetting::current();
        $setting->update($validated);

        return $this->successResponse(['setting' => $setting->fresh()], 'Weighing scale settings updated.');
    }
}
