<?php

namespace App\Http\Controllers\Api\Store\Pos;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\PosDevice;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Services\CreditService;
use App\Services\LoyaltyService;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handles CLIENT → SERVER sync of offline-created sales.
 *
 * Each sale in the batch is processed independently (partial success allowed).
 * Conflict safety rules (spec D6):
 *   - NEVER reject a sale that the cashier already completed — stock/credit conflicts
 *     are flagged for review but the sale IS created.
 *   - Server assigns the real sale number; offline reference is preserved for audit.
 *   - Payments are TRUSTED: cashier physically received the money.
 */
class OfflineSalesSyncController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly StockService   $stock,
        private readonly LoyaltyService $loyalty,
        private readonly CreditService  $credit,
    ) {}

    // ── Batch upload endpoint ─────────────────────────────────────────────────

    public function syncSales(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_uuid'   => 'required|string|max:64',
            'sales'         => 'required|array|max:50',
            'sales.*.offline_reference'   => 'required|string|max:50',
            'sales.*.client_created_at'   => 'required|string',
            'sales.*.client_timezone'     => 'required|string|max:64',
            'sales.*.items'               => 'required|array|min:1',
            'sales.*.payments'            => 'required|array|min:1',
        ]);

        $storeId = app('current_store_id');

        // Validate device belongs to store and is still active
        $device = PosDevice::where('device_uuid', $validated['device_uuid'])
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->first();

        if (! $device) {
            return $this->errorResponse('Device is not registered or has been deactivated.', 403);
        }

        $device->update(['last_seen_at' => now(), 'last_sync_at' => now()]);

        $results = [];

        foreach ($validated['sales'] as $offlineSale) {
            $results[] = $this->processSingleSale($offlineSale, $device->id);
        }

        return $this->successResponse([
            'results'     => $results,
            'synced_at'   => now()->toISOString(),
            'device_id'   => $device->id,
        ]);
    }

    // ── Conflict resolution ───────────────────────────────────────────────────

    /** List unresolved synced sales with stock or credit conflicts. */
    public function conflicts(Request $request): JsonResponse
    {
        $conflicts = Sale::where(function ($q) {
                $q->where('has_stock_conflict', true)
                  ->orWhere('has_credit_conflict', true);
            })
            ->whereNotNull('offline_reference')
            ->with(['customer:id,name,phone', 'items:id,sale_id,product_name,sku,quantity,unit_price,line_total'])
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 20));

        return $this->paginatedResponse($conflicts);
    }

    /** Mark a conflict as acknowledged / resolved. */
    public function resolveConflict(Request $request, int $saleId): JsonResponse
    {
        $sale = Sale::where('offline_reference', '!=', null)->findOrFail($saleId);

        $validated = $request->validate([
            'resolution' => 'required|in:acknowledged,stock_adjusted,recovered',
            'notes'      => 'nullable|string|max:500',
        ]);

        $sale->update([
            'has_stock_conflict'  => false,
            'has_credit_conflict' => false,
            'notes'               => $sale->notes
                ? $sale->notes."\n[Resolved: {$validated['resolution']}]"
                : "[Resolved: {$validated['resolution']}]",
        ]);

        return $this->successResponse($sale->fresh(), 'Conflict resolved.');
    }

    // ── Single-sale processing ────────────────────────────────────────────────

    private function processSingleSale(array $data, int $deviceId): array
    {
        $offlineRef = $data['offline_reference'];

        // Idempotency: don't re-process an already synced sale
        if (Sale::where('offline_reference', $offlineRef)->exists()) {
            $existing = Sale::where('offline_reference', $offlineRef)->first();
            return [
                'offline_reference' => $offlineRef,
                'status'            => 'already_synced',
                'sale_id'           => $existing->id,
                'real_sale_number'  => $existing->sale_number,
                'conflicts'         => [],
            ];
        }

        $conflicts       = [];
        $hasStockConflict  = false;
        $hasCreditConflict = false;

        try {
            $result = DB::transaction(function () use ($data, $deviceId, &$hasStockConflict, &$hasCreditConflict, &$conflicts) {
                $defaultBranchId = Branch::where('is_active', true)->value('id') ?? 1;

                // ── Resolve customer ──────────────────────────────────────────
                $customer    = null;
                $customerId  = $data['customer_id'] ?? null;
                if ($customerId) {
                    $customer = Customer::find($customerId);
                    if (! $customer) {
                        $conflicts[] = ['type' => 'customer_deleted', 'message' => "Customer #{$customerId} no longer exists."];
                        $customerId  = null;
                    }
                }

                $clientCreatedAt = $this->parseClientTime($data['client_created_at'], $data['client_timezone']);

                // ── Create Sale ───────────────────────────────────────────────
                $sale = Sale::create([
                    'sale_number'            => Sale::generateNumber(),
                    'branch_id'              => $defaultBranchId,
                    'customer_id'            => $customerId,
                    'cashier_id'             => auth()->id(),
                    'sale_date'              => $clientCreatedAt->toDateString(),
                    'subtotal'               => (float) ($data['subtotal']        ?? 0),
                    'tax_amount'             => (float) ($data['tax_amount']      ?? 0),
                    'discount_amount'        => (float) ($data['discount_amount'] ?? 0),
                    'discount_type'          => $data['discount_type']   ?? null,
                    'discount_reason'        => $data['discount_reason'] ?? null,
                    'total'                  => (float) ($data['total']            ?? 0),
                    'paid_amount'            => (float) ($data['paid_amount']      ?? 0),
                    'change_given'           => (float) ($data['change_given']     ?? 0),
                    'balance'                => 0,
                    'status'                 => 'completed',
                    'payment_status'         => 'paid',
                    'notes'                  => $data['notes'] ?? null,
                    'offline_reference'      => $data['offline_reference'],
                    'synced_from_device_id'  => $deviceId,
                    'synced_at'              => now(),
                ]);

                // ── Items + Stock ─────────────────────────────────────────────
                foreach ($data['items'] ?? [] as $item) {
                    $product = Product::find($item['product_id']);

                    SaleItem::create([
                        'sale_id'         => $sale->id,
                        'product_id'      => $item['product_id'],
                        'variant_id'      => $item['variant_id'] ?? null,
                        'product_name'    => $item['name'] ?? ($product?->name ?? 'Unknown'),
                        'sku'             => $item['sku'] ?? '',
                        'quantity'        => (float) $item['quantity'],
                        'unit_price'      => (float) $item['unit_price'],
                        'cost_at_time'    => (float) ($product?->cost_price ?? 0),
                        'tax_rate'        => (float) ($item['tax_rate']       ?? 0),
                        'tax_amount'      => (float) ($item['tax_amount']      ?? 0),
                        'discount_amount' => (float) ($item['discount_amount'] ?? 0),
                        'line_total'      => (float) $item['total'],
                    ]);

                    // Stock deduction — conflict-safe (D6: STILL create sale)
                    if ($product && $product->track_stock) {
                        try {
                            $this->stock->deductStock(
                                $item['product_id'],
                                $item['variant_id'] ?? null,
                                $defaultBranchId,
                                (float) $item['quantity'],
                                'sale',
                                'sale',
                                $sale->id,
                                (float) ($product->cost_price ?? 0),
                                "Offline sync: {$data['offline_reference']}"
                            );
                        } catch (\Throwable $e) {
                            $hasStockConflict = true;
                            $conflicts[] = [
                                'type'    => 'stock',
                                'message' => "Insufficient stock for {$item['name']}: {$e->getMessage()}",
                            ];
                            Log::warning("Offline sync stock conflict: {$data['offline_reference']}, item: {$item['product_id']}: {$e->getMessage()}");
                        }
                    }
                }

                // ── Payments + Credit ─────────────────────────────────────────
                foreach ($data['payments'] ?? [] as $payment) {
                    SalePayment::create([
                        'sale_id'   => $sale->id,
                        'method'    => $payment['method'],
                        'amount'    => (float) $payment['amount'],
                        'reference' => $payment['reference'] ?? null,
                    ]);

                    if ($payment['method'] === 'on_credit' && $customerId) {
                        try {
                            $this->credit->addSaleOnCredit($sale->id, (float) $payment['amount']);
                        } catch (\Throwable $e) {
                            $hasCreditConflict = true;
                            $conflicts[] = [
                                'type'    => 'credit',
                                'message' => "Credit limit exceeded: {$e->getMessage()}",
                            ];
                            // Force-update outstanding balance anyway (cashier already gave credit)
                            Customer::where('id', $customerId)->increment('outstanding_balance', (float) $payment['amount']);
                            Log::warning("Offline sync credit conflict: {$data['offline_reference']}: {$e->getMessage()}");
                        }
                    }
                }

                // ── Set conflict flags ────────────────────────────────────────
                if ($hasStockConflict || $hasCreditConflict) {
                    $sale->update([
                        'has_stock_conflict'  => $hasStockConflict,
                        'has_credit_conflict' => $hasCreditConflict,
                    ]);
                }

                return $sale;
            });

            // Post-transaction: loyalty (non-fatal)
            try {
                $this->loyalty->earnFromSale($result->id);
            } catch (\Throwable) {}

            return [
                'offline_reference' => $offlineRef,
                'status'            => ($hasStockConflict || $hasCreditConflict) ? 'synced_with_conflicts' : 'synced',
                'sale_id'           => $result->id,
                'real_sale_number'  => $result->sale_number,
                'conflicts'         => $conflicts,
                'error'             => null,
            ];

        } catch (\Throwable $e) {
            Log::error("Offline sync failed for {$offlineRef}: {$e->getMessage()}");
            return [
                'offline_reference' => $offlineRef,
                'status'            => 'failed',
                'sale_id'           => null,
                'real_sale_number'  => null,
                'conflicts'         => [],
                'error'             => $e->getMessage(),
            ];
        }
    }

    private function parseClientTime(string $clientTime, string $timezone): \Carbon\Carbon
    {
        try {
            $dt  = \Carbon\Carbon::parse($clientTime, $timezone);
            $skew = abs(now()->diffInMinutes($dt));
            if ($skew > 60) {
                Log::warning("Offline sale time skew: {$skew} minutes. Device: {$timezone}, claimed: {$clientTime}");
            }
            return $dt;
        } catch (\Throwable) {
            return now();
        }
    }
}
