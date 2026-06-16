<?php

namespace App\Http\Controllers\Api\Store\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Http\Traits\LocationScope;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockTransferController extends Controller
{
    use ApiResponse, LocationScope;

    public function __construct(private StockService $stock) {}

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->can('transfer-stock')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $query = StockTransfer::with('items.product:id,name,sku')->latest();

        // Branch/warehouse managers only see transfers involving their location
        $this->applyTransferScope($query, $request);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        // Filter by from-location (branch or warehouse)
        if ($request->filled('from_branch_id')) {
            $query->where('from_branch_id', $request->input('from_branch_id'));
        } elseif ($request->filled('from_warehouse_id')) {
            $query->where('from_warehouse_id', $request->input('from_warehouse_id'));
        }
        // Filter by to-location (branch or warehouse)
        if ($request->filled('to_branch_id')) {
            $query->where('to_branch_id', $request->input('to_branch_id'));
        } elseif ($request->filled('to_warehouse_id')) {
            $query->where('to_warehouse_id', $request->input('to_warehouse_id'));
        }

        return $this->paginatedResponse($query->paginate($request->input('per_page', 20)));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('transfer-stock')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $transfer = StockTransfer::with('items.product:id,name,sku,cost_price')
            ->findOrFail($id);

        return $this->successResponse(['transfer' => $transfer]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->can('transfer-stock')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'from_branch_id'      => 'nullable|integer',
            'from_warehouse_id'   => 'nullable|integer',
            'to_branch_id'        => 'nullable|integer',
            'to_warehouse_id'     => 'nullable|integer',
            'transfer_date'       => 'required|date',
            'notes'               => 'nullable|string',
            'items'               => 'required|array|min:1',
            'items.*.product_id'  => 'required|exists:products,id',
            'items.*.variant_id'  => 'nullable|exists:product_variants,id',
            'items.*.quantity_sent' => 'required|numeric|min:0.001',
        ]);

        $fromBranchId    = $validated['from_branch_id']    ?? null;
        $fromWarehouseId = $validated['from_warehouse_id'] ?? null;
        $toBranchId      = $validated['to_branch_id']      ?? null;
        $toWarehouseId   = $validated['to_warehouse_id']   ?? null;

        // At least one source and one destination must be provided
        if (! $fromBranchId && ! $fromWarehouseId) {
            return $this->errorResponse('Select a source branch or warehouse.', 422);
        }
        if (! $toBranchId && ! $toWarehouseId) {
            return $this->errorResponse('Select a destination branch or warehouse.', 422);
        }
        // Source and destination cannot be the same location
        if (($fromBranchId && $fromBranchId === $toBranchId)
         || ($fromWarehouseId && $fromWarehouseId === $toWarehouseId)) {
            return $this->errorResponse('Source and destination must be different locations.', 422);
        }

        return DB::transaction(function () use ($validated, $fromBranchId, $fromWarehouseId, $toBranchId, $toWarehouseId) {
            $transferNumber = $this->generateNumber();

            $transfer = StockTransfer::create([
                'transfer_number'   => $transferNumber,
                'from_branch_id'    => $fromBranchId,
                'from_warehouse_id' => $fromWarehouseId,
                'to_branch_id'      => $toBranchId,
                'to_warehouse_id'   => $toWarehouseId,
                'transfer_date'     => $validated['transfer_date'],
                'notes'             => $validated['notes'] ?? null,
                'status'            => 'draft',
                'created_by'        => auth()->user()?->id,
            ]);

            foreach ($validated['items'] as $item) {
                StockTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id'        => $item['product_id'],
                    'variant_id'        => $item['variant_id'] ?? null,
                    'quantity_sent'     => $item['quantity_sent'],
                ]);
            }

            return $this->successResponse(['transfer' => $transfer->load('items.product')], 'Transfer created.', 201);
        });
    }

    public function send(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('transfer-stock')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $transfer = StockTransfer::with('items')->findOrFail($id);

        if ($transfer->status !== 'draft') {
            return $this->errorResponse('Only draft transfers can be sent.', 422);
        }

        DB::transaction(function () use ($transfer) {
            foreach ($transfer->items as $item) {
                $this->stock->deductStock(
                    $item->product_id,
                    $item->variant_id,
                    $transfer->from_branch_id,
                    (float) $item->quantity_sent,
                    'transfer_out',
                    'stock_transfer',
                    $transfer->id,
                    0,
                    null,
                    $transfer->from_warehouse_id
                );
            }
            $transfer->update(['status' => 'in_transit']);
        });

        return $this->successResponse(['transfer' => $transfer->fresh()], 'Transfer dispatched — stock deducted from source.');
    }

    public function receive(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('transfer-stock')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $transfer = StockTransfer::with('items')->findOrFail($id);

        if ($transfer->status !== 'in_transit') {
            return $this->errorResponse('Only in-transit transfers can be received.', 422);
        }

        $validated = $request->validate([
            'items'                       => 'sometimes|array',
            'items.*.id'                  => 'required|exists:stock_transfer_items,id',
            'items.*.quantity_received'   => 'required|numeric|min:0',
        ]);

        DB::transaction(function () use ($transfer, $validated) {
            foreach ($transfer->items as $item) {
                $received = null;
                if (isset($validated['items'])) {
                    $override = collect($validated['items'])->firstWhere('id', $item->id);
                    $received = $override ? (float) $override['quantity_received'] : (float) $item->quantity_sent;
                } else {
                    $received = (float) $item->quantity_sent;
                }

                if ($received > 0) {
                    $this->stock->addStock(
                        $item->product_id,
                        $item->variant_id,
                        $transfer->to_branch_id,
                        $received,
                        'transfer_in',
                        'stock_transfer',
                        $transfer->id,
                        0,
                        null,
                        $transfer->to_warehouse_id
                    );
                }

                $item->update(['quantity_received' => $received]);
            }

            $transfer->update([
                'status'        => 'received',
                'received_date' => now()->toDateString(),
                'received_by'   => auth()->user()?->id,
            ]);
        });

        return $this->successResponse(['transfer' => $transfer->fresh('items')], 'Transfer received — stock added to destination.');
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('transfer-stock')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $transfer = StockTransfer::findOrFail($id);

        if ($transfer->status !== 'draft') {
            return $this->errorResponse('Only draft transfers can be cancelled.', 422);
        }

        $transfer->update(['status' => 'cancelled']);

        return $this->successResponse(['transfer' => $transfer->fresh()], 'Transfer cancelled.');
    }

    private function generateNumber(): string
    {
        $year  = now()->year;
        $count = StockTransfer::whereYear('created_at', $year)->count() + 1;
        return sprintf('TR-%s-%06d', $year, $count);
    }
}
