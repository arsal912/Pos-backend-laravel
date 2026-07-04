<?php

namespace App\Http\Controllers\Api\Store\Crm;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Product;
use App\Models\ProductGroupPrice;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GroupPricingController extends Controller
{
    use ApiResponse;

    public function __construct(private PricingService $pricing) {}

    public function index(int $productId): JsonResponse
    {
        Product::findOrFail($productId);
        $prices = ProductGroupPrice::where('product_id', $productId)
            ->with(['group:id,name,color,slug', 'variant:id,name,sku'])
            ->get();

        return $this->successResponse(['group_prices' => $prices]);
    }

    public function upsert(Request $request, int $productId): JsonResponse
    {
        if (! $request->user()->can('manage-customer-groups')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'prices'                   => 'required|array|min:1',
            'prices.*.group_id'        => 'required|exists:customer_groups,id',
            'prices.*.variant_id'      => 'nullable|exists:product_variants,id',
            'prices.*.price'           => 'required|numeric|min:0',
        ]);

        Product::findOrFail($productId);
        $this->pricing->upsertGroupPrices($productId, $validated['prices']);

        return $this->successResponse([
            'group_prices' => ProductGroupPrice::where('product_id', $productId)
                ->with('group:id,name,color')
                ->get(),
        ], 'Group prices updated.');
    }

    public function destroy(Request $request, int $productId, int $groupId): JsonResponse
    {
        if (! $request->user()->can('manage-customer-groups')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        ProductGroupPrice::where('product_id', $productId)
            ->where('customer_group_id', $groupId)
            ->delete();

        return $this->successResponse(null, 'Group price removed.');
    }
}
