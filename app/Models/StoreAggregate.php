<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreAggregate extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'tenant_database',
        'branches_count',
        'subscriptions_count',
        'payments_count',
        'active_users_count',
        'total_revenue',
        'month_revenue',
        'today_revenue',
        'last_payment_at',
        'last_synced_at',
        'meta',
    ];

    protected $casts = [
        'branches_count' => 'integer',
        'subscriptions_count' => 'integer',
        'payments_count' => 'integer',
        'active_users_count' => 'integer',
        'total_revenue' => 'decimal:2',
        'month_revenue' => 'decimal:2',
        'today_revenue' => 'decimal:2',
        'last_payment_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'meta' => 'array',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
