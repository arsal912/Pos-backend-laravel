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
            ],
            'cashier' => [
                'view-products',
                'create-sales', 'view-sales',
                'view-customers', 'manage-customers',
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
