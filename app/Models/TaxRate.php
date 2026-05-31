<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaxRate extends Model
{
    protected $fillable = ['name', 'rate', 'is_inclusive', 'is_active'];

    protected function casts(): array
    {
        return [
            'rate'         => 'decimal:2',
            'is_inclusive' => 'boolean',
            'is_active'    => 'boolean',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
