<?php

namespace App\Http\Controllers\Api\Store\Pos;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    use ApiResponse;

    public function __construct(private StockService $stock) {}

    // ── Sale History ──────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->can('view-sales')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $query = Sale::with(['customer:id,name,phone', 'cashier:id,name', 'payments', 'items'])
            ->where('status', '!=', 'draft');

        if ($request->filled('status'))       $query->where('status', $request->input('status'));
        if ($request->filled('cashier_id'))   $query->where('cashier_id', $request->input('cashier_id'));
        if ($request->filled('customer_id'))  $query->where('customer_id', $request->input('customer_id'));
        if ($request->filled('branch_id'))    $query->where('branch_id', $request->input('branch_id'));
        if ($request->filled('date_from'))    $query->where('sale_date', '>=', $request->input('date_from'));
        if ($request->filled('date_to'))      $query->where('sale_date', '<=', $request->input('date_to'));

        return $this->paginatedResponse($query->latest('sale_date')->paginate($request->input('per_page', 20)));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('view-sales')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $sale = Sale::with(['items.product', 'items.variant', 'payments', 'customer', 'returns'])->findOrFail($id);

        return $this->successResponse(['sale' => $sale]);
    }

    // ── Returns / Refunds ────────────────────────────────────────────────────

    public function createReturn(Request $request, int $saleId): JsonResponse
    {
        if (! $request->user()->can('refund-sales')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'refund_method'           => 'required|in:cash,card,bank_transfer,jazzcash,easypaisa,store_credit,other',
            'reason'                  => 'nullable|string',
            'notes'                   => 'nullable|string',
            'items'                   => 'required|array|min:1',
            'items.*.sale_item_id'    => 'required|exists:sale_items,id',
            'items.*.quantity_returned' => 'required|numeric|min:0.001',
            'items.*.restock'         => 'sometimes|boolean',
        ]);

        $sale = Sale::with('items.product')->findOrFail($saleId);

        if (! $sale->isCompleted()) {
            return $this->errorResponse('Can only return items from completed sales.', 422);
        }

        return DB::transaction(function () use ($validated, $sale) {
            $refundTotal = 0;
            $returnItems = [];

            foreach ($validated['items'] as $ri) {
                $saleItem = $sale->items()->findOrFail($ri['sale_item_id']);
                $maxReturnable = (float) $saleItem->quantity - $saleItem->quantityReturned();

                if ((float) $ri['quantity_returned'] > $maxReturnable) {
                    throw new \RuntimeException(
                        "Cannot return {$ri['quantity_returned']} of \"{$saleItem->product_name}\". Max: {$maxReturnable}"
                    );
                }

                $lineRefund = (float) $saleItem->unit_price * (float) $ri['quantity_returned'];
                $refundTotal += $lineRefund;

                $returnItems[] = [
                    'sale_item'         => $saleItem,
                    'quantity_returned' => (float) $ri['quantity_returned'],
                    'refund_amount'     => $lineRefund,
                    'restock'           => $ri['restock'] ?? true,
                ];
            }

            $saleReturn = SaleReturn::create([
                'return_number'    => SaleReturn::generateNumber(),
                'original_sale_id' => $sale->id,
                'branch_id'        => $sale->branch_id,
                'customer_id'      => $sale->customer_id,
                'cashier_id'       => auth()->id(),
                'return_date'      => now()->toDateString(),
                'refund_amount'    => $refundTotal,
                'refund_method'    => $validated['refund_method'],
                'reason'           => $validated['reason'] ?? null,
                'notes'            => $validated['notes'] ?? null,
                'status'           => 'completed',
            ]);

            foreach ($returnItems as $ri) {
                SaleReturnItem::create([
                    'sale_return_id'    => $saleReturn->id,
                    'sale_item_id'      => $ri['sale_item']->id,
                    'product_id'        => $ri['sale_item']->product_id,
                    'variant_id'        => $ri['sale_item']->variant_id,
                    'quantity_returned' => $ri['quantity_returned'],
                    'unit_price'        => $ri['sale_item']->unit_price,
                    'refund_amount'     => $ri['refund_amount'],
                    'restock'           => $ri['restock'],
                ]);

                // Re-add stock if restock flag is true
                if ($ri['restock'] && $ri['sale_item']->product?->track_stock) {
                    $this->stock->addStock(
                        $ri['sale_item']->product_id,
                        $ri['sale_item']->variant_id,
                        $sale->branch_id,
                        $ri['quantity_returned'],
                        'sale_return',
                        'sale_return',
                        $saleReturn->id,
                        (float) $ri['sale_item']->cost_at_time,
                        'Sale return #' . $saleReturn->return_number
                    );
                }
            }

            // Update sale status
            $allItemsFullyReturned = $sale->items->every(function ($item) {
                return (float) $item->quantity <= $item->quantityReturned();
            });

            $sale->update([
                'status' => $allItemsFullyReturned ? 'refunded' : 'partially_refunded',
            ]);

            // Update customer stats — refund reduces lifetime value
            if ($sale->customer_id) {
                try {
                    \App\Models\Customer::updatePurchaseStats($sale->customer_id);
                } catch (\Throwable) {}
            }

            return $this->successResponse(
                ['return' => $saleReturn->load('items')],
                'Return processed.',
                201
            );
        });
    }

    public function listReturns(Request $request): JsonResponse
    {
        if (! $request->user()->can('view-sales')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $returns = SaleReturn::with(['originalSale:id,sale_number', 'items'])
            ->latest('return_date')
            ->paginate($request->input('per_page', 20));

        return $this->paginatedResponse($returns);
    }

    public function showReturn(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('view-sales')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        return $this->successResponse(['return' => SaleReturn::with(['originalSale', 'items.product'])->findOrFail($id)]);
    }
}
