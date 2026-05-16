<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'subscription_id',
        'amount',
        'currency',
        'gateway',         // stripe, paypal, jazzcash, easypaisa, manual
        'gateway_payment_id',
        'gateway_response', // json
        'status',          // pending, completed, failed, refunded
        'paid_at',
        'invoice_number',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'gateway_response' => 'array',
            'paid_at' => 'datetime',
            'amount' => 'decimal:2',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
