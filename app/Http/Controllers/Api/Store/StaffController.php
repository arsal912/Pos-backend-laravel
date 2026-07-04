<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Manage store staff members — invite, update, assign roles, deactivate.
 */
class StaffController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->can('view-users')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $storeId = auth()->user()->store_id;

        $staff = User::where('store_id', $storeId)
            ->where('is_super_admin', false)
            ->with(['roles' => fn ($q) => $q->where('guard_name', 'sanctum')->select('id','name','color','is_system')])
            ->orderBy('name')
            ->get()
            ->map(fn ($u) => [
                'id'         => $u->id,
                'name'       => $u->name,
                'email'      => $u->email,
                'phone'      => $u->phone,
                'is_active'  => $u->is_active,
                'branch_id'    => $u->branch_id,
                'warehouse_id' => $u->warehouse_id,
                'last_login_at' => $u->last_login_at,
                'created_at' => $u->created_at,
                'roles'      => $u->roles->map(fn ($r) => [
                    'id'        => $r->id,
                    'name'      => $r->name,
                    'color'     => $r->color ?? '#6366f1',
                    'is_system' => $r->is_system,
                ]),
            ]);

        return $this->successResponse(['staff' => $staff]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->can('manage-users')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $storeId = auth()->user()->store_id;

        $validated = $request->validate([
            'name'      => 'required|string|max:100',
            'email'     => 'required|email|unique:users,email',
            'phone'     => 'nullable|string|max:20',
            'password'  => 'required|string|min:8',
            'role_name'    => 'required|string',
            'branch_id'    => 'nullable|integer',
            'warehouse_id' => 'nullable|integer',
        ]);

        // Verify role exists and is visible to this store
        $role = Role::where('name', $validated['role_name'])
            ->where('guard_name', 'sanctum')
            ->where(fn ($q) => $q->whereNull('store_id')->orWhere('store_id', $storeId))
            ->first();

        if (! $role) {
            return $this->errorResponse("Role '{$validated['role_name']}' not found.", 422);
        }

        // Prevent creating another store-owner (only one owner per store)
        if ($validated['role_name'] === 'store-owner') {
            return $this->errorResponse('Cannot create additional store owners. Use store-admin instead.', 422);
        }

        $user = new User();
        $user->name       = $validated['name'];
        $user->email      = $validated['email'];
        $user->phone      = $validated['phone'] ?? null;
        $user->password   = $validated['password'];
        $user->branch_id    = $validated['branch_id']    ?? null;
        $user->warehouse_id = $validated['warehouse_id'] ?? null;
        $user->is_active  = true;
        $user->is_super_admin = false;
        // store_id excluded from $fillable — set directly
        $user->store_id = $storeId;
        $user->save();

        $user->assignRole($validated['role_name']);

        return $this->successResponse([
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames(),
        ], 'Staff member created.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('manage-users')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $storeId = auth()->user()->store_id;
        $member  = User::where('id', $id)->where('store_id', $storeId)->firstOrFail();

        $validated = $request->validate([
            'name'         => 'sometimes|string|max:100',
            'phone'        => 'nullable|string|max:20',
            'password'     => 'nullable|string|min:8',
            'branch_id'    => 'nullable|integer',
            'warehouse_id' => 'nullable|integer',
            'is_active'    => 'sometimes|boolean',
            'role_name'    => 'sometimes|string',
        ]);

        if (isset($validated['name']))         $member->name         = $validated['name'];
        if (isset($validated['phone']))        $member->phone        = $validated['phone'];
        if (array_key_exists('branch_id', $validated))    $member->branch_id    = $validated['branch_id'];
        if (array_key_exists('warehouse_id', $validated)) $member->warehouse_id = $validated['warehouse_id'];
        if (isset($validated['is_active']))    $member->is_active    = $validated['is_active'];
        if (isset($validated['password']))     $member->password     = $validated['password'];
        $member->save();

        // Update role if provided
        if (isset($validated['role_name'])) {
            $role = Role::where('name', $validated['role_name'])
                ->where('guard_name', 'sanctum')
                ->where(fn ($q) => $q->whereNull('store_id')->orWhere('store_id', $storeId))
                ->first();

            if (! $role) {
                return $this->errorResponse("Role not found.", 422);
            }

            // Can't change store-owner role
            if ($member->hasRole('store-owner') && $validated['role_name'] !== 'store-owner') {
                return $this->errorResponse('Cannot change the store owner\'s role.', 422);
            }

            $member->syncRoles([$validated['role_name']]);
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        }

        return $this->successResponse([
            'id'    => $member->id,
            'name'  => $member->name,
            'roles' => $member->getRoleNames(),
        ], 'Staff member updated.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('manage-users')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $storeId = auth()->user()->store_id;
        $member  = User::where('id', $id)->where('store_id', $storeId)->firstOrFail();

        if ($member->id === auth()->id()) {
            return $this->errorResponse('You cannot deactivate your own account.', 422);
        }

        if ($member->hasRole('store-owner')) {
            return $this->errorResponse('Cannot remove the store owner account.', 422);
        }

        $member->update(['is_active' => false]);
        $member->tokens()->delete(); // Revoke all tokens

        return $this->successResponse(null, 'Staff member deactivated.');
    }
}
