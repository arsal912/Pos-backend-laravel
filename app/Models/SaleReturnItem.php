<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleReturnItem extends Model
{
    protected $fillable = [
        'sale_return_id', 'sale_item_id', 'product_id', 'variant_id',
        'quantity_returned', 'unit_price', 'refund_amount', 'restock',
    ];

    protected function casts(): array
    {
        return [
            'quantity_returned' => 'decimal:3',
            'unit_price'        => 'decimal:2',
            'refund_amount'     => 'decimal:2',
            'restock'           => 'boolean',
        ];
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }
}
