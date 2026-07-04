<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'customer_id', 'type', 'amount', 'balance_after',
        'payment_method', 'reference_type', 'reference_id',
        'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount'       => 'decimal:2',
            'balance_after'=> 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isPayment(): bool
    {
        return $this->type === 'payment_received';
    }

    public function isCharge(): bool
    {
        return $this->type === 'sale_on_credit';
    }
}
