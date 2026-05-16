<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Module;
use App\Models\Store;
use App\Models\StoreModule;
use App\Models\User;
use App\Models\UserModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ModuleController extends Controller
{
    use ApiResponse;

    /**
     * List all modules grouped by category.
     */
    public function index(): JsonResponse
    {
        $modules = Module::orderBy('category')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->groupBy('category');

        return $this->successResponse($modules);
    }

    /**
     * Get permission matrix for a specific store (all modules + their status).
     */
    public function getStoreModules(int $storeId): JsonResponse
    {
        $store = Store::with(['activeSubscription.plan.modules'])->findOrFail($storeId);

        $allModules = Module::orderBy('category')->orderBy('sort_order')->get();
        $storeModules = StoreModule::where('store_id', $storeId)->get()->keyBy('module_id');
        $planModuleIds = $store->activeSubscription?->plan?->modules->pluck('id')->toArray() ?? [];

        $matrix = $allModules->map(function ($module) use ($storeModules, $planModuleIds) {
            $storeOverride = $storeModules->get($module->id);

            return [
                'id' => $module->id,
                'name' => $module->name,
                'slug' => $module->slug,
                'category' => $module->category,
                'icon' => $module->icon,
                'description' => $module->description,
                'is_core' => $module->is_core,
                'in_plan' => in_array($module->id, $planModuleIds),
                'has_store_override' => $storeOverride !== null,
                'is_enabled' => $storeOverride
                    ? (bool) $storeOverride->is_enabled
                    : in_array($module->id, $planModuleIds),
                'notes' => $storeOverride?->notes,
            ];
        });

        return $this->successResponse([
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'plan' => $store->activeSubscription?->plan?->name,
            ],
            'modules' => $matrix->groupBy('category'),
        ]);
    }

    /**
     * Update / toggle a module for a specific store.
     */
    public function updateStoreModule(Request $request, int $storeId, int $moduleId): JsonResponse
    {
        $validated = $request->validate([
            'is_enabled' => 'required|boolean',
            'notes' => 'nullable|string',
        ]);

        $store = Store::findOrFail($storeId);
        $module = Module::findOrFail($moduleId);

        if ($module->is_core && !$validated['is_enabled']) {
            return $this->errorResponse(
                "Module '{$module->name}' is a core module and cannot be disabled.",
                422
            );
        }

        $storeModule = StoreModule::updateOrCreate(
            ['store_id' => $store->id, 'module_id' => $module->id],
            [
                'is_enabled' => $validated['is_enabled'],
                'notes' => $validated['notes'] ?? null,
                'enabled_by' => $request->user()->id,
            ]
        );

        $this->clearCacheForStore($store->id);

        return $this->successResponse(
            $storeModule,
            "Module '{$module->name}' " . ($validated['is_enabled'] ? 'enabled' : 'disabled') . " for {$store->name}"
        );
    }

    /**
     * Bulk update store modules.
     */
    public function bulkUpdateStoreModules(Request $request, int $storeId): JsonResponse
    {
        $validated = $request->validate([
            'modules' => 'required|array',
            'modules.*.module_id' => 'required|exists:modules,id',
            'modules.*.is_enabled' => 'required|boolean',
        ]);

        $store = Store::findOrFail($storeId);

        foreach ($validated['modules'] as $item) {
            $module = Module::find($item['module_id']);
            if ($module->is_core && !$item['is_enabled']) {
                continue; // skip core modules
            }

            StoreModule::updateOrCreate(
                ['store_id' => $store->id, 'module_id' => $item['module_id']],
                [
                    'is_enabled' => $item['is_enabled'],
                    'enabled_by' => $request->user()->id,
                ]
            );
        }

        $this->clearCacheForStore($store->id);

        return $this->successResponse(null, 'Modules updated for store');
    }

    /**
     * Get permission matrix for a specific user.
     */
    public function getUserModules(int $userId): JsonResponse
    {
        $user = User::with('store')->findOrFail($userId);

        if (!$user->store_id) {
            return $this->errorResponse('User has no store.', 422);
        }

        $allModules = Module::orderBy('category')->orderBy('sort_order')->get();
        $userModules = UserModule::where('user_id', $userId)->get()->keyBy('module_id');
        $storeModules = StoreModule::where('store_id', $user->store_id)->get()->keyBy('module_id');
        $planModuleIds = $user->store->activeSubscription?->plan?->modules->pluck('id')->toArray() ?? [];

        $matrix = $allModules->map(function ($module) use ($userModules, $storeModules, $planModuleIds) {
            $userOverride = $userModules->get($module->id);
            $storeOverride = $storeModules->get($module->id);

            // Determine effective access
            $storeEnabled = $storeOverride
                ? (bool) $storeOverride->is_enabled
                : in_array($module->id, $planModuleIds);

            $effectiveEnabled = $userOverride
                ? (bool) $userOverride->is_enabled
                : $storeEnabled;

            return [
                'id' => $module->id,
                'name' => $module->name,
                'slug' => $module->slug,
                'category' => $module->category,
                'icon' => $module->icon,
                'is_core' => $module->is_core,
                'store_enabled' => $storeEnabled,
                'has_user_override' => $userOverride !== null,
                'is_enabled' => $effectiveEnabled,
                'notes' => $userOverride?->notes,
            ];
        });

        return $this->successResponse([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'store_name' => $user->store?->name,
            ],
            'modules' => $matrix->groupBy('category'),
        ]);
    }

    /**
     * Toggle a module for a specific user (override store-level).
     */
    public function updateUserModule(Request $request, int $userId, int $moduleId): JsonResponse
    {
        $validated = $request->validate([
            'is_enabled' => 'required|boolean',
            'notes' => 'nullable|string',
        ]);

        $user = User::findOrFail($userId);
        $module = Module::findOrFail($moduleId);

        if ($module->is_core && !$validated['is_enabled']) {
            return $this->errorResponse(
                "Module '{$module->name}' is a core module and cannot be disabled.",
                422
            );
        }

        $userModule = UserModule::updateOrCreate(
            ['user_id' => $user->id, 'module_id' => $module->id],
            [
                'is_enabled' => $validated['is_enabled'],
                'notes' => $validated['notes'] ?? null,
                'overridden_by' => $request->user()->id,
            ]
        );

        return $this->successResponse(
            $userModule,
            "Module '{$module->name}' " . ($validated['is_enabled'] ? 'enabled' : 'disabled') . " for {$user->name}"
        );
    }

    /**
     * Remove user override (revert to store-level setting).
     */
    public function removeUserModuleOverride(int $userId, int $moduleId): JsonResponse
    {
        UserModule::where('user_id', $userId)
            ->where('module_id', $moduleId)
            ->delete();

        return $this->successResponse(null, 'User override removed; reverted to store setting');
    }

    protected function clearCacheForStore(int $storeId): void
    {
        Cache::forget("store_modules_{$storeId}");
    }
}
