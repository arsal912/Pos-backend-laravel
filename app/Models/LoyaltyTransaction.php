<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyTransaction extends Model
{
    public const UPDATED_AT = null; // immutable audit record

    protected $fillable = [
        'customer_id', 'type', 'points', 'balance_after',
        'reference_type', 'reference_id', 'description',
        'expires_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'points'       => 'decimal:2',
            'balance_after'=> 'decimal:2',
            'expires_at'   => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopeEarned($query)
    {
        return $query->whereIn('type', [
            'earn', 'welcome_bonus', 'birthday_bonus', 'referral_bonus', 'adjust_add',
        ]);
    }

    public function scopeRedeemed($query)
    {
        return $query->whereIn('type', ['redeem', 'adjust_deduct', 'expire', 'return_reversal']);
    }
}
