<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InterStoreTransferRequest extends Model
{
    protected $connection = 'mysql';

    protected $fillable = [
        'requesting_store_id', 'requesting_store_name', 'requesting_user_id',
        'source_store_id',     'source_store_name',
        'source_location_type', 'source_location_id', 'source_location_name',
        'product_sku', 'product_name',
        'quantity_requested', 'quantity_fulfilled',
        'status', 'request_notes', 'response_notes',
        'actioned_by_user_id', 'actioned_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity_requested' => 'decimal:3',
            'quantity_fulfilled' => 'decimal:3',
            'actioned_at'        => 'datetime',
        ];
    }

    public function requestingStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'requesting_store_id');
    }

    public function sourceStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'source_store_id');
    }

    public function requestingUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requesting_user_id');
    }

    public function actionedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actioned_by_user_id');
    }
}
