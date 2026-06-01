<?php

namespace App\Models;

use App\Traits\HasCreatedUpdatedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    use SoftDeletes, HasCreatedUpdatedBy;

    protected $fillable = [
        'category_id', 'brand_id', 'name', 'slug', 'sku', 'barcode',
        'description', 'image', 'gallery', 'type', 'unit_id',
        'cost_price', 'selling_price', 'msrp', 'tax_rate_id',
        'track_stock', 'allow_negative_stock', 'low_stock_threshold',
        'loyalty_points_multiplier',
        'is_active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'gallery'              => 'array',
            'track_stock'          => 'boolean',
            'allow_negative_stock' => 'boolean',
            'is_active'                   => 'boolean',
            'loyalty_points_multiplier'   => 'decimal:2',
            'cost_price'           => 'decimal:2',
            'selling_price'        => 'decimal:2',
            'msrp'                 => 'decimal:2',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order');
    }

    public function activeVariants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->where('is_active', true)->orderBy('sort_order');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function primaryImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->where('is_primary', true);
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch($query, string $term)
    {
        return $query->whereRaw(
            'MATCH(name, sku, barcode) AGAINST(? IN BOOLEAN MODE)',
            [$term . '*']
        );
    }

    public function isSimple(): bool
    {
        return $this->type === 'simple';
    }

    public function isVariable(): bool
    {
        return $this->type === 'variable';
    }

    /**
     * Total stock across all branches for this product.
     */
    public function totalStock(): float
    {
        if ($this->isVariable()) {
            return (float) InventoryItem::where('product_id', $this->id)
                ->whereNotNull('variant_id')
                ->sum('quantity');
        }

        return (float) InventoryItem::where('product_id', $this->id)
            ->whereNull('variant_id')
            ->sum('quantity');
    }
}
