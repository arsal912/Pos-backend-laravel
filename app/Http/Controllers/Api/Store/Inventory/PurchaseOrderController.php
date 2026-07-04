<?php

namespace App\Http\Controllers\Api\Store\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->can('view-suppliers')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $query = PurchaseOrder::with('supplier:id,name,company')
            ->withCount('items');

        if ($request->filled('status'))      $query->where('status', $request->input('status'));
        if ($request->filled('supplier_id')) $query->where('supplier_id', $request->input('supplier_id'));
        if ($search = $request->input('search')) {
            $query->where('po_number', 'like', "%{$search}%");
        }

        return $this->paginatedResponse($query->latest()->paginate($request->input('per_page', 20)));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('view-suppliers')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $po = PurchaseOrder::with(['supplier', 'items.product', 'items.variant', 'grns'])->findOrFail($id);

        return $this->successResponse(['purchase_order' => $po]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->can('manage-suppliers')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'supplier_id'              => 'required|exists:suppliers,id',
            'branch_id'                => 'required|integer',
            'order_date'               => 'required|date',
            'expected_delivery_date'   => 'nullable|date',
            'notes'                    => 'nullable|string',
            'terms'                    => 'nullable|string',
            'items'                    => 'required|array|min:1',
            'items.*.product_id'       => 'required|exists:products,id',
            'items.*.variant_id'       => 'nullable|exists:product_variants,id',
            'items.*.quantity_ordered' => 'required|numeric|min:0.001',
            'items.*.unit_cost'        => 'required|numeric|min:0',
            'items.*.tax_rate'         => 'sometimes|numeric|min:0',
            'items.*.discount'         => 'sometimes|numeric|min:0',
        ]);

        return DB::transaction(function () use ($validated) {
            $po = PurchaseOrder::create([
                'po_number'               => $this->generatePoNumber(),
                'supplier_id'             => $validated['supplier_id'],
                'branch_id'               => $validated['branch_id'],
                'order_date'              => $validated['order_date'],
                'expected_delivery_date'  => $validated['expected_delivery_date'] ?? null,
                'notes'                   => $validated['notes'] ?? null,
                'terms'                   => $validated['terms'] ?? null,
                'status'                  => 'draft',
                'created_by'              => auth()->id(),
            ]);

            foreach ($validated['items'] as $item) {
                $taxRate  = (float) ($item['tax_rate'] ?? 0);
                $discount = (float) ($item['discount'] ?? 0);
                $subtotal = (float) $item['quantity_ordered'] * (float) $item['unit_cost'];
                $subtotal = $subtotal - ($subtotal * $discount / 100);

                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'product_id'        => $item['product_id'],
                    'variant_id'        => $item['variant_id'] ?? null,
                    'quantity_ordered'  => $item['quantity_ordered'],
                    'unit_cost'         => $item['unit_cost'],
                    'tax_rate'          => $taxRate,
                    'discount'          => $discount,
                    'subtotal'          => $subtotal,
                ]);
            }

            $po->recalculateTotals();

            return $this->successResponse(
                ['purchase_order' => $po->load(['items.product', 'supplier'])],
                'Purchase order created.',
                201
            );
        });
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('manage-suppliers')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $po = PurchaseOrder::findOrFail($id);

        if (! in_array($po->status, ['draft'])) {
            return $this->errorResponse('Only draft POs can be edited.', 422);
        }

        $validated = $request->validate([
            'expected_delivery_date' => 'nullable|date',
            'notes'                  => 'nullable|string',
            'terms'                  => 'nullable|string',
        ]);

        $po->update($validated);

        return $this->successResponse(['purchase_order' => $po->fresh()], 'Purchase order updated.');
    }

    public function send(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('manage-suppliers')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $po = PurchaseOrder::findOrFail($id);

        if ($po->status !== 'draft') {
            return $this->errorResponse('Only draft POs can be sent.', 422);
        }

        $po->update(['status' => 'sent']);

        return $this->successResponse(['purchase_order' => $po->fresh()], 'PO marked as sent.');
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('manage-suppliers')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $po = PurchaseOrder::findOrFail($id);

        if (in_array($po->status, ['received', 'cancelled'])) {
            return $this->errorResponse('Cannot cancel a received or already-cancelled PO.', 422);
        }

        $po->update(['status' => 'cancelled']);

        return $this->successResponse(['purchase_order' => $po->fresh()], 'PO cancelled.');
    }

    private function generatePoNumber(): string
    {
        $year  = now()->year;
        $count = PurchaseOrder::whereYear('created_at', $year)->count() + 1;
        return sprintf('PO-%s-%06d', $year, $count);
    }
}
