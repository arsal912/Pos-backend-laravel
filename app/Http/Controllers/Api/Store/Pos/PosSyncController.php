<?php

namespace App\Http\Controllers\Api\Store\Pos;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\TaxRate;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Server → Client sync endpoints.
 * These endpoints push data to the device's IndexedDB cache.
 * Designed to be called: 1) full sync on first load, 2) incremental on reconnect.
 */
class PosSyncController extends Controller
{
    use ApiResponse;

    // ── Products ─────────────────────────────────────────────────────────────

    public function products(Request $request): JsonResponse
    {
        $limit = min((int) $request->input('limit', 1000), 2000);
        $since = $request->input('since');

        $query = Product::with([
                'taxRate:id,name,rate,is_inclusive',
                'variants' => fn ($q) => $q->where('is_active', true)
                                           ->select('id', 'product_id', 'name', 'sku', 'barcode', 'selling_price', 'sort_order')
                                           ->orderBy('sort_order'),
            ])
            ->where('is_active', true)
            ->orderBy('name')
            ->limit($limit);

        if ($since) {
            $query->where('updated_at', '>', $since);
        }

        $products = $query->get([
            'id', 'sku', 'barcode', 'name', 'category_id', 'brand_id',
            'selling_price', 'cost_price', 'tax_rate_id', 'unit_id',
            'track_stock', 'allow_negative_stock', 'low_stock_threshold',
            'image', 'updated_at',
        ]);

        // Batch-load stock in 2 queries (product-level + variant-level)
        $productIds = $products->pluck('id');
        $stockMap   = $this->batchProductStock($productIds);
        $variantIds = $products->flatMap(fn ($p) => $p->variants->pluck('id'));
        $variantStockMap = $this->batchVariantStock($variantIds);

        $result = $products->map(function ($p) use ($stockMap, $variantStockMap) {
            $variants = $p->variants->map(fn ($v) => [
                'id'            => $v->id,
                'product_id'    => $v->product_id,
                'name'          => $v->name,
                'sku'           => $v->sku,
                'barcode'       => $v->barcode,
                'price'         => (float) ($v->selling_price ?? $p->selling_price),
                'current_stock' => max(0, (float) ($variantStockMap[$v->id] ?? 0)),
            ])->values();

            return [
                'id'                   => $p->id,
                'sku'                  => $p->sku,
                'barcode'              => $p->barcode,
                'name'                 => $p->name,
                'name_lower'           => mb_strtolower($p->name),
                'category_id'          => $p->category_id,
                'brand_id'             => $p->brand_id,
                'price'                => (float) $p->selling_price,
                'cost'                 => (float) ($p->cost_price ?? 0),
                'tax_rate_id'          => $p->tax_rate_id,
                'tax_rate'             => (float) ($p->taxRate?->rate ?? 0),
                'tax_inclusive'        => (bool) ($p->taxRate?->is_inclusive ?? false),
                'unit_id'              => $p->unit_id,
                'tracks_stock'         => (bool) $p->track_stock,
                'allow_negative_stock' => (bool) $p->allow_negative_stock,
                'low_stock_threshold'  => (int) ($p->low_stock_threshold ?? 0),
                'current_stock'        => max(0, (float) ($stockMap[$p->id] ?? 0)),
                'image_url'            => $p->image ? url($p->image) : null,
                'variants'             => $variants,
                'updated_at'           => $p->updated_at?->toISOString(),
            ];
        });

        return $this->successResponse([
            'products'  => $result,
            'synced_at' => now()->toISOString(),
            'count'     => $result->count(),
        ]);
    }

    // ── Customers ─────────────────────────────────────────────────────────────

    public function customers(Request $request): JsonResponse
    {
        $limit = min((int) $request->input('limit', 500), 2000);
        $since = $request->input('since');

        $query = Customer::where('is_active', true)
            ->orderBy('last_purchase_at', 'desc')
            ->orderBy('name')
            ->limit($limit);

        if ($since) {
            $query->where('updated_at', '>', $since);
        }

        $customers = $query->get([
            'id', 'code', 'name', 'phone', 'email', 'customer_group_id',
            'loyalty_points_balance', 'outstanding_balance', 'credit_limit',
            'updated_at',
        ]);

        $result = $customers->map(fn ($c) => [
            'id'                     => $c->id,
            'code'                   => $c->code,
            'name'                   => $c->name,
            'name_lower'             => mb_strtolower($c->name),
            'phone'                  => $c->phone,
            'email'                  => $c->email,
            'customer_group_id'      => $c->customer_group_id,
            'loyalty_points_balance' => (float) $c->loyalty_points_balance,
            'outstanding_balance'    => (float) $c->outstanding_balance,
            'credit_limit'           => $c->credit_limit !== null ? (float) $c->credit_limit : null,
            // allow_credit_at_pos = has a positive credit limit
            'allow_credit_at_pos'    => $c->credit_limit !== null && (float) $c->credit_limit > 0,
            'updated_at'             => $c->updated_at?->toISOString(),
        ]);

        return $this->successResponse([
            'customers' => $result,
            'synced_at' => now()->toISOString(),
            'count'     => $result->count(),
        ]);
    }

    // ── Reference data (categories, units, tax rates, etc.) ─────────────────

    public function reference(): JsonResponse
    {
        $store = Store::find(app('current_store_id'));

        $categories = Category::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'parent_id', 'name', 'sort_order']);

        $brands = Brand::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        $units = Unit::orderBy('name')
            ->get(['id', 'name', 'short_code', 'is_decimal']);

        $taxRates = TaxRate::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'rate', 'is_inclusive']);

        $customerGroups = CustomerGroup::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'default_discount_percent', 'earns_loyalty_points']);

        // Store meta: currency, timezone, core settings
        $storeMeta = [
            'store_id'           => $store?->id,
            'store_name'         => $store?->name,
            'currency'           => $store?->currency ?? 'USD',
            'timezone'           => $store?->timezone ?? 'UTC',
            'address'            => $store?->address,
        ];

        return $this->successResponse([
            'categories'      => $categories,
            'brands'          => $brands,
            'units'           => $units,
            'tax_rates'       => $taxRates,
            'customer_groups' => $customerGroups,
            'store_meta'      => $storeMeta,
            'synced_at'       => now()->toISOString(),
        ]);
    }

    // ── Manifest (lightweight check for incremental sync decision) ───────────

    public function manifest(): JsonResponse
    {
        $lastProduct  = Product::where('is_active', true)->max('updated_at');
        $lastCustomer = Customer::where('is_active', true)->max('updated_at');

        return $this->successResponse([
            'last_product_update_at'  => $lastProduct,
            'last_customer_update_at' => $lastCustomer,
            'product_count'           => Product::where('is_active', true)->count(),
            'customer_count'          => Customer::where('is_active', true)->count(),
            'server_time'             => now()->toISOString(),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Batch-load stock for product IDs (no variant). */
    private function batchProductStock($productIds): array
    {
        if ($productIds->isEmpty()) return [];

        return InventoryItem::whereIn('product_id', $productIds)
            ->whereNull('variant_id')
            ->groupBy('product_id')
            ->select('product_id', DB::raw('SUM(COALESCE(quantity,0) - COALESCE(reserved_quantity,0)) as stock'))
            ->pluck('stock', 'product_id')
            ->toArray();
    }

    /** Batch-load stock for variant IDs. */
    private function batchVariantStock($variantIds): array
    {
        if ($variantIds->isEmpty()) return [];

        return InventoryItem::whereIn('variant_id', $variantIds)
            ->groupBy('variant_id')
            ->select('variant_id', DB::raw('SUM(COALESCE(quantity,0) - COALESCE(reserved_quantity,0)) as stock'))
            ->pluck('stock', 'variant_id')
            ->toArray();
    }
}
