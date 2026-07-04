<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreInventorySnapshot extends Model
{
    protected $connection = 'mysql';

    protected $fillable = [
        'store_id', 'store_name',
        'location_type', 'location_id', 'location_name',
        'product_sku', 'product_name',
        'quantity', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity'  => 'decimal:3',
            'synced_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
