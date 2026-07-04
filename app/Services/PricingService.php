<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductGroupPrice;
use App\Models\ProductVariant;

/**
 * Resolves the correct selling price for a product given an optional customer.
 * Respects customer group pricing and group-level discount percentages.
 */
class PricingService
{
    /**
     * Get the effective price for a product (or variant) for a given customer.
     *
     * Resolution order:
     *  1. If customer has a group → check product_group_prices for (product, variant, group)
     *  2. If no group price, use product.selling_price (or variant.selling_price)
     *  3. Apply group.default_discount_percent on top if no specific group price was found
     */
    public function getPrice(
        Product $product,
        ?ProductVariant $variant = null,
        ?Customer $customer = null
    ): float {
        $base = $variant
            ? (float) $variant->selling_price
            : (float) $product->selling_price;

        if (! $customer || ! $customer->customer_group_id) {
            return $base;
        }

        // Check product_group_prices table first
        $groupPrice = ProductGroupPrice::where('product_id', $product->id)
            ->where('variant_id', $variant?->id)
            ->where('customer_group_id', $customer->customer_group_id)
            ->value('price');

        if ($groupPrice !== null) {
            return round((float) $groupPrice, 2);
        }

        // Fallback: apply group's default discount
        $group = $customer->group ?? $customer->load('group')->group;

        if ($group && $group->default_discount_percent) {
            $base = $base * (1 - (float) $group->default_discount_percent / 100);
        }

        return round($base, 2);
    }

    /**
     * Get all group prices for a product keyed by [variant_id][group_id].
     */
    public function getGroupPricesForProduct(int $productId): array
    {
        return ProductGroupPrice::where('product_id', $productId)
            ->with('group:id,name,color,slug')
            ->get()
            ->groupBy('variant_id')
            ->map(fn ($rows) => $rows->keyBy('customer_group_id'))
            ->toArray();
    }

    /**
     * Upsert group prices for a product from an array input.
     * $items = [['group_id' => 1, 'variant_id' => null, 'price' => 500], ...]
     */
    public function upsertGroupPrices(int $productId, array $items): void
    {
        foreach ($items as $item) {
            ProductGroupPrice::updateOrCreate(
                [
                    'product_id'        => $productId,
                    'variant_id'        => $item['variant_id'] ?? null,
                    'customer_group_id' => $item['group_id'],
                ],
                ['price' => $item['price']]
            );
        }
    }
}
