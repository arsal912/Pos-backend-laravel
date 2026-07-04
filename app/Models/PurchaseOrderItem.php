<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id', 'product_id', 'variant_id',
        'quantity_ordered', 'quantity_received',
        'unit_cost', 'tax_rate', 'discount', 'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'quantity_ordered'  => 'decimal:3',
            'quantity_received' => 'decimal:3',
            'unit_cost'         => 'decimal:2',
            'tax_rate'          => 'decimal:2',
            'discount'          => 'decimal:2',
            'subtotal'          => 'decimal:2',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
