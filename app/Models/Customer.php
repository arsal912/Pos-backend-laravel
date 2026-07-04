<?php

namespace App\Models;

use App\Traits\HasCreatedUpdatedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Customer extends Model
{
    use SoftDeletes, HasCreatedUpdatedBy;

    protected $fillable = [
        // Phase 4B
        'code', 'name', 'email', 'phone', 'company', 'tax_number',
        'billing_address', 'shipping_address', 'city', 'country',
        'date_of_birth', 'gender', 'opening_balance', 'credit_limit',
        'notes', 'is_active', 'created_by', 'updated_by',
        // Phase 4C
        'customer_group_id', 'loyalty_points_balance', 'lifetime_value',
        'outstanding_balance', 'last_purchase_at', 'total_purchases_count',
        'sms_marketing_opted_in', 'email_marketing_opted_in',
        'whatsapp_marketing_opted_in', 'referral_code',
        'referred_by_customer_id', 'tags',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth'               => 'date',
            'opening_balance'             => 'decimal:2',
            'credit_limit'                => 'decimal:2',
            'is_active'                   => 'boolean',
            'loyalty_points_balance'      => 'decimal:2',
            'lifetime_value'              => 'decimal:2',
            'outstanding_balance'         => 'decimal:2',
            'last_purchase_at'            => 'datetime',
            'total_purchases_count'       => 'integer',
            'sms_marketing_opted_in'      => 'boolean',
            'email_marketing_opted_in'    => 'boolean',
            'whatsapp_marketing_opted_in' => 'boolean',
            'tags'                        => 'array',
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'referred_by_customer_id');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(Customer::class, 'referred_by_customer_id');
    }

    public function loyaltyTransactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class)->latest();
    }

    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class)->latest();
    }

    public function customerNotes(): HasMany
    {
        return $this->hasMany(CustomerNote::class)
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at');
    }

    public function communications(): HasMany
    {
        return $this->hasMany(CommunicationLog::class)->latest();
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch($query, string $term)
    {
        if (mb_strlen($term) >= 3) {
            return $query->whereRaw(
                'MATCH(name, phone, email) AGAINST(? IN BOOLEAN MODE)',
                [$term . '*']
            );
        }

        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
              ->orWhere('phone', 'like', "%{$term}%")
              ->orWhere('email', 'like', "%{$term}%")
              ->orWhere('code', 'like', "%{$term}%");
        });
    }

    public function scopeHasCredit($query)
    {
        return $query->where('outstanding_balance', '>', 0);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public static function generateCode(): string
    {
        $count = static::withTrashed()->count() + 1;
        return sprintf('CUS-%06d', $count);
    }

    public static function generateReferralCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (static::where('referral_code', $code)->exists());

        return $code;
    }

    /** Price to charge this customer for a given product/variant (respects group pricing). */
    public function priceFor(Product $product, ?ProductVariant $variant = null): float
    {
        if ($this->customer_group_id) {
            $groupPrice = ProductGroupPrice::where('product_id', $product->id)
                ->where('variant_id', $variant?->id)
                ->where('customer_group_id', $this->customer_group_id)
                ->value('price');

            if ($groupPrice !== null) {
                return (float) $groupPrice;
            }
        }

        $base = $variant ? (float) $variant->selling_price : (float) $product->selling_price;

        if ($this->customer_group_id && $this->relationLoaded('group') && $this->group?->default_discount_percent) {
            $base = $base * (1 - (float) $this->group->default_discount_percent / 100);
        }

        return round($base, 2);
    }

    /** Whether this customer can take on more credit. */
    public function canTakeCredit(float $amount): bool
    {
        if ($this->credit_limit === null) return true;
        return ((float) $this->outstanding_balance + $amount) <= (float) $this->credit_limit;
    }

    /**
     * Recalculate and persist denormalized purchase stats from the sales table.
     * Call this after any sale is completed, voided, or returned.
     *
     * Stats updated: total_purchases_count, lifetime_value, last_purchase_at
     */
    public static function updatePurchaseStats(int $customerId): void
    {
        $stats = Sale::where('customer_id', $customerId)
            ->where('status', 'completed')
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total), 0) as ltv, MAX(sale_date) as last_at')
            ->first();

        static::where('id', $customerId)->update([
            'total_purchases_count' => (int)   ($stats?->cnt    ?? 0),
            'lifetime_value'        => (float) ($stats?->ltv    ?? 0),
            'last_purchase_at'      => $stats?->last_at          ?? null,
        ]);
    }

    /**
     * Bulk-recalculate stats for all customers in the current tenant.
     * Used by the nightly scheduled command and FullDemoSeeder.
     */
    public static function recalculateAllStats(): int
    {
        $updated = 0;
        static::where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->each(function (self $customer) use (&$updated) {
                static::updatePurchaseStats($customer->id);
                $updated++;
            });
        return $updated;
    }

    /** Maximum redeemable Rs value from loyalty points for a given sale total. */
    public function maxRedeemableValue(float $saleTotal, LoyaltySettings $settings): float
    {
        $balance   = (float) $this->loyalty_points_balance;
        $rsValue   = $balance * (float) $settings->redemption_value;
        $minPoints = (float) $settings->minimum_points_to_redeem;

        if ($balance < $minPoints) return 0;

        if ($settings->maximum_redemption_per_sale) {
            $cap = $saleTotal * ((float) $settings->maximum_redemption_per_sale / 100);
            return min($rsValue, $cap);
        }

        return $rsValue;
    }
}
