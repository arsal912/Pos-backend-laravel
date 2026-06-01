<?php

namespace Database\Seeders;

use App\Models\Module;
use Illuminate\Database\Seeder;

class ModuleSeeder extends Seeder
{
    public function run(): void
    {
        $modules = [
            // ============ CORE ============
            ['name' => 'Dashboard', 'slug' => 'dashboard', 'category' => 'core', 'icon' => 'layout-dashboard', 'is_core' => true, 'description' => 'Main dashboard with KPIs and overview'],
            ['name' => 'POS Sales', 'slug' => 'pos-sales', 'category' => 'core', 'icon' => 'shopping-cart', 'is_core' => true, 'description' => 'Point of sale screen for processing transactions'],

            // ============ PRODUCTS ============
            ['name' => 'Product Management', 'slug' => 'products', 'category' => 'products', 'icon' => 'package', 'description' => 'Add, edit, and manage products'],
            ['name' => 'Categories', 'slug' => 'categories', 'category' => 'products', 'icon' => 'folder', 'description' => 'Product categories and subcategories'],
            ['name' => 'Product Variants', 'slug' => 'variants', 'category' => 'products', 'icon' => 'layers', 'description' => 'Manage product variants (size, color, etc.)'],
            ['name' => 'Barcode Printing', 'slug' => 'barcode-printing', 'category' => 'products', 'icon' => 'barcode', 'description' => 'Generate and print product barcodes'],
            ['name' => 'Bulk Import', 'slug' => 'bulk-import', 'category' => 'products', 'icon' => 'upload', 'description' => 'Bulk import products via Excel/CSV'],

            // ============ INVENTORY ============
            ['name' => 'Inventory Management', 'slug' => 'inventory', 'category' => 'inventory', 'icon' => 'boxes', 'description' => 'Track stock levels and movements'],
            ['name' => 'Stock Adjustment', 'slug' => 'stock-adjustment', 'category' => 'inventory', 'icon' => 'sliders', 'description' => 'Adjust stock for damage, loss, etc.'],
            ['name' => 'Stock Transfer', 'slug' => 'stock-transfer', 'category' => 'inventory', 'icon' => 'arrow-left-right', 'description' => 'Transfer stock between branches'],
            ['name' => 'Purchase Orders', 'slug' => 'purchase-orders', 'category' => 'inventory', 'icon' => 'file-text', 'description' => 'Create and manage purchase orders'],
            ['name' => 'Goods Received Note', 'slug' => 'grn', 'category' => 'inventory', 'icon' => 'clipboard-check', 'description' => 'Receive goods from suppliers'],

            // ============ PEOPLE ============
            ['name' => 'Customer Management',     'slug' => 'customers',                'category' => 'people', 'icon' => 'users',           'description' => 'Manage customers and their data'],
            ['name' => 'Loyalty Program',         'slug' => 'loyalty',                  'category' => 'people', 'icon' => 'gift',             'description' => 'Customer loyalty points and rewards'],
            ['name' => 'Customer Credit',         'slug' => 'customer-credit',          'category' => 'people', 'icon' => 'credit-card',      'description' => 'Customer credit tabs and outstanding balance management'],
            ['name' => 'Customer Groups',         'slug' => 'customer-groups',          'category' => 'people', 'icon' => 'users-round',      'description' => 'Group customers for pricing and segmentation'],
            ['name' => 'Customer Communications', 'slug' => 'customer-communications',  'category' => 'people', 'icon' => 'message-circle',   'description' => 'SMS, email, and WhatsApp customer communications'],
            ['name' => 'Supplier Management',     'slug' => 'suppliers',                'category' => 'people', 'icon' => 'truck',            'description' => 'Manage suppliers and vendors'],
            ['name' => 'Staff Management',        'slug' => 'staff',                    'category' => 'people', 'icon' => 'user-cog',         'description' => 'Manage employees and their roles'],

            // ============ OPERATIONS ============
            ['name' => 'Multi-Branch', 'slug' => 'multi-branch', 'category' => 'operations', 'icon' => 'store', 'description' => 'Manage multiple store locations'],
            ['name' => 'Expense Tracking', 'slug' => 'expenses', 'category' => 'operations', 'icon' => 'receipt', 'description' => 'Track business expenses'],
            ['name' => 'Returns & Refunds', 'slug' => 'returns', 'category' => 'operations', 'icon' => 'undo-2', 'description' => 'Process sales and purchase returns'],

            // ============ REPORTS ============
            ['name' => 'Sales Reports', 'slug' => 'sales-reports', 'category' => 'reports', 'icon' => 'bar-chart', 'description' => 'Daily, weekly, monthly sales reports'],
            ['name' => 'Purchase Reports', 'slug' => 'purchase-reports', 'category' => 'reports', 'icon' => 'shopping-bag', 'description' => 'Purchase analysis reports'],
            ['name' => 'Stock Reports', 'slug' => 'stock-reports', 'category' => 'reports', 'icon' => 'package-search', 'description' => 'Inventory and stock reports'],
            ['name' => 'Tax Reports', 'slug' => 'tax-reports', 'category' => 'reports', 'icon' => 'percent', 'description' => 'Tax collection and filing reports'],
            ['name' => 'Profit & Loss', 'slug' => 'profit-loss', 'category' => 'reports', 'icon' => 'trending-up', 'description' => 'P&L statement and financial overview'],
            ['name' => 'Staff Performance', 'slug' => 'staff-reports', 'category' => 'reports', 'icon' => 'award', 'description' => 'Staff sales and performance reports'],

            // ============ SETTINGS ============
            ['name' => 'Store Settings', 'slug' => 'store-settings', 'category' => 'settings', 'icon' => 'settings', 'is_core' => true, 'description' => 'General store configuration'],
            ['name' => 'Tax Settings', 'slug' => 'tax-settings', 'category' => 'settings', 'icon' => 'percent', 'description' => 'Configure tax rates'],
            ['name' => 'Receipt Customization', 'slug' => 'receipt-customization', 'category' => 'settings', 'icon' => 'file-text', 'description' => 'Customize receipt templates'],

            // ============ ADVANCED ============
            ['name' => 'Credit Management', 'slug' => 'credit-management', 'category' => 'advanced', 'icon' => 'credit-card', 'description' => 'Customer credit and dues management'],
            ['name' => 'Recurring Expenses', 'slug' => 'recurring-expenses', 'category' => 'advanced', 'icon' => 'repeat', 'description' => 'Automate recurring expense entries'],
        ];

        $sortOrder = 1;
        foreach ($modules as $module) {
            Module::updateOrCreate(
                ['slug' => $module['slug']],
                array_merge($module, [
                    'is_active' => true,
                    'is_core' => $module['is_core'] ?? false,
                    'sort_order' => $sortOrder++,
                ])
            );
        }
    }
}
