<?php

namespace App\Http\Controllers\Api\Store\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Http\Traits\LocationScope;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    use ApiResponse, LocationScope;

    /**
     * Current stock state across all products.
     * GET /store/inventory
     */
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->can('view-inventory')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $query = InventoryItem::with([
            'product:id,name,sku,cost_price,low_stock_threshold,allow_negative_stock',
            'variant:id,name,sku',
            'warehouse:id,name,code',
        ]);

        // Branch/warehouse managers are automatically scoped to their location;
        // explicit filters are still honoured but only within their scope.
        $this->applyInventoryScope($query, $request);

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->input('branch_id'));
        }

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->input('warehouse_id'));
        }

        if ($request->boolean('low_stock')) {
            $query->whereHas('product', fn ($q) =>
                $q->whereNotNull('low_stock_threshold')
            )->whereRaw('inventory_items.quantity <= (SELECT low_stock_threshold FROM products WHERE products.id = inventory_items.product_id)');
        }

        if ($request->filled('category_id')) {
            $query->whereHas('product', fn ($q) => $q->where('category_id', $request->input('category_id')));
        }

        if ($request->input('status') === 'out') {
            $query->where('quantity', '<=', 0);
        }

        $items = $query->paginate($request->input('per_page', 20));

        // Append computed stock_value and available
        $items->getCollection()->transform(function (InventoryItem $item) {
            $item->stock_value = (float) $item->quantity * (float) ($item->product?->cost_price ?? 0);
            $item->available   = max(0, (float) $item->quantity - (float) $item->reserved_quantity);
            $item->stock_status = $this->stockStatus($item);
            return $item;
        });

        return $this->paginatedResponse($items);
    }

    /**
     * Stock for a single product across all branches.
     * GET /store/inventory/products/{id}
     */
    public function productStock(Request $request, int $productId): JsonResponse
    {
        if (! $request->user()->can('view-inventory')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $items = InventoryItem::with(['variant:id,name,sku', 'warehouse:id,name,code'])
            ->where('product_id', $productId)
            ->get()
            ->map(function (InventoryItem $item) {
                $item->available    = max(0, (float) $item->quantity - (float) $item->reserved_quantity);
                $item->stock_status = $this->stockStatus($item);
                $item->location_label = $item->warehouse
                    ? "WH: {$item->warehouse->name}"
                    : "Branch #{$item->branch_id}";
                return $item;
            });

        $product = \App\Models\Product::with('category:id,name', 'unit:id,name,short_code')
            ->find($productId);

        return $this->successResponse([
            'product'          => $product,
            'stock_by_location' => $items,
            'stock_by_branch'   => $items, // backward compat alias
            'total_quantity'    => $items->sum('quantity'),
        ]);
    }

    /**
     * Full stock movement audit log.
     * GET /store/inventory/movements
     */
    public function movements(Request $request): JsonResponse
    {
        if (! $request->user()->can('view-inventory')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $query = StockMovement::with([
            'product:id,name,sku',
            'variant:id,name,sku',
        ]);

        if ($request->filled('product_id'))   $query->where('product_id', $request->input('product_id'));
        if ($request->filled('branch_id'))    $query->where('branch_id', $request->input('branch_id'));
        if ($request->filled('warehouse_id')) $query->where('warehouse_id', $request->input('warehouse_id'));
        if ($request->filled('type'))         $query->where('type', $request->input('type'));
        if ($request->filled('date_from'))    $query->where('created_at', '>=', $request->input('date_from'));
        if ($request->filled('date_to'))      $query->where('created_at', '<=', $request->input('date_to') . ' 23:59:59');

        $movements = $query->latest()->paginate($request->input('per_page', 30));

        return $this->paginatedResponse($movements);
    }

    private function stockStatus(InventoryItem $item): string
    {
        $qty = (float) $item->quantity;
        $threshold = (int) ($item->product?->low_stock_threshold ?? 0);

        if ($qty <= 0) return 'out';
        if ($threshold > 0 && $qty <= $threshold) return 'low';
        return 'in_stock';
    }
}
