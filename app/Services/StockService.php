<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StoreInventorySnapshot;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single source of truth for all stock changes in the tenant DB.
 * All methods run inside DB transactions with row-level locks.
 *
 * Location is identified by EITHER branchId OR warehouseId (not both).
 * Passing warehouseId overrides branchId for the location lookup.
 */
class StockService
{
    public function addStock(
        int $productId,
        ?int $variantId,
        ?int $branchId,
        float $qty,
        string $type,
        ?string $referenceType = null,
        ?int $referenceId = null,
        float $costAtTime = 0,
        ?string $notes = null,
        ?int $warehouseId = null
    ): StockMovement {
        return DB::transaction(function () use (
            $productId, $variantId, $branchId, $qty,
            $type, $referenceType, $referenceId, $costAtTime, $notes, $warehouseId
        ) {
            $item = $this->lockedItem($productId, $variantId, $branchId, $warehouseId);
            $item->quantity = (float) $item->quantity + $qty;
            $item->save();

            $movement = StockMovement::create([
                'product_id'     => $productId,
                'variant_id'     => $variantId,
                'branch_id'      => $warehouseId ? null : $branchId,
                'warehouse_id'   => $warehouseId,
                'type'           => $type,
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
                'quantity'       => $qty,
                'cost_at_time'   => $costAtTime,
                'balance_after'  => $item->quantity,
                'notes'          => $notes,
                'created_by'     => auth()->user()?->id,
            ]);

            $this->syncSnapshot($productId, $branchId, $warehouseId, (float) $item->quantity);

            return $movement;
        });
    }

    public function deductStock(
        int $productId,
        ?int $variantId,
        ?int $branchId,
        float $qty,
        string $type,
        ?string $referenceType = null,
        ?int $referenceId = null,
        float $costAtTime = 0,
        ?string $notes = null,
        ?int $warehouseId = null
    ): StockMovement {
        return DB::transaction(function () use (
            $productId, $variantId, $branchId, $qty,
            $type, $referenceType, $referenceId, $costAtTime, $notes, $warehouseId
        ) {
            $item   = $this->lockedItem($productId, $variantId, $branchId, $warehouseId);
            $newQty = (float) $item->quantity - $qty;

            if ($newQty < 0) {
                $product = Product::find($productId);
                if ($product && ! $product->allow_negative_stock) {
                    throw new InsufficientStockException(
                        "Insufficient stock for product ID {$productId}. " .
                        "Available: {$item->quantity}, Requested: {$qty}"
                    );
                }
            }

            $item->quantity = $newQty;
            $item->save();

            $movement = StockMovement::create([
                'product_id'     => $productId,
                'variant_id'     => $variantId,
                'branch_id'      => $warehouseId ? null : $branchId,
                'warehouse_id'   => $warehouseId,
                'type'           => $type,
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
                'quantity'       => -$qty,
                'cost_at_time'   => $costAtTime,
                'balance_after'  => $newQty,
                'notes'          => $notes,
                'created_by'     => auth()->user()?->id,
            ]);

            $this->syncSnapshot($productId, $branchId, $warehouseId, $newQty);

            return $movement;
        });
    }

    public function adjustStock(
        int $productId,
        ?int $variantId,
        ?int $branchId,
        float $newQty,
        string $reason = 'adjustment',
        ?string $notes = null,
        ?int $warehouseId = null
    ): StockMovement {
        return DB::transaction(function () use (
            $productId, $variantId, $branchId, $newQty, $reason, $notes, $warehouseId
        ) {
            $item = $this->lockedItem($productId, $variantId, $branchId, $warehouseId);
            $diff = $newQty - (float) $item->quantity;
            $item->quantity        = $newQty;
            $item->last_counted_at = now();
            $item->save();

            $movement = StockMovement::create([
                'product_id'    => $productId,
                'variant_id'    => $variantId,
                'branch_id'     => $warehouseId ? null : $branchId,
                'warehouse_id'  => $warehouseId,
                'type'          => 'adjustment',
                'quantity'      => $diff,
                'balance_after' => $newQty,
                'notes'         => $notes ?? $reason,
                'created_by'    => auth()->user()?->id,
            ]);

            $this->syncSnapshot($productId, $branchId, $warehouseId, $newQty);

            return $movement;
        });
    }

    public function reserveStock(int $productId, ?int $variantId, ?int $branchId, float $qty, ?int $warehouseId = null): void
    {
        DB::transaction(function () use ($productId, $variantId, $branchId, $qty, $warehouseId) {
            $item      = $this->lockedItem($productId, $variantId, $branchId, $warehouseId);
            $available = (float) $item->quantity - (float) $item->reserved_quantity;
            $product   = Product::find($productId);

            if ($available < $qty && $product && ! $product->allow_negative_stock) {
                throw new InsufficientStockException(
                    "Cannot reserve {$qty} of product {$productId}. Available: {$available}"
                );
            }

            $item->reserved_quantity = (float) $item->reserved_quantity + $qty;
            $item->save();
        });
    }

    public function releaseReservation(int $productId, ?int $variantId, ?int $branchId, float $qty, ?int $warehouseId = null): void
    {
        DB::transaction(function () use ($productId, $variantId, $branchId, $qty, $warehouseId) {
            $item = $this->lockedItem($productId, $variantId, $branchId, $warehouseId);
            $item->reserved_quantity = max(0, (float) $item->reserved_quantity - $qty);
            $item->save();
        });
    }

    // ── Internal ─────────────────────────────────────────────────────────────

    private function lockedItem(int $productId, ?int $variantId, ?int $branchId, ?int $warehouseId = null): InventoryItem
    {
        $attrs = [
            'product_id'   => $productId,
            'variant_id'   => $variantId,
            'branch_id'    => $warehouseId ? null : $branchId,
            'warehouse_id' => $warehouseId,
        ];

        InventoryItem::firstOrCreate($attrs, ['quantity' => 0, 'reserved_quantity' => 0]);

        return InventoryItem::where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->when($warehouseId,
                fn ($q) => $q->where('warehouse_id', $warehouseId)->whereNull('branch_id'),
                fn ($q) => $q->where('branch_id', $branchId)->whereNull('warehouse_id')
            )
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Push a single product/location quantity to the central snapshot table.
     * Called after every stock mutation. Wrapped in try/catch so it never
     * fails the main transaction if the central DB is temporarily unavailable.
     */
    private function syncSnapshot(int $productId, ?int $branchId, ?int $warehouseId, float $qty): void
    {
        try {
            $storeId = app()->bound('current_store_id') ? app('current_store_id') : null;
            if (! $storeId) return;

            $product = Product::find($productId);
            if (! $product || ! $product->sku) return; // SKU is required for cross-store matching

            $store = \App\Models\Store::find($storeId);
            if (! $store) return;

            if ($warehouseId) {
                $locType = 'warehouse';
                $locId   = $warehouseId;
                $locName = Warehouse::find($warehouseId)?->name ?? "Warehouse #{$warehouseId}";
            } else {
                $locType = 'branch';
                $locId   = $branchId;
                $locName = \App\Models\Branch::find($branchId)?->name ?? "Branch #{$branchId}";
            }

            StoreInventorySnapshot::updateOrCreate(
                [
                    'store_id'      => $storeId,
                    'location_type' => $locType,
                    'location_id'   => $locId,
                    'product_sku'   => $product->sku,
                ],
                [
                    'store_name'   => $store->name,
                    'location_name'=> $locName,
                    'product_name' => $product->name,
                    'quantity'     => $qty,
                    'synced_at'    => now(),
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('StockService: snapshot sync failed — ' . $e->getMessage());
        }
    }
}
