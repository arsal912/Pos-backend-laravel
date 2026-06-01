<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\StoreSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreSettingsController extends Controller
{
    use ApiResponse;

    // Default values for every POS setting
    private const DEFAULTS = [
        'pos.tax_inclusive'           => '0',
        'pos.low_stock_threshold'     => '5',
        'pos.allow_negative_stock'    => '0',
        'pos.round_decimals'          => '2',
        'pos.currency_position'       => 'before',
        'pos.cash_rounding'           => '0',  // 0 = off, 1 = nearest 1, 5 = nearest 5
        'pos.default_branch_id'       => '1',
        'receipt.thermal_template_id' => '',
        'receipt.a4_template_id'      => '',
    ];

    public function index(): JsonResponse
    {
        $stored   = StoreSetting::allAsArray();
        $settings = [];

        foreach (self::DEFAULTS as $key => $default) {
            $settings[$key] = $stored[$key] ?? $default;
        }

        return $this->successResponse(['settings' => $settings]);
    }

    public function update(Request $request): JsonResponse
    {
        if (! $request->user()->can('manage-settings')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $data = $request->validate([
            'settings' => 'required|array',
        ]);

        foreach ($data['settings'] as $key => $value) {
            // Only allow known keys
            if (array_key_exists($key, self::DEFAULTS)) {
                StoreSetting::set($key, $value ?? '');
            }
        }

        return $this->successResponse(['settings' => StoreSetting::allAsArray()], 'Settings saved.');
    }
}
