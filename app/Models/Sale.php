<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'sale_number', 'branch_id', 'customer_id', 'cashier_id', 'sale_date',
        'subtotal', 'tax_amount', 'discount_amount', 'discount_type',
        'discount_reason', 'total', 'paid_amount', 'change_given', 'balance',
        'status', 'payment_status', 'notes',
        // Phase 6 — offline sync fields
        'offline_reference', 'synced_from_device_id', 'synced_at',
        'has_stock_conflict', 'has_credit_conflict',
    ];

    protected function casts(): array
    {
        return [
            'sale_date'       => 'date',
            'subtotal'        => 'decimal:2',
            'tax_amount'      => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total'           => 'decimal:2',
            'paid_amount'     => 'decimal:2',
            'change_given'    => 'decimal:2',
            'balance'         => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class, 'original_sale_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /** Recalculate totals from items + sale-level discount */
    public function recalculate(): void
    {
        $subtotal   = 0;
        $taxAmount  = 0;

        foreach ($this->items as $item) {
            $subtotal  += (float) $item->line_total;
            $taxAmount += (float) $item->tax_amount;
        }

        $discountAmt = 0;
        if ($this->discount_type === 'percent' && $this->discount_amount > 0) {
            $discountAmt = $subtotal * ((float) $this->discount_amount / 100);
        } elseif ($this->discount_type === 'fixed') {
            $discountAmt = (float) $this->discount_amount;
        }

        $total = $subtotal - $discountAmt;

        $this->update([
            'subtotal'        => $subtotal,
            'tax_amount'      => $taxAmount,
            'discount_amount' => $discountAmt,
            'total'           => $total,
        ]);
    }

    public static function generateNumber(): string
    {
        $year  = now()->year;
        $count = static::whereYear('created_at', $year)->withTrashed()->count() + 1;
        return sprintf('S-%s-%08d', $year, $count);
    }
}
