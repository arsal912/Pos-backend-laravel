<?php

namespace App\Models;

use App\Models\PaymentEvent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasFactory;

    protected $connection = 'mysql';

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
        'failure_reason',
        'retry_count',
        'last_retry_at',
        'refunded_at',
        'refund_amount',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'gateway_response' => 'array',
            'paid_at' => 'datetime',
            'last_retry_at' => 'datetime',
            'refunded_at' => 'datetime',
            'amount' => 'decimal:2',
            'refund_amount' => 'decimal:2',
            'retry_count' => 'integer',
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

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }
}
