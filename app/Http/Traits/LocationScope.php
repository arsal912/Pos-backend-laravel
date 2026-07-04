<?php

namespace App\Http\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Automatically scopes inventory / transfer queries to the authenticated
 * user's assigned location when they hold a location-bound role:
 *   - branch-manager   → filters to user->branch_id
 *   - warehouse-manager → filters to user->warehouse_id
 *
 * Store owners, store admins and store managers see everything.
 */
trait LocationScope
{
    /**
     * Returns ['type' => 'branch'|'warehouse'|null, 'id' => int|null]
     */
    protected function userLocationScope(Request $request): array
    {
        $user = $request->user();
        if (! $user) return ['type' => null, 'id' => null];
        return $user->locationScope();
    }

    /**
     * Apply scope to an InventoryItem query.
     */
    protected function applyInventoryScope(Builder $query, Request $request): Builder
    {
        $scope = $this->userLocationScope($request);

        if ($scope['type'] === 'branch') {
            $query->where('branch_id', $scope['id'])->whereNull('warehouse_id');
        } elseif ($scope['type'] === 'warehouse') {
            $query->where('warehouse_id', $scope['id']);
        }

        return $query;
    }

    /**
     * Apply scope to a StockTransfer query — only show transfers that
     * involve the manager's branch or warehouse.
     */
    protected function applyTransferScope(Builder $query, Request $request): Builder
    {
        $scope = $this->userLocationScope($request);

        if ($scope['type'] === 'branch') {
            $id = $scope['id'];
            $query->where(fn ($q) => $q
                ->where('from_branch_id', $id)
                ->orWhere('to_branch_id',   $id)
            );
        } elseif ($scope['type'] === 'warehouse') {
            $id = $scope['id'];
            $query->where(fn ($q) => $q
                ->where('from_warehouse_id', $id)
                ->orWhere('to_warehouse_id',   $id)
            );
        }

        return $query;
    }
}
