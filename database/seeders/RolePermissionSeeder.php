<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            // Store management
            'view-stores', 'manage-stores',
            // Product
            'view-products', 'create-products', 'edit-products', 'delete-products',
            // Sales
            'create-sales', 'view-sales', 'refund-sales',
            // Inventory
            'view-inventory', 'manage-inventory', 'transfer-stock',
            // Customers
            'view-customers', 'manage-customers',
            // Suppliers
            'view-suppliers', 'manage-suppliers',
            // Reports
            'view-reports', 'export-reports',
            // Users / Staff
            'view-users', 'manage-users',
            // Settings
            'manage-settings',
            // Branches
            'manage-branches',
            // Expenses
            'manage-expenses',
            // Phase 4C — Loyalty
            'view-loyalty', 'manage-loyalty',
            // Phase 4C — Credit
            'manage-customer-credit',
            // Phase 4C — Customer groups
            'manage-customer-groups',
            // Phase 4C — Communications
            'send-customer-communication',
            // Phase 4C — Import
            'import-customers',
            // POS price override
            'edit-sale-price',
            // Phase 6 — PWA device management
            'manage-pos-devices',
            // Phase 4D — Reports
            'view-profit-loss',    // sensitive P&L data
            'view-staff-reports',  // cashier performance
            'view-admin-reports',  // super admin platform reports
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'sanctum']);
        }

        // Roles
        $roles = [
            'super-admin' => $permissions,
            'store-owner' => $permissions,
            'store-manager' => [
                'view-products', 'create-products', 'edit-products',
                'create-sales', 'view-sales', 'refund-sales',
                'view-inventory', 'manage-inventory',
                'view-customers', 'manage-customers',
                'view-suppliers', 'manage-suppliers',
                'view-reports', 'export-reports',
                'manage-expenses',
                // Phase 4C
                'view-loyalty', 'manage-loyalty',
                'manage-customer-credit', 'manage-customer-groups',
                'send-customer-communication', 'import-customers',
                // Phase 4D
                'view-profit-loss', 'view-staff-reports',
                // Phase 6
                'manage-pos-devices',
            ],
            'cashier' => [
                'view-products',
                'create-sales', 'view-sales',
                'view-customers', 'manage-customers',
                // Phase 4C — cashier can see loyalty balance but not adjust
                'view-loyalty',
            ],
            'inventory-staff' => [
                'view-products', 'edit-products',
                'view-inventory', 'manage-inventory', 'transfer-stock',
                'view-suppliers',
            ],
        ];

        // Additional roles — Phase 7 role management module
        $roles['finance'] = [
            'view-reports', 'export-reports', 'view-profit-loss', 'view-staff-reports',
            'manage-expenses', 'view-customers', 'view-sales', 'view-loyalty',
        ];
        $roles['floor-staff'] = [
            'view-products', 'create-sales', 'view-sales', 'view-customers', 'view-loyalty',
        ];
        $roles['store-admin'] = $permissions; // same as store-owner

        // Branch Manager — full operations scoped to their assigned branch
        $roles['branch-manager'] = [
            'view-products', 'create-products', 'edit-products',
            'create-sales', 'view-sales', 'refund-sales',
            'view-inventory', 'manage-inventory', 'transfer-stock',
            'view-customers', 'manage-customers',
            'view-loyalty', 'manage-loyalty',
            'manage-customer-credit',
            'manage-expenses',
            'view-reports',
        ];

        // Warehouse Manager — inventory and logistics scoped to their assigned warehouse
        $roles['warehouse-manager'] = [
            'view-products',
            'view-inventory', 'manage-inventory', 'transfer-stock',
            'view-suppliers', 'manage-suppliers',
            'view-reports',
        ];

        // Role metadata (description + color for UI)
        $roleMeta = [
            'super-admin'       => ['description' => 'Full platform access — super admin only',                           'color' => '#ef4444', 'is_system' => 1],
            'store-owner'       => ['description' => 'Full store access including billing',                               'color' => '#6366f1', 'is_system' => 1],
            'store-admin'       => ['description' => 'Full store access except billing',                                  'color' => '#8b5cf6', 'is_system' => 1],
            'store-manager'     => ['description' => 'Most store features — no billing or role management',               'color' => '#3b82f6', 'is_system' => 1],
            'branch-manager'    => ['description' => 'Full branch operations — scoped to their assigned branch',          'color' => '#0ea5e9', 'is_system' => 1],
            'warehouse-manager' => ['description' => 'Inventory & logistics — scoped to their assigned warehouse',        'color' => '#f97316', 'is_system' => 1],
            'cashier'           => ['description' => 'POS sales, basic customer lookup',                                  'color' => '#10b981', 'is_system' => 1],
            'inventory-staff'   => ['description' => 'Products, inventory, suppliers — no POS sales',                    'color' => '#f59e0b', 'is_system' => 1],
            'finance'           => ['description' => 'Reports and expenses — no POS or product editing',                  'color' => '#06b6d4', 'is_system' => 1],
            'floor-staff'       => ['description' => 'Basic POS only — scan, sell, view customers',                      'color' => '#84cc16', 'is_system' => 1],
        ];

        foreach ($roles as $roleName => $perms) {
            foreach (['web', 'sanctum'] as $guard) {
                $meta = $roleMeta[$roleName] ?? ['description' => null, 'color' => '#6366f1', 'is_system' => 1];
                $role = Role::firstOrCreate(
                    ['name' => $roleName, 'guard_name' => $guard],
                    ['description' => $meta['description'], 'color' => $meta['color'], 'is_system' => $meta['is_system']]
                );
                // Update metadata on existing rows too
                $role->update(['description' => $meta['description'], 'color' => $meta['color'], 'is_system' => $meta['is_system']]);
                $role->syncPermissions(
                    Permission::whereIn('name', $perms)->where('guard_name', $guard)->get()
                );
            }
        }
    }
}
