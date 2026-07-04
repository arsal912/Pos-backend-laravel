<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'product_id', 'name', 'sku', 'barcode', 'attributes',
        'cost_price', 'selling_price', 'image', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'attributes'    => 'array',
            'cost_price'    => 'decimal:2',
            'selling_price' => 'decimal:2',
            'is_active'     => 'boolean',
            'sort_order'    => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class, 'variant_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class, 'variant_id');
    }

    public function totalStock(): float
    {
        return (float) $this->inventoryItems()->sum('quantity');
    }

    public function stockForBranch(int $branchId): float
    {
        return (float) $this->inventoryItems()
            ->where('branch_id', $branchId)
            ->value('quantity') ?? 0;
    }
}
