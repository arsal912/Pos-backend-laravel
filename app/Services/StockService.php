<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for all stock changes in the tenant DB.
 * All methods run inside DB transactions with row-level locks.
 * Never write to inventory_items directly from controllers.
 */
class StockService
{
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
            $item = $this->lockedItem($productId, $variantId, $branchId);
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
            $item   = $this->lockedItem($productId, $variantId, $branchId);
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
                'quantity'       => -$qty,
                'cost_at_time'   => $costAtTime,
                'balance_after'  => $newQty,
                'notes'          => $notes,
                'created_by'     => auth()->id(),
            ]);
        });
    }

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
            $item = $this->lockedItem($productId, $variantId, $branchId);
            $diff = $newQty - (float) $item->quantity;
            $item->quantity        = $newQty;
            $item->last_counted_at = now();
            $item->save();

            return StockMovement::create([
                'product_id'    => $productId,
                'variant_id'    => $variantId,
                'branch_id'     => $branchId,
                'type'          => 'adjustment',
                'quantity'      => $diff,
                'balance_after' => $newQty,
                'notes'         => $notes ?? $reason,
                'created_by'    => auth()->id(),
            ]);
        });
    }

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
            $out = $this->deductStock($productId, $variantId, $fromBranchId, $qty, 'transfer_out', 'stock_transfer', $transferId);
            $in  = $this->addStock($productId, $variantId, $toBranchId,   $qty, 'transfer_in',  'stock_transfer', $transferId);
            return ['out' => $out, 'in' => $in];
        });
    }

    public function reserveStock(int $productId, ?int $variantId, int $branchId, float $qty): void
    {
        DB::transaction(function () use ($productId, $variantId, $branchId, $qty) {
            $item      = $this->lockedItem($productId, $variantId, $branchId);
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

    public function releaseReservation(int $productId, ?int $variantId, int $branchId, float $qty): void
    {
        DB::transaction(function () use ($productId, $variantId, $branchId, $qty) {
            $item = $this->lockedItem($productId, $variantId, $branchId);
            $item->reserved_quantity = max(0, (float) $item->reserved_quantity - $qty);
            $item->save();
        });
    }

    // ── Internal ─────────────────────────────────────────────────────────────

    /**
     * Get or create an InventoryItem with a row-level lock for update.
     * Must be called inside a DB::transaction().
     */
    private function lockedItem(int $productId, ?int $variantId, int $branchId): InventoryItem
    {
        // Ensure row exists
        InventoryItem::firstOrCreate(
            ['product_id' => $productId, 'variant_id' => $variantId, 'branch_id' => $branchId],
            ['quantity' => 0, 'reserved_quantity' => 0]
        );

        // Now fetch with lock — must be done as a query, not on the model instance
        return InventoryItem::where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
