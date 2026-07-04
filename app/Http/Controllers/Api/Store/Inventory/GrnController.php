<?php

namespace App\Http\Controllers\Api\Store\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Grn;
use App\Models\GrnItem;
use App\Models\PurchaseOrder;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GrnController extends Controller
{
    use ApiResponse;

    public function __construct(private StockService $stock) {}

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->can('view-suppliers')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $query = Grn::with(['supplier:id,name', 'purchaseOrder:id,po_number'])
            ->withCount('items');

        if ($request->filled('status'))      $query->where('status', $request->input('status'));
        if ($request->filled('supplier_id')) $query->where('supplier_id', $request->input('supplier_id'));

        return $this->paginatedResponse($query->latest()->paginate($request->input('per_page', 20)));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('view-suppliers')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $grn = Grn::with(['supplier', 'purchaseOrder', 'items.product', 'items.variant'])->findOrFail($id);

        return $this->successResponse(['grn' => $grn]);
    }

    /**
     * Create a standalone GRN (not linked to a PO).
     */
    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->can('manage-suppliers')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'supplier_id'              => 'nullable|exists:suppliers,id',
            'branch_id'                => 'required|integer',
            'received_date'            => 'required|date',
            'notes'                    => 'nullable|string',
            'status'                   => 'sometimes|in:draft,received',
            'items'                    => 'required|array|min:1',
            'items.*.product_id'       => 'required|exists:products,id',
            'items.*.variant_id'       => 'nullable|exists:product_variants,id',
            'items.*.quantity_received'=> 'required|numeric|min:0.001',
            'items.*.unit_cost'        => 'sometimes|numeric|min:0',
        ]);

        return DB::transaction(function () use ($validated) {
            $grn = $this->createGrn($validated);

            return $this->successResponse(
                ['grn' => $grn->load(['items.product', 'items.variant', 'supplier'])],
                'GRN created.',
                201
            );
        });
    }

    /**
     * Create a GRN pre-filled from a Purchase Order.
     * POST /purchase-orders/{poId}/grns
     */
    public function storeFromPo(Request $request, int $poId): JsonResponse
    {
        if (! $request->user()->can('manage-suppliers')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $po = PurchaseOrder::with('items.product', 'items.variant')->findOrFail($poId);

        if (! in_array($po->status, ['sent', 'partially_received'])) {
            return $this->errorResponse('PO must be in "sent" or "partially_received" status to receive goods.', 422);
        }

        $validated = $request->validate([
            'received_date' => 'required|date',
            'notes'         => 'nullable|string',
            'status'        => 'sometimes|in:draft,received',
            // Optional partial overrides
            'items'                         => 'sometimes|array',
            'items.*.po_item_id'            => 'required|exists:purchase_order_items,id',
            'items.*.quantity_received'     => 'required|numeric|min:0',
            'items.*.unit_cost'             => 'sometimes|numeric|min:0',
        ]);

        return DB::transaction(function () use ($po, $validated) {
            // Build items from PO if not provided
            $itemsInput = $validated['items'] ?? null;

            $grnItemsData = [];
            foreach ($po->items as $poItem) {
                if ($itemsInput) {
                    $override = collect($itemsInput)->firstWhere('po_item_id', $poItem->id);
                    if (! $override) continue;
                    $qtyReceived = (float) $override['quantity_received'];
                } else {
                    $qtyReceived = (float) $poItem->quantity_ordered - (float) $poItem->quantity_received;
                }

                if ($qtyReceived <= 0) continue;

                $grnItemsData[] = [
                    'product_id'        => $poItem->product_id,
                    'variant_id'        => $poItem->variant_id,
                    'quantity_received' => $qtyReceived,
                    'unit_cost'         => $poItem->unit_cost,
                    'po_item_id'        => $poItem->id,
                ];
            }

            if (empty($grnItemsData)) {
                return $this->errorResponse('No items to receive.', 422);
            }

            $grn = $this->createGrn([
                'supplier_id'   => $po->supplier_id,
                'branch_id'     => $po->branch_id,
                'received_date' => $validated['received_date'],
                'notes'         => $validated['notes'] ?? null,
                'status'        => $validated['status'] ?? 'received',
                'items'         => $grnItemsData,
                'purchase_order_id' => $po->id,
            ]);

            // Update PO item quantities received
            foreach ($grn->items as $grnItem) {
                if ($grnItem->po_item_id) {
                    $poItem = \App\Models\PurchaseOrderItem::find($grnItem->po_item_id);
                    if ($poItem) {
                        $poItem->increment('quantity_received', $grnItem->quantity_received);
                    }
                }
            }

            // Update PO status
            $allReceived = $po->items()->whereRaw('quantity_received < quantity_ordered')->doesntExist();
            $po->update(['status' => $allReceived ? 'received' : 'partially_received']);

            return $this->successResponse(
                ['grn' => $grn->load(['items.product', 'supplier']), 'purchase_order' => $po->fresh()],
                'GRN created from PO.',
                201
            );
        });
    }

    private function createGrn(array $data): Grn
    {
        $grn = Grn::create([
            'grn_number'         => $this->generateGrnNumber(),
            'purchase_order_id'  => $data['purchase_order_id'] ?? null,
            'supplier_id'        => $data['supplier_id'] ?? null,
            'branch_id'          => $data['branch_id'],
            'received_date'      => $data['received_date'],
            'status'             => $data['status'] ?? 'draft',
            'notes'              => $data['notes'] ?? null,
            'created_by'         => auth()->id(),
        ]);

        foreach ($data['items'] as $item) {
            GrnItem::create([
                'grn_id'            => $grn->id,
                'product_id'        => $item['product_id'],
                'variant_id'        => $item['variant_id'] ?? null,
                'quantity_received' => $item['quantity_received'],
                'unit_cost'         => $item['unit_cost'] ?? 0,
                'po_item_id'        => $item['po_item_id'] ?? null,
            ]);
        }

        // If status is "received", add stock immediately
        if ($grn->status === 'received') {
            $grn->load('items');
            foreach ($grn->items as $grnItem) {
                $this->stock->addStock(
                    $grnItem->product_id,
                    $grnItem->variant_id,
                    $grn->branch_id,
                    (float) $grnItem->quantity_received,
                    'purchase',
                    'grn',
                    $grn->id,
                    (float) $grnItem->unit_cost,
                    "GRN #{$grn->grn_number}"
                );
            }
        }

        return $grn;
    }

    private function generateGrnNumber(): string
    {
        $year  = now()->year;
        $count = Grn::whereYear('created_at', $year)->count() + 1;
        return sprintf('GRN-%s-%06d', $year, $count);
    }
}
