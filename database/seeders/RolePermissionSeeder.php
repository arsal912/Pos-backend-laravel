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
            // POS price override (mentioned in Step 7)
            'edit-sale-price',
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

        foreach ($roles as $roleName => $perms) {
            foreach (['web', 'sanctum'] as $guard) {
                $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => $guard]);
                $role->syncPermissions(
                    Permission::whereIn('name', $perms)->where('guard_name', $guard)->get()
                );
            }
        }
    }
}
