<?php

namespace App\Http\Controllers\Api\Store\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockTransferController extends Controller
{
    use ApiResponse;

    public function __construct(private StockService $stock) {}

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->can('transfer-stock')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $query = StockTransfer::with('items.product:id,name,sku')->latest();

        if ($request->filled('status'))         $query->where('status', $request->input('status'));
        if ($request->filled('from_branch_id')) $query->where('from_branch_id', $request->input('from_branch_id'));
        if ($request->filled('to_branch_id'))   $query->where('to_branch_id', $request->input('to_branch_id'));

        return $this->paginatedResponse($query->paginate($request->input('per_page', 20)));
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->can('transfer-stock')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'from_branch_id'         => 'required|integer',
            'to_branch_id'           => 'required|integer|different:from_branch_id',
            'transfer_date'          => 'required|date',
            'notes'                  => 'nullable|string',
            'items'                  => 'required|array|min:1',
            'items.*.product_id'     => 'required|exists:products,id',
            'items.*.variant_id'     => 'nullable|exists:product_variants,id',
            'items.*.quantity_sent'  => 'required|numeric|min:0.001',
        ]);

        return DB::transaction(function () use ($validated) {
            $transferNumber = $this->generateNumber();

            $transfer = StockTransfer::create([
                'transfer_number' => $transferNumber,
                'from_branch_id'  => $validated['from_branch_id'],
                'to_branch_id'    => $validated['to_branch_id'],
                'transfer_date'   => $validated['transfer_date'],
                'notes'           => $validated['notes'] ?? null,
                'status'          => 'draft',
                'created_by'      => auth()->id(),
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
                    $transfer->id
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
                        $transfer->id
                    );
                }

                $item->update(['quantity_received' => $received]);
            }

            $transfer->update([
                'status'       => 'received',
                'received_date' => now()->toDateString(),
                'received_by'  => auth()->id(),
            ]);
        });

        return $this->successResponse(['transfer' => $transfer->fresh('items')], 'Transfer received — stock added to destination.');
    }

    private function generateNumber(): string
    {
        $year  = now()->year;
        $count = StockTransfer::whereYear('created_at', $year)->count() + 1;
        return sprintf('TR-%s-%06d', $year, $count);
    }
}
