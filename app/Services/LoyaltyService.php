<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\LoyaltySettings;
use App\Models\LoyaltyTransaction;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for all loyalty point changes.
 * All methods run inside transactions with a row-level lock on the customer.
 */
class LoyaltyService
{
    // ── Read ─────────────────────────────────────────────────────────────────

    public function getBalance(int $customerId): float
    {
        return (float) Customer::lockForUpdate()->find($customerId)?->loyalty_points_balance ?? 0;
    }

    public function settings(): LoyaltySettings
    {
        return LoyaltySettings::current();
    }

    // ── Earn on sale ─────────────────────────────────────────────────────────

    /**
     * Calculate and award points for a completed sale.
     * Called from PosController::completeSale after stock is deducted.
     */
    public function earnFromSale(int $saleId): ?LoyaltyTransaction
    {
        $sale = Sale::with(['customer.group', 'items.product'])->find($saleId);

        if (! $sale || ! $sale->customer_id) {
            return null;
        }

        $settings = $this->settings();
        if (! $settings->is_enabled) return null;

        // Check if the customer's group earns loyalty
        if ($sale->customer->group && ! $sale->customer->group->earns_loyalty_points) {
            return null;
        }

        // Calculate points per line item
        $totalPoints = 0.0;

        foreach ($sale->items as $item) {
            $base = $settings->earn_on_discounted_sales
                ? (float) $item->line_total
                : (float) ($item->unit_price * $item->quantity);

            // Subtract tax if earn_on_tax is false
            if (! $settings->earn_on_tax) {
                $base -= (float) $item->tax_amount;
            }

            $base = max(0, $base);

            // Product-level multiplier (default 1.0)
            $multiplier = (float) ($item->product?->loyalty_points_multiplier ?? 1.0);

            $totalPoints += $base * (float) $settings->points_per_currency_unit * $multiplier;
        }

        $totalPoints = round($totalPoints, 2);
        if ($totalPoints <= 0) return null;

        $expiresAt = $settings->points_expiry_days
            ? now()->addDays($settings->points_expiry_days)
            : null;

        return $this->addPoints(
            $sale->customer_id,
            $totalPoints,
            'earn',
            'Earned on sale ' . $sale->sale_number,
            'sale',
            $saleId,
            $expiresAt
        );
    }

    // ── Redeem at checkout ────────────────────────────────────────────────────

    /**
     * Validate and deduct points for a sale.
     * Returns the Rs value of the redemption.
     */
    public function redeemAtCheckout(int $customerId, float $pointsToRedeem, int $saleId): float
    {
        return DB::transaction(function () use ($customerId, $pointsToRedeem, $saleId) {
            $customer = Customer::lockForUpdate()->findOrFail($customerId);
            $settings = $this->settings();

            if (! $settings->is_enabled) {
                throw new \RuntimeException('Loyalty program is not enabled.');
            }

            if ((float) $customer->loyalty_points_balance < $settings->minimum_points_to_redeem) {
                throw new \RuntimeException(
                    "Minimum {$settings->minimum_points_to_redeem} points required to redeem."
                );
            }

            if ((float) $customer->loyalty_points_balance < $pointsToRedeem) {
                throw new \RuntimeException(
                    "Insufficient points. Balance: {$customer->loyalty_points_balance}"
                );
            }

            $rsValue = round($pointsToRedeem * (float) $settings->redemption_value, 2);

            $this->addPoints(
                $customerId,
                -$pointsToRedeem,
                'redeem',
                "Redeemed {$pointsToRedeem} pts = {$rsValue} on sale #{$saleId}",
                'sale',
                $saleId
            );

            return $rsValue;
        });
    }

    // ── Reverse earn (on return) ──────────────────────────────────────────────

    public function reverseEarn(int $saleId): ?LoyaltyTransaction
    {
        $earn = LoyaltyTransaction::where('type', 'earn')
            ->where('reference_type', 'sale')
            ->where('reference_id', $saleId)
            ->first();

        if (! $earn) return null;

        // Only reverse what hasn't already been reversed
        $alreadyReversed = LoyaltyTransaction::where('type', 'return_reversal')
            ->where('reference_id', $saleId)
            ->sum('points'); // negative value

        $remainingToReverse = (float) $earn->points + (float) $alreadyReversed; // what's left

        if ($remainingToReverse <= 0) return null;

        return $this->addPoints(
            $earn->customer_id,
            -$remainingToReverse,
            'return_reversal',
            "Points reversed for returned sale #{$saleId}",
            'sale',
            $saleId
        );
    }

    // ── Manual adjust ─────────────────────────────────────────────────────────

    public function manualAdjust(int $customerId, float $points, string $reason): LoyaltyTransaction
    {
        $type = $points >= 0 ? 'adjust_add' : 'adjust_deduct';
        return $this->addPoints($customerId, $points, $type, $reason, 'manual_adjustment');
    }

    // ── Bonuses ───────────────────────────────────────────────────────────────

    public function applyWelcomeBonus(int $customerId): ?LoyaltyTransaction
    {
        $settings = $this->settings();
        if (! $settings->is_enabled || $settings->welcome_bonus_points <= 0) return null;

        // Only once — check no prior welcome bonus
        $exists = LoyaltyTransaction::where('customer_id', $customerId)
            ->where('type', 'welcome_bonus')
            ->exists();
        if ($exists) return null;

        return $this->addPoints(
            $customerId,
            (float) $settings->welcome_bonus_points,
            'welcome_bonus',
            'Welcome bonus points'
        );
    }

    public function applyBirthdayBonus(int $customerId): ?LoyaltyTransaction
    {
        $settings = $this->settings();
        if (! $settings->is_enabled || $settings->birthday_bonus_points <= 0) return null;

        // One per year — check not applied this year
        $exists = LoyaltyTransaction::where('customer_id', $customerId)
            ->where('type', 'birthday_bonus')
            ->whereYear('created_at', now()->year)
            ->exists();
        if ($exists) return null;

        return $this->addPoints(
            $customerId,
            (float) $settings->birthday_bonus_points,
            'birthday_bonus',
            'Birthday bonus points'
        );
    }

    public function applyReferralBonus(int $referrerCustomerId, int $refereeFirstSaleId): ?LoyaltyTransaction
    {
        $settings = $this->settings();
        if (! $settings->is_enabled || $settings->referral_bonus_points <= 0) return null;

        return $this->addPoints(
            $referrerCustomerId,
            (float) $settings->referral_bonus_points,
            'referral_bonus',
            'Referral bonus — referred customer made first purchase',
            'sale',
            $refereeFirstSaleId
        );
    }

    // ── Expiry ────────────────────────────────────────────────────────────────

    /**
     * Expire all points whose expires_at has passed.
     * Run from the loyalty:expire-points scheduled command.
     */
    public function expirePoints(): int
    {
        $expired = 0;

        $expiringEarns = LoyaltyTransaction::whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->whereIn('type', ['earn', 'welcome_bonus', 'birthday_bonus', 'referral_bonus', 'adjust_add'])
            ->where('points', '>', 0)
            ->get();

        foreach ($expiringEarns as $earn) {
            // Check if already reversed
            $alreadyReversed = LoyaltyTransaction::where('type', 'expire')
                ->where('reference_id', $earn->id)
                ->exists();

            if ($alreadyReversed) continue;

            // Only expire what the customer still has
            $customer = Customer::find($earn->customer_id);
            if (! $customer) continue;

            $pointsToExpire = min((float) $earn->points, (float) $customer->loyalty_points_balance);
            if ($pointsToExpire <= 0) continue;

            $this->addPoints(
                $earn->customer_id,
                -$pointsToExpire,
                'expire',
                'Points expired',
                'loyalty_transaction',
                $earn->id
            );

            $expired++;
        }

        return $expired;
    }

    // ── Internal helper ───────────────────────────────────────────────────────

    private function addPoints(
        int $customerId,
        float $points,
        string $type,
        string $description,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?\DateTimeInterface $expiresAt = null
    ): LoyaltyTransaction {
        return DB::transaction(function () use (
            $customerId, $points, $type, $description,
            $referenceType, $referenceId, $expiresAt
        ) {
            $customer = Customer::lockForUpdate()->findOrFail($customerId);
            $newBalance = max(0, (float) $customer->loyalty_points_balance + $points);
            $customer->update(['loyalty_points_balance' => $newBalance]);

            return LoyaltyTransaction::create([
                'customer_id'    => $customerId,
                'type'           => $type,
                'points'         => $points,
                'balance_after'  => $newBalance,
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
                'description'    => $description,
                'expires_at'     => $expiresAt,
                'created_by'     => auth()->id(),
            ]);
        });
    }
}
