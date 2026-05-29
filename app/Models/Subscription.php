<?php

namespace App\Models;

use App\Models\PaymentEvent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory;

    protected $connection = 'mysql';

    protected $fillable = [
        'store_id',
        'plan_id',
        'status',          // active, expired, cancelled, pending
        'starts_at',
        'ends_at',
        'cancelled_at',
        'grace_period_ends_at',
        'next_billing_at',
        'auto_renew',
        'gateway_customer_id',
        'payment_gateway',
        'gateway_subscription_id',
        'amount',
        'currency',
        'billing_cycle',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'grace_period_ends_at' => 'datetime',
            'next_billing_at' => 'datetime',
            'amount' => 'decimal:2',
            'auto_renew' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->ends_at?->isFuture();
    }
}
