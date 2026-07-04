<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerGroup extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'description',
        'default_discount_percent', 'earns_loyalty_points',
        'is_default', 'color', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'default_discount_percent' => 'decimal:2',
            'earns_loyalty_points'     => 'boolean',
            'is_default'               => 'boolean',
            'is_active'                => 'boolean',
            'sort_order'               => 'integer',
        ];
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function groupPrices(): HasMany
    {
        return $this->hasMany(ProductGroupPrice::class);
    }

    /** Ensure only one default group exists */
    public function setAsDefault(): void
    {
        static::where('id', '!=', $this->id)->update(['is_default' => false]);
        $this->update(['is_default' => true]);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }
}
