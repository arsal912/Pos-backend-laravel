<?php

namespace App\Http\Controllers\Api\Store\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\InventoryItem;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockAdjustmentController extends Controller
{
    use ApiResponse;

    public function __construct(private StockService $stock) {}

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->can('view-inventory')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $query = StockAdjustment::with('items.product:id,name,sku')
            ->latest();

        if ($request->filled('branch_id')) $query->where('branch_id', $request->input('branch_id'));
        if ($request->filled('status'))    $query->where('status', $request->input('status'));

        return $this->paginatedResponse($query->paginate($request->input('per_page', 20)));
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->can('manage-inventory')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'branch_id'            => 'required|integer',
            'reason'               => 'required|in:damage,loss,count_correction,expired,other',
            'notes'                => 'nullable|string',
            'submit'               => 'sometimes|boolean',
            'items'                => 'required|array|min:1',
            'items.*.product_id'   => 'required|exists:products,id',
            'items.*.variant_id'   => 'nullable|exists:product_variants,id',
            'items.*.quantity_after' => 'required|numeric|min:0',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $adj = StockAdjustment::create([
                'branch_id'  => $validated['branch_id'],
                'reason'     => $validated['reason'],
                'notes'      => $validated['notes'] ?? null,
                'created_by' => auth()->id(),
                'status'     => 'draft',
            ]);

            foreach ($validated['items'] as $item) {
                $inventoryItem = InventoryItem::where('product_id', $item['product_id'])
                    ->where('variant_id', $item['variant_id'] ?? null)
                    ->where('branch_id', $validated['branch_id'])
                    ->first();

                $qtyBefore = $inventoryItem ? (float) $inventoryItem->quantity : 0;

                StockAdjustmentItem::create([
                    'stock_adjustment_id' => $adj->id,
                    'product_id'          => $item['product_id'],
                    'variant_id'          => $item['variant_id'] ?? null,
                    'quantity_before'     => $qtyBefore,
                    'quantity_after'      => $item['quantity_after'],
                    'difference'          => (float) $item['quantity_after'] - $qtyBefore,
                    'cost_at_time'        => \App\Models\Product::find($item['product_id'])?->cost_price ?? 0,
                ]);
            }

            // Optionally auto-approve
            if ($request->boolean('submit')) {
                $this->doApprove($adj, $request->user());
            }

            return $this->successResponse(
                ['adjustment' => $adj->load('items.product')],
                'Stock adjustment created.',
                201
            );
        });
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('manage-inventory')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $adj = StockAdjustment::with('items')->findOrFail($id);

        if ($adj->status !== 'draft') {
            return $this->errorResponse('Only draft adjustments can be approved.', 422);
        }

        DB::transaction(fn () => $this->doApprove($adj, $request->user()));

        return $this->successResponse(['adjustment' => $adj->fresh('items')], 'Adjustment approved and stock updated.');
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('manage-inventory')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $adj = StockAdjustment::findOrFail($id);

        if ($adj->status !== 'draft') {
            return $this->errorResponse('Only draft adjustments can be rejected.', 422);
        }

        $adj->update(['status' => 'rejected', 'approved_by' => auth()->id(), 'approved_at' => now()]);

        return $this->successResponse(['adjustment' => $adj->fresh()], 'Adjustment rejected.');
    }

    private function doApprove(StockAdjustment $adj, $user): void
    {
        foreach ($adj->items as $item) {
            $this->stock->adjustStock(
                $item->product_id,
                $item->variant_id,
                $adj->branch_id,
                (float) $item->quantity_after,
                $adj->reason,
                $adj->notes
            );
        }

        $adj->update([
            'status'      => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);
    }
}
