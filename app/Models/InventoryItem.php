<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryItem extends Model
{
    protected $fillable = [
        'product_id', 'variant_id', 'branch_id', 'warehouse_id',
        'quantity', 'reserved_quantity', 'last_counted_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity'          => 'decimal:3',
            'reserved_quantity' => 'decimal:3',
            'last_counted_at'   => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** Available = quantity − reserved */
    public function getAvailableAttribute(): float
    {
        return max(0, (float) $this->quantity - (float) $this->reserved_quantity);
    }
}
