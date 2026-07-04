<?php

namespace App\Services;

use App\Exceptions\CreditLimitExceededException;
use App\Models\CreditTransaction;
use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for all customer credit movements.
 * All methods are transactional with row-level locks on the customer.
 */
class CreditService
{
    public function getOutstandingBalance(int $customerId): float
    {
        return (float) Customer::find($customerId)?->outstanding_balance ?? 0;
    }

    // ── Add credit sale ───────────────────────────────────────────────────────

    /**
     * Record a sale on credit. Throws CreditLimitExceededException if limit breached.
     */
    public function addSaleOnCredit(int $saleId, float $amount): CreditTransaction
    {
        return DB::transaction(function () use ($saleId, $amount) {
            $sale     = Sale::findOrFail($saleId);
            $customer = Customer::lockForUpdate()->findOrFail($sale->customer_id);

            if (! $customer->canTakeCredit($amount)) {
                throw new CreditLimitExceededException(
                    "Credit limit exceeded for customer {$customer->name}. " .
                    "Limit: {$customer->credit_limit}, Balance: {$customer->outstanding_balance}, " .
                    "Requested: {$amount}",
                    (float) $customer->outstanding_balance,
                    (float) $customer->credit_limit,
                    $amount
                );
            }

            $newBalance = (float) $customer->outstanding_balance + $amount;
            $customer->update(['outstanding_balance' => $newBalance]);

            return CreditTransaction::create([
                'customer_id'    => $customer->id,
                'type'           => 'sale_on_credit',
                'amount'         => $amount,           // positive = owes more
                'balance_after'  => $newBalance,
                'reference_type' => 'sale',
                'reference_id'   => $saleId,
                'notes'          => "Sale #{$sale->sale_number}",
                'created_by'     => auth()->id(),
            ]);
        });
    }

    // ── Record payment ────────────────────────────────────────────────────────

    public function recordPayment(
        int $customerId,
        float $amount,
        string $paymentMethod,
        ?string $notes = null,
        ?string $referenceType = null,
        ?int $referenceId = null
    ): CreditTransaction {
        return DB::transaction(function () use (
            $customerId, $amount, $paymentMethod, $notes, $referenceType, $referenceId
        ) {
            $customer   = Customer::lockForUpdate()->findOrFail($customerId);
            $newBalance = max(0, (float) $customer->outstanding_balance - $amount);
            $customer->update(['outstanding_balance' => $newBalance]);

            return CreditTransaction::create([
                'customer_id'     => $customerId,
                'type'            => 'payment_received',
                'amount'          => -$amount,          // negative = paid down
                'balance_after'   => $newBalance,
                'payment_method'  => $paymentMethod,
                'reference_type'  => $referenceType,
                'reference_id'    => $referenceId,
                'notes'           => $notes,
                'created_by'      => auth()->id(),
            ]);
        });
    }

    // ── Refund to credit account ──────────────────────────────────────────────

    /**
     * Refund a sale return to the customer's credit account (reduces outstanding).
     */
    public function refundToCredit(int $customerId, float $amount, int $saleId): CreditTransaction
    {
        return DB::transaction(function () use ($customerId, $amount, $saleId) {
            $customer   = Customer::lockForUpdate()->findOrFail($customerId);
            $newBalance = max(0, (float) $customer->outstanding_balance - $amount);
            $customer->update(['outstanding_balance' => $newBalance]);

            return CreditTransaction::create([
                'customer_id'    => $customerId,
                'type'           => 'refund_credit',
                'amount'         => -$amount,
                'balance_after'  => $newBalance,
                'reference_type' => 'sale',
                'reference_id'   => $saleId,
                'notes'          => "Refund applied to credit account",
                'created_by'     => auth()->id(),
            ]);
        });
    }

    // ── Set opening balance ───────────────────────────────────────────────────

    public function setOpeningBalance(int $customerId, float $amount): CreditTransaction
    {
        return DB::transaction(function () use ($customerId, $amount) {
            $customer   = Customer::lockForUpdate()->findOrFail($customerId);
            $customer->update(['outstanding_balance' => $amount]);

            return CreditTransaction::create([
                'customer_id'   => $customerId,
                'type'          => 'opening_balance',
                'amount'        => $amount,
                'balance_after' => $amount,
                'notes'         => 'Opening balance set',
                'created_by'    => auth()->id(),
            ]);
        });
    }

    // ── Recompute (integrity check) ───────────────────────────────────────────

    /**
     * Recompute outstanding_balance from credit_transactions.
     * Used by the weekly integrity-check command.
     * Returns the computed balance (also updates the customer row).
     */
    public function recompute(int $customerId): float
    {
        return DB::transaction(function () use ($customerId) {
            $computed = (float) CreditTransaction::where('customer_id', $customerId)
                ->sum('amount'); // positive = owes, negative = paid

            $computed = max(0, $computed);
            Customer::lockForUpdate()->findOrFail($customerId)->update(['outstanding_balance' => $computed]);

            return $computed;
        });
    }

    // ── Aging helpers ─────────────────────────────────────────────────────────

    /**
     * Get aging breakdown for a customer — buckets based on transaction dates.
     * Returns ['current','days_1_30','days_31_60','days_61_90','days_90_plus']
     */
    public function agingBucketsForCustomer(int $customerId): array
    {
        $buckets = [
            'current'    => 0.0,
            'days_1_30'  => 0.0,
            'days_31_60' => 0.0,
            'days_61_90' => 0.0,
            'days_90_plus' => 0.0,
        ];

        CreditTransaction::where('customer_id', $customerId)
            ->where('type', 'sale_on_credit')
            ->get()
            ->each(function ($tx) use (&$buckets) {
                $days = (int) $tx->created_at->diffInDays(now());
                if ($days <= 0)       $buckets['current']     += (float) $tx->amount;
                elseif ($days <= 30)  $buckets['days_1_30']   += (float) $tx->amount;
                elseif ($days <= 60)  $buckets['days_31_60']  += (float) $tx->amount;
                elseif ($days <= 90)  $buckets['days_61_90']  += (float) $tx->amount;
                else                  $buckets['days_90_plus'] += (float) $tx->amount;
            });

        return $buckets;
    }
}
