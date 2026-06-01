<?php

namespace App\Http\Controllers\Api\Store\Pos;

use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\CashDrawerSession;
use App\Models\HoldSale;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Services\StockService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosController extends Controller
{
    use ApiResponse;

    public function __construct(private StockService $stock) {}

    // =========================================================================
    // Cart / Draft Sale Operations
    // =========================================================================

    /**
     * POST /pos/sales — start a new draft sale.
     */
    public function createSale(Request $request): JsonResponse
    {
        if (! $request->user()->can('create-sales')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $branchId = $request->input('branch_id', 1);

        // Look for an existing draft by this cashier on this branch
        $existing = Sale::where('cashier_id', auth()->id())
            ->where('branch_id', $branchId)
            ->where('status', 'draft')
            ->latest()
            ->first();

        if ($existing && $request->boolean('resume', false)) {
            return $this->successResponse(['sale' => $existing->load('items.product', 'items.variant', 'payments', 'customer')], 'Draft sale resumed.');
        }

        $sale = Sale::create([
            'sale_number' => Sale::generateNumber(),
            'branch_id'   => $branchId,
            'cashier_id'  => auth()->id(),
            'sale_date'   => now()->toDateString(),
            'status'      => 'draft',
            'payment_status' => 'pending',
        ]);

        return $this->successResponse(['sale' => $sale], 'Sale started.', 201);
    }

    /**
     * POST /pos/sales/{id}/items — add or increment an item.
     */
    public function addItem(Request $request, int $saleId): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'variant_id' => 'nullable|exists:product_variants,id',
            'quantity'   => 'sometimes|numeric|min:0.001',
            'unit_price' => 'sometimes|numeric|min:0',
        ]);

        $sale = $this->getDraftSale($saleId, $request);
        if ($sale instanceof JsonResponse) return $sale;

        $product   = Product::findOrFail($validated['product_id']);
        $variantId = $validated['variant_id'] ?? null;

        // Determine price & cost
        if ($variantId) {
            $variant   = ProductVariant::findOrFail($variantId);
            $unitPrice = $validated['unit_price'] ?? (float) $variant->selling_price;
            $cost      = (float) $variant->cost_price;
            $sku       = $variant->sku;
            $name      = $product->name . ' - ' . $variant->name;
        } else {
            $unitPrice = $validated['unit_price'] ?? (float) $product->selling_price;
            $cost      = (float) $product->cost_price;
            $sku       = $product->sku;
            $name      = $product->name;
        }

        $qty = (float) ($validated['quantity'] ?? 1);

        // Stock check
        if ($product->track_stock && ! $product->allow_negative_stock) {
            $available = (float) InventoryItem::where('product_id', $product->id)
                ->where('variant_id', $variantId)
                ->where('branch_id', $sale->branch_id)
                ->value('quantity') ?? 0;

            if ($available < $qty) {
                return $this->errorResponse(
                    "Insufficient stock. Available: {$available}, requested: {$qty}",
                    422
                );
            }
        }

        // Tax
        $taxRate  = (float) ($product->taxRate?->rate ?? 0);
        $taxAmt   = $product->taxRate?->is_inclusive
            ? 0  // price already includes tax
            : round($unitPrice * $qty * $taxRate / 100, 2);
        $lineTotal = round($unitPrice * $qty, 2);

        // Merge with existing row if same product/variant
        $existing = $sale->items()
            ->where('product_id', $validated['product_id'])
            ->where('variant_id', $variantId)
            ->first();

        if ($existing) {
            $newQty   = (float) $existing->quantity + $qty;
            $newTotal = round($unitPrice * $newQty, 2);
            $newTax   = $product->taxRate?->is_inclusive ? 0 : round($unitPrice * $newQty * $taxRate / 100, 2);

            $existing->update([
                'quantity'   => $newQty,
                'tax_amount' => $newTax,
                'line_total' => $newTotal,
            ]);
            $item = $existing->fresh();
        } else {
            $item = $sale->items()->create([
                'product_id'   => $product->id,
                'variant_id'   => $variantId,
                'product_name' => $name,
                'sku'          => $sku,
                'quantity'     => $qty,
                'unit_price'   => $unitPrice,
                'cost_at_time' => $cost,
                'tax_rate'     => $taxRate,
                'tax_amount'   => $taxAmt,
                'line_total'   => $lineTotal,
            ]);
        }

        $sale->load('items')->recalculate();

        return $this->successResponse([
            'item' => $item->fresh(),
            'sale' => $sale->fresh('items', 'payments'),
        ], 'Item added.');
    }

    /**
     * PUT /pos/sales/{id}/items/{itemId} — update qty / price / discount.
     */
    public function updateItem(Request $request, int $saleId, int $itemId): JsonResponse
    {
        $validated = $request->validate([
            'quantity'        => 'sometimes|numeric|min:0.001',
            'unit_price'      => 'sometimes|numeric|min:0',
            'discount_amount' => 'sometimes|numeric|min:0',
        ]);

        $sale = $this->getDraftSale($saleId, $request);
        if ($sale instanceof JsonResponse) return $sale;

        $item = SaleItem::where('sale_id', $saleId)->findOrFail($itemId);

        $qty       = (float) ($validated['quantity'] ?? $item->quantity);
        $price     = (float) ($validated['unit_price'] ?? $item->unit_price);
        $discount  = (float) ($validated['discount_amount'] ?? $item->discount_amount);
        $lineTotal = round($price * $qty - $discount, 2);
        $taxAmt    = round($lineTotal * (float) $item->tax_rate / 100, 2);

        $item->update([
            'quantity'        => $qty,
            'unit_price'      => $price,
            'discount_amount' => $discount,
            'tax_amount'      => $taxAmt,
            'line_total'      => $lineTotal,
        ]);

        $sale->load('items')->recalculate();

        return $this->successResponse([
            'item' => $item->fresh(),
            'sale' => $sale->fresh('items'),
        ], 'Item updated.');
    }

    /**
     * DELETE /pos/sales/{id}/items/{itemId}
     */
    public function removeItem(Request $request, int $saleId, int $itemId): JsonResponse
    {
        $sale = $this->getDraftSale($saleId, $request);
        if ($sale instanceof JsonResponse) return $sale;

        SaleItem::where('sale_id', $saleId)->findOrFail($itemId)->delete();
        $sale->load('items')->recalculate();

        return $this->successResponse(['sale' => $sale->fresh('items')], 'Item removed.');
    }

    /**
     * POST /pos/sales/{id}/discount — apply sale-level discount.
     */
    public function applyDiscount(Request $request, int $saleId): JsonResponse
    {
        $validated = $request->validate([
            'discount_amount' => 'required|numeric|min:0',
            'discount_type'   => 'required|in:fixed,percent',
            'discount_reason' => 'nullable|string',
        ]);

        $sale = $this->getDraftSale($saleId, $request);
        if ($sale instanceof JsonResponse) return $sale;

        $sale->update([
            'discount_amount' => $validated['discount_amount'],
            'discount_type'   => $validated['discount_type'],
            'discount_reason' => $validated['discount_reason'] ?? null,
        ]);

        $sale->load('items')->recalculate();

        return $this->successResponse(['sale' => $sale->fresh()], 'Discount applied.');
    }

    /**
     * POST /pos/sales/{id}/customer — attach a customer.
     */
    public function attachCustomer(Request $request, int $saleId): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
        ]);

        $sale = $this->getDraftSale($saleId, $request);
        if ($sale instanceof JsonResponse) return $sale;

        $sale->update(['customer_id' => $validated['customer_id']]);

        return $this->successResponse(['sale' => $sale->fresh('customer')], 'Customer attached.');
    }

    /**
     * POST /pos/sales/{id}/payments — add a payment row.
     */
    public function addPayment(Request $request, int $saleId): JsonResponse
    {
        $validated = $request->validate([
            'method'    => 'required|in:cash,card,bank_transfer,jazzcash,easypaisa,store_credit,other',
            'amount'    => 'required|numeric|min:0.01',
            'reference' => 'nullable|string',
            'notes'     => 'nullable|string',
        ]);

        $sale = $this->getDraftSale($saleId, $request);
        if ($sale instanceof JsonResponse) return $sale;

        $payment = $sale->payments()->create($validated);

        // Update paid_amount, change, balance
        $totalPaid = (float) $sale->payments()->sum('amount');
        $total     = (float) $sale->total;
        $change    = max(0, $totalPaid - $total);
        $balance   = min(0, $totalPaid - $total); // negative if underpaid

        $paymentStatus = $totalPaid >= $total ? 'paid' : 'partial';

        $sale->update([
            'paid_amount'    => $totalPaid,
            'change_given'   => $change,
            'balance'        => $balance,
            'payment_status' => $paymentStatus,
        ]);

        return $this->successResponse([
            'payment' => $payment,
            'sale'    => $sale->fresh('payments'),
        ], 'Payment added.');
    }

    /**
     * POST /pos/sales/{id}/complete — finalize: deduct stock, mark completed.
     */
    public function completeSale(Request $request, int $saleId): JsonResponse
    {
        if (! $request->user()->can('create-sales')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $sale = Sale::with(['items.product', 'payments'])->findOrFail($saleId);

        if (! $sale->isDraft()) {
            return $this->errorResponse('Only draft sales can be completed.', 422);
        }

        if ($sale->items->isEmpty()) {
            return $this->errorResponse('Cannot complete an empty sale.', 422);
        }

        $totalPaid = (float) $sale->payments()->sum('amount');
        if ($totalPaid < (float) $sale->total) {
            return $this->errorResponse(
                sprintf('Payment insufficient. Total: %s, Paid: %s', $sale->total, $totalPaid),
                422
            );
        }

        try {
            DB::transaction(function () use ($sale) {
                // Deduct stock for each item
                foreach ($sale->items as $item) {
                    if ($item->product?->track_stock) {
                        $this->stock->deductStock(
                            $item->product_id,
                            $item->variant_id,
                            $sale->branch_id,
                            (float) $item->quantity,
                            'sale',
                            'sale',
                            $sale->id,
                            (float) $item->cost_at_time
                        );
                    }
                }

                $sale->update([
                    'status'         => 'completed',
                    'payment_status' => 'paid',
                    'sale_date'      => now()->toDateString(),
                ]);
            });
        } catch (InsufficientStockException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->successResponse([
            'sale' => $sale->fresh(['items.product', 'payments', 'customer']),
        ], 'Sale completed.');
    }

    /**
     * POST /pos/sales/{id}/void — delete a draft.
     */
    public function voidSale(Request $request, int $saleId): JsonResponse
    {
        $sale = $this->getDraftSale($saleId, $request);
        if ($sale instanceof JsonResponse) return $sale;

        $sale->delete();

        return $this->successResponse(null, 'Sale voided.');
    }

    // =========================================================================
    // Receipts
    // =========================================================================

    public function receipt(Request $request, int $saleId): \Illuminate\Http\Response|JsonResponse
    {
        if (! $request->user()->can('view-sales')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $sale = Sale::with(['items.product', 'payments', 'customer'])->findOrFail($saleId);
        $store = app('current_store');

        $template = $request->input('format', 'thermal') === 'a4'
            ? 'pos.receipt-a4'
            : 'pos.receipt-thermal';

        $html = view($template, ['sale' => $sale, 'store' => $store])->render();

        if ($request->boolean('pdf')) {
            $pdf = Pdf::loadHTML($html);
            return response($pdf->output(), 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => "inline; filename=\"receipt-{$sale->sale_number}.pdf\"",
            ]);
        }

        return response($html, 200, ['Content-Type' => 'text/html']);
    }

    // =========================================================================
    // Hold (Park) Sales
    // =========================================================================

    public function holdSale(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:100',
            'data'        => 'required|array',
            'customer_id' => 'nullable|integer',
            'branch_id'   => 'sometimes|integer',
        ]);

        $held = HoldSale::create([
            'branch_id'   => $validated['branch_id'] ?? 1,
            'cashier_id'  => auth()->id(),
            'customer_id' => $validated['customer_id'] ?? null,
            'name'        => $validated['name'],
            'data'        => $validated['data'],
        ]);

        return $this->successResponse(['hold' => $held], 'Sale held.', 201);
    }

    public function listHeld(Request $request): JsonResponse
    {
        $holds = HoldSale::where('cashier_id', auth()->id())
            ->where('branch_id', $request->input('branch_id', 1))
            ->latest()
            ->get();

        return $this->successResponse(['holds' => $holds]);
    }

    public function resumeHeld(int $holdId): JsonResponse
    {
        $hold = HoldSale::where('cashier_id', auth()->id())->findOrFail($holdId);

        return $this->successResponse(['hold' => $hold], 'Cart data loaded — resume on client side.');
    }

    public function deleteHeld(int $holdId): JsonResponse
    {
        HoldSale::where('cashier_id', auth()->id())->findOrFail($holdId)->delete();

        return $this->successResponse(null, 'Held sale removed.');
    }

    // =========================================================================
    // Cash Drawer
    // =========================================================================

    public function openDrawer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'opening_balance' => 'required|numeric|min:0',
            'branch_id'       => 'sometimes|integer',
        ]);

        $open = CashDrawerSession::where('cashier_id', auth()->id())
            ->whereNull('closed_at')
            ->first();

        if ($open) {
            return $this->errorResponse('A drawer session is already open.', 422);
        }

        $session = CashDrawerSession::create([
            'branch_id'       => $validated['branch_id'] ?? 1,
            'cashier_id'      => auth()->id(),
            'opened_at'       => now(),
            'opening_balance' => $validated['opening_balance'],
        ]);

        return $this->successResponse(['session' => $session], 'Cash drawer opened.', 201);
    }

    public function currentDrawer(Request $request): JsonResponse
    {
        $session = CashDrawerSession::where('cashier_id', auth()->id())
            ->whereNull('closed_at')
            ->first();

        if (! $session) {
            return $this->successResponse(['session' => null], 'No open session.');
        }

        // Calculate expected balance
        $cashSales = Sale::where('cashier_id', auth()->id())
            ->where('status', 'completed')
            ->where('created_at', '>=', $session->opened_at)
            ->join('sale_payments', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sale_payments.method', 'cash')
            ->sum('sale_payments.amount');

        $expected = (float) $session->opening_balance + (float) $cashSales;

        return $this->successResponse([
            'session'          => $session,
            'expected_balance' => $expected,
            'cash_sales'       => $cashSales,
        ]);
    }

    public function closeDrawer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'closing_balance' => 'required|numeric|min:0',
            'notes'           => 'nullable|string',
        ]);

        $session = CashDrawerSession::where('cashier_id', auth()->id())
            ->whereNull('closed_at')
            ->firstOrFail();

        $cashSales = Sale::where('cashier_id', auth()->id())
            ->where('status', 'completed')
            ->where('created_at', '>=', $session->opened_at)
            ->join('sale_payments', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sale_payments.method', 'cash')
            ->sum('sale_payments.amount');

        $expected  = (float) $session->opening_balance + (float) $cashSales;
        $overShort = (float) $validated['closing_balance'] - $expected;

        $session->update([
            'closed_at'        => now(),
            'closing_balance'  => $validated['closing_balance'],
            'expected_balance' => $expected,
            'over_short'       => $overShort,
            'notes'            => $validated['notes'] ?? null,
        ]);

        return $this->successResponse(['session' => $session->fresh()], 'Cash drawer closed.');
    }

    public function drawerHistory(Request $request): JsonResponse
    {
        $sessions = CashDrawerSession::where('cashier_id', auth()->id())
            ->whereNotNull('closed_at')
            ->latest('closed_at')
            ->paginate($request->input('per_page', 20));

        return $this->paginatedResponse($sessions);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function getDraftSale(int $saleId, Request $request): Sale|JsonResponse
    {
        if (! $request->user()->can('create-sales')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $sale = Sale::with('items')->findOrFail($saleId);

        if (! $sale->isDraft()) {
            return $this->errorResponse('Sale is no longer a draft.', 422);
        }

        return $sale;
    }
}
