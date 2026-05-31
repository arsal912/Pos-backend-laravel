<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for all stock changes in the tenant DB.
 * All methods run inside transactions with row-level locks.
 * Never write to inventory_items directly from controllers.
 */
class StockService
{
    /**
     * Add stock (purchase, GRN, initial setup, transfer-in).
     */
    public function addStock(
        int $productId,
        ?int $variantId,
        int $branchId,
        float $qty,
        string $type,
        ?string $referenceType = null,
        ?int $referenceId = null,
        float $costAtTime = 0,
        ?string $notes = null
    ): StockMovement {
        return DB::transaction(function () use (
            $productId, $variantId, $branchId, $qty,
            $type, $referenceType, $referenceId, $costAtTime, $notes
        ) {
            $item = $this->getOrCreateInventoryItem($productId, $variantId, $branchId);
            $item->lockForUpdate()->refresh();

            $item->quantity = (float) $item->quantity + $qty;
            $item->save();

            return StockMovement::create([
                'product_id'     => $productId,
                'variant_id'     => $variantId,
                'branch_id'      => $branchId,
                'type'           => $type,
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
                'quantity'       => $qty,
                'cost_at_time'   => $costAtTime,
                'balance_after'  => $item->quantity,
                'notes'          => $notes,
                'created_by'     => auth()->id(),
            ]);
        });
    }

    /**
     * Deduct stock (sale, adjustment, transfer-out).
     * Throws InsufficientStockException if stock would go negative
     * and the product/store doesn't allow it.
     */
    public function deductStock(
        int $productId,
        ?int $variantId,
        int $branchId,
        float $qty,
        string $type,
        ?string $referenceType = null,
        ?int $referenceId = null,
        float $costAtTime = 0,
        ?string $notes = null
    ): StockMovement {
        return DB::transaction(function () use (
            $productId, $variantId, $branchId, $qty,
            $type, $referenceType, $referenceId, $costAtTime, $notes
        ) {
            $item = $this->getOrCreateInventoryItem($productId, $variantId, $branchId);
            $item->lockForUpdate()->refresh();

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

            return StockMovement::create([
                'product_id'     => $productId,
                'variant_id'     => $variantId,
                'branch_id'      => $branchId,
                'type'           => $type,
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
                'quantity'       => -$qty,          // negative = deduction
                'cost_at_time'   => $costAtTime,
                'balance_after'  => $newQty,
                'notes'          => $notes,
                'created_by'     => auth()->id(),
            ]);
        });
    }

    /**
     * Set stock to an absolute value (count correction / adjustment).
     */
    public function adjustStock(
        int $productId,
        ?int $variantId,
        int $branchId,
        float $newQty,
        string $reason = 'adjustment',
        ?string $notes = null
    ): StockMovement {
        return DB::transaction(function () use (
            $productId, $variantId, $branchId, $newQty, $reason, $notes
        ) {
            $item = $this->getOrCreateInventoryItem($productId, $variantId, $branchId);
            $item->lockForUpdate()->refresh();

            $diff         = $newQty - (float) $item->quantity;
            $item->quantity = $newQty;
            $item->last_counted_at = now();
            $item->save();

            return StockMovement::create([
                'product_id'  => $productId,
                'variant_id'  => $variantId,
                'branch_id'   => $branchId,
                'type'        => 'adjustment',
                'quantity'    => $diff,
                'balance_after' => $newQty,
                'notes'       => $notes ?? $reason,
                'created_by'  => auth()->id(),
            ]);
        });
    }

    /**
     * Transfer stock between branches.
     */
    public function transferStock(
        int $fromBranchId,
        int $toBranchId,
        int $productId,
        ?int $variantId,
        float $qty,
        ?int $transferId = null
    ): array {
        return DB::transaction(function () use (
            $fromBranchId, $toBranchId, $productId, $variantId, $qty, $transferId
        ) {
            $out = $this->deductStock(
                $productId, $variantId, $fromBranchId, $qty,
                'transfer_out', 'stock_transfer', $transferId
            );
            $in = $this->addStock(
                $productId, $variantId, $toBranchId, $qty,
                'transfer_in', 'stock_transfer', $transferId
            );
            return ['out' => $out, 'in' => $in];
        });
    }

    /**
     * Reserve stock for a pending sale (reduces available, not quantity).
     */
    public function reserveStock(int $productId, ?int $variantId, int $branchId, float $qty): void
    {
        DB::transaction(function () use ($productId, $variantId, $branchId, $qty) {
            $item = $this->getOrCreateInventoryItem($productId, $variantId, $branchId);
            $item->lockForUpdate()->refresh();

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

    /**
     * Release a stock reservation (sale cancelled / failed).
     */
    public function releaseReservation(int $productId, ?int $variantId, int $branchId, float $qty): void
    {
        DB::transaction(function () use ($productId, $variantId, $branchId, $qty) {
            $item = $this->getOrCreateInventoryItem($productId, $variantId, $branchId);
            $item->lockForUpdate()->refresh();
            $item->reserved_quantity = max(0, (float) $item->reserved_quantity - $qty);
            $item->save();
        });
    }

    // -------------------------------------------------------------------------

    private function getOrCreateInventoryItem(
        int $productId,
        ?int $variantId,
        int $branchId
    ): InventoryItem {
        return InventoryItem::firstOrCreate(
            ['product_id' => $productId, 'variant_id' => $variantId, 'branch_id' => $branchId],
            ['quantity' => 0, 'reserved_quantity' => 0]
        );
    }
}
