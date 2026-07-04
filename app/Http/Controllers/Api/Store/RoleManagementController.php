<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Store-level role management.
 * Allows store owners to view system roles, create custom roles,
 * and assign permissions to any role visible to their store.
 */
class RoleManagementController extends Controller
{
    use ApiResponse;

    private const PERMISSION_GROUPS = [
        'POS & Sales'     => ['create-sales','view-sales','refund-sales','edit-sale-price'],
        'Products'        => ['view-products','create-products','edit-products','delete-products'],
        'Inventory'       => ['view-inventory','manage-inventory','transfer-stock'],
        'Customers'       => ['view-customers','manage-customers','manage-customer-groups','manage-customer-credit','import-customers'],
        'Reports'         => ['view-reports','export-reports','view-profit-loss','view-staff-reports'],
        'Finance'         => ['manage-expenses'],
        'Loyalty'         => ['view-loyalty','manage-loyalty'],
        'Suppliers'       => ['view-suppliers','manage-suppliers'],
        'Communications'  => ['send-customer-communication'],
        'Staff & Users'   => ['view-users','manage-users'],
        'Settings'        => ['manage-settings','manage-branches'],
        'Devices'         => ['manage-pos-devices'],
    ];

    // ── List roles visible to this store ────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->can('manage-users')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $storeId = auth()->user()->store_id;

        $roles = Role::where('guard_name', 'sanctum')
            ->forStore($storeId)
            ->withCount('permissions')
            ->get()
            ->map(fn ($r) => [
                'id'               => $r->id,
                'name'             => $r->name,
                'description'      => $r->description,
                'color'            => $r->color ?? '#6366f1',
                'is_system'        => $r->is_system,
                'is_custom'        => $r->isCustom(),
                'store_id'         => $r->store_id,
                'permissions_count'=> $r->permissions_count,
                'permissions'      => $r->permissions->pluck('name'),
            ]);

        return $this->successResponse([
            'roles'             => $roles,
            'permission_groups' => self::PERMISSION_GROUPS,
        ]);
    }

    // ── Get role with full permission detail ─────────────────────────────────

    public function show(Request $request, int $id): JsonResponse
    {
        $role = $this->getStoreRole($id);
        if (! $role) return $this->errorResponse('Role not found.', 404);

        return $this->successResponse([
            'role'              => array_merge($role->toArray(), [
                'permissions' => $role->permissions->pluck('name'),
            ]),
            'permission_groups' => self::PERMISSION_GROUPS,
        ]);
    }

    // ── Create custom role ───────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->can('manage-users')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'name'        => 'required|string|max:50',
            'description' => 'nullable|string|max:200',
            'color'       => 'nullable|string|max:20',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        $storeId = auth()->user()->store_id;
        $slug    = \Illuminate\Support\Str::slug($validated['name'], '-');

        // Unique within store (and no clash with system role names)
        $exists = Role::where('guard_name', 'sanctum')
            ->where('name', $slug)
            ->exists();
        if ($exists) {
            return $this->errorResponse("A role named '{$slug}' already exists.", 422);
        }

        // Create for both guards
        foreach (['sanctum', 'web'] as $guard) {
            $role = Role::create([
                'name'        => $slug,
                'guard_name'  => $guard,
                'store_id'    => $storeId,
                'is_system'   => false,
                'description' => $validated['description'] ?? null,
                'color'       => $validated['color'] ?? '#6366f1',
            ]);

            if ($guard === 'sanctum' && ! empty($validated['permissions'])) {
                $perms = Permission::whereIn('name', $validated['permissions'])
                    ->where('guard_name', 'sanctum')
                    ->get();
                $role->syncPermissions($perms);
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return $this->successResponse(
            Role::where('name', $slug)->where('guard_name', 'sanctum')->first(),
            'Role created.',
            201
        );
    }

    // ── Update role ──────────────────────────────────────────────────────────

    public function update(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('manage-users')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $role = $this->getStoreRole($id);
        if (! $role) return $this->errorResponse('Role not found.', 404);

        $validated = $request->validate([
            'name'        => 'sometimes|string|max:50',
            'description' => 'nullable|string|max:200',
            'color'       => 'nullable|string|max:20',
        ]);

        // System roles: only allow description/color update, not name
        if ($role->is_system) {
            unset($validated['name']);
        }

        // Update both guards (web + sanctum)
        Role::where('name', $role->name)
            ->whereIn('guard_name', ['sanctum', 'web'])
            ->where(fn ($q) => $q->whereNull('store_id')->orWhere('store_id', auth()->user()->store_id))
            ->update(array_filter($validated, fn ($v) => $v !== null));

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return $this->successResponse($role->fresh(), 'Role updated.');
    }

    // ── Delete custom role ───────────────────────────────────────────────────

    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('manage-users')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $role = $this->getStoreRole($id);
        if (! $role) return $this->errorResponse('Role not found.', 404);

        if ($role->is_system) {
            return $this->errorResponse('System roles cannot be deleted. You can edit their permissions.', 422);
        }

        if ($role->store_id !== auth()->user()->store_id) {
            return $this->errorResponse('You can only delete roles you created.', 403);
        }

        // Remove from both guards
        Role::where('name', $role->name)
            ->where('store_id', auth()->user()->store_id)
            ->get()
            ->each->delete();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return $this->successResponse(null, 'Role deleted.');
    }

    // ── Sync permissions on a role ────────────────────────────────────────────

    public function syncPermissions(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('manage-users')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $role = $this->getStoreRole($id);
        if (! $role) return $this->errorResponse('Role not found.', 404);

        $validated = $request->validate([
            'permissions'   => 'required|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        // Sync for sanctum guard
        $perms = Permission::whereIn('name', $validated['permissions'])
            ->where('guard_name', 'sanctum')
            ->get();
        $role->syncPermissions($perms);

        // Sync for web guard too
        $roleWeb = Role::where('name', $role->name)
            ->where('guard_name', 'web')
            ->first();
        if ($roleWeb) {
            $webPerms = Permission::whereIn('name', $validated['permissions'])
                ->where('guard_name', 'web')
                ->get();
            $roleWeb->syncPermissions($webPerms);
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return $this->successResponse([
            'role'        => $role->name,
            'permissions' => $validated['permissions'],
        ], 'Permissions updated.');
    }

    // ── Get all permissions (grouped) ─────────────────────────────────────────

    public function permissions(Request $request): JsonResponse
    {
        $all = Permission::where('guard_name', 'sanctum')
            ->orderBy('name')
            ->pluck('name')
            ->toArray();

        $grouped = [];
        foreach (self::PERMISSION_GROUPS as $group => $groupPerms) {
            $grouped[$group] = array_values(array_intersect($all, $groupPerms));
        }

        // Ungrouped permissions
        $usedPerms  = array_merge(...array_values(self::PERMISSION_GROUPS));
        $ungrouped  = array_diff($all, $usedPerms);
        if (! empty($ungrouped)) {
            $grouped['Other'] = array_values($ungrouped);
        }

        return $this->successResponse(['permission_groups' => $grouped]);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function getStoreRole(int $id): ?Role
    {
        $storeId = auth()->user()->store_id;
        return Role::where('id', $id)
            ->where('guard_name', 'sanctum')
            ->where(fn ($q) => $q->whereNull('store_id')->orWhere('store_id', $storeId))
            ->with('permissions:id,name')
            ->first();
    }
}
