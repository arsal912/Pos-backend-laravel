<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\StoreSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

    /**
     * GET /store/profile — return current store details (name, currency, timezone, etc.)
     */
    public function getProfile(Request $request): JsonResponse
    {
        $store = $request->user()->store;
        if (! $store) return $this->errorResponse('Store not found.', 404);

        return $this->successResponse(['store' => $store->only([
            'id', 'name', 'email', 'phone', 'address', 'city', 'country',
            'currency', 'timezone', 'business_type', 'whatsapp_number', 'logo',
        ])]);
    }

    /**
     * PUT /store/profile — update store profile including currency and timezone.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        if (! $request->user()->can('manage-settings')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $store = $request->user()->store;
        if (! $store) return $this->errorResponse('Store not found.', 404);

        $validated = $request->validate([
            'name'          => 'sometimes|string|max:150',
            'phone'         => 'nullable|string|max:30',
            'address'       => 'nullable|string|max:500',
            'city'          => 'nullable|string|max:100',
            'country'       => 'nullable|string|max:100',
            'currency'      => 'sometimes|string|size:3|in:PKR,USD,EUR,GBP,AED,SAR,INR,CAD,AUD,BDT,LKR,NPR,MYR,SGD,THB',
            'timezone'      => 'sometimes|string|max:60',
            'business_type' => 'nullable|string|max:100',
        ]);

        $store->fill($validated)->save();

        return $this->successResponse(['store' => $store->fresh()->only([
            'id', 'name', 'email', 'phone', 'address', 'city', 'country',
            'currency', 'timezone', 'business_type', 'whatsapp_number', 'logo',
        ])], 'Store profile updated.');
    }

    /**
     * PUT /store/settings/whatsapp — store owner sets their WhatsApp Business number.
     * This is the number customers will message to request reports.
     */
    public function updateWhatsapp(Request $request): JsonResponse
    {
        if (! $request->user()->can('manage-settings')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'whatsapp_number' => 'nullable|string|max:20|regex:/^\+?[0-9\s\-]+$/',
        ]);

        $store = $request->user()->store;
        if (! $store) {
            return $this->errorResponse('Store not found.', 404);
        }

        $store->whatsapp_number = $validated['whatsapp_number']
            ? preg_replace('/\s+/', '', $validated['whatsapp_number'])
            : null;
        $store->save();

        return $this->successResponse([
            'whatsapp_number' => $store->whatsapp_number,
        ], 'WhatsApp number updated.');
    }

    /**
     * POST /store/settings/logo — store owner uploads/replaces their store logo.
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        if (! $request->user()->can('manage-settings')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $request->validate([
            'logo' => 'required|file|mimes:jpg,jpeg,png,webp,gif|max:2048',
        ]);

        $store = $request->user()->store;
        if (! $store) {
            return $this->errorResponse('Store not found.', 404);
        }

        // Delete previous logo
        if ($store->logo && Storage::disk('local')->exists($store->logo)) {
            Storage::disk('local')->delete($store->logo);
        }

        $ext  = $request->file('logo')->getClientOriginalExtension();
        $path = "logos/{$store->id}/" . Str::uuid() . '.' . $ext;
        Storage::disk('local')->put($path, file_get_contents($request->file('logo')->getRealPath()));

        // logo excluded from $fillable — assign directly
        $store->logo = $path;
        $store->save();

        return $this->successResponse([
            'logo'     => $path,
            'logo_url' => url("/api/v1/store/files/{$path}"),
        ], 'Store logo updated.');
    }
}
