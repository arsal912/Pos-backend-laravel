<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    public $timestamps = true;
    public const UPDATED_AT = null; // Stock movements are immutable

    protected $fillable = [
        'product_id', 'variant_id', 'branch_id', 'type',
        'reference_type', 'reference_id',
        'quantity', 'cost_at_time', 'balance_after',
        'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity'     => 'decimal:3',
            'cost_at_time' => 'decimal:2',
            'balance_after'=> 'decimal:3',
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
}
