<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    protected $fillable = [
        'store_id', 'name', 'code',
        'type', 'address', 'phone', 'manager', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** All branches this warehouse serves (many-to-many via branch_warehouse) */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_warehouse');
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }
}
