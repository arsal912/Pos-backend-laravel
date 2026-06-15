<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Custom Role model — always uses the CENTRAL database connection.
 * Extended with store_id for per-store custom roles.
 *
 * store_id = NULL  → system/predefined role (shared across all stores)
 * store_id = X     → custom role created by store X
 * is_system = true → cannot be deleted
 */
class Role extends SpatieRole
{
    protected $connection = 'mysql'; // always central DB

    protected $fillable = [
        'name', 'guard_name', 'store_id', 'is_system', 'description', 'color',
    ];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    /** Scope to roles visible to a specific store (system + store-specific). */
    public function scopeForStore($query, int $storeId)
    {
        return $query->where(fn ($q) =>
            $q->whereNull('store_id')->orWhere('store_id', $storeId)
        );
    }

    public function isCustom(): bool
    {
        return ! $this->is_system && $this->store_id !== null;
    }
}
