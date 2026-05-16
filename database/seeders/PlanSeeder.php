<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $allModuleIds = Module::pluck('id')->toArray();

        $coreModules = Module::whereIn('slug', [
            'dashboard', 'pos-sales', 'products', 'categories', 'customers', 'store-settings',
        ])->pluck('id')->toArray();

        $basicModules = Module::whereIn('slug', [
            'dashboard', 'pos-sales', 'products', 'categories', 'variants',
            'customers', 'sales-reports', 'store-settings', 'tax-settings',
            'returns', 'expenses',
        ])->pluck('id')->toArray();

        $proModules = Module::whereIn('slug', [
            'dashboard', 'pos-sales', 'products', 'categories', 'variants', 'barcode-printing',
            'bulk-import', 'inventory', 'stock-adjustment', 'purchase-orders', 'grn',
            'customers', 'loyalty', 'suppliers', 'staff', 'expenses', 'returns',
            'sales-reports', 'purchase-reports', 'stock-reports', 'tax-reports',
            'profit-loss', 'staff-reports', 'store-settings', 'tax-settings',
            'receipt-customization', 'stock-transfer',
        ])->pluck('id')->toArray();

        $plans = [
            [
                'name' => 'Free Trial',
                'slug' => 'free-trial',
                'description' => '14-day free trial with all features',
                'price' => 0,
                'currency' => 'USD',
                'billing_cycle' => 'monthly',
                'trial_days' => 14,
                'max_products' => 100,
                'max_users' => 3,
                'max_branches' => 1,
                'max_transactions_per_month' => 500,
                'features' => ['All Pro features for 14 days', 'Email support', 'No credit card required'],
                'is_featured' => false,
                'sort_order' => 1,
                'modules' => $allModuleIds,
            ],
            [
                'name' => 'Basic',
                'slug' => 'basic',
                'description' => 'Perfect for small businesses just starting out',
                'price' => 9.99,
                'currency' => 'USD',
                'billing_cycle' => 'monthly',
                'trial_days' => 7,
                'max_products' => 500,
                'max_users' => 3,
                'max_branches' => 1,
                'max_transactions_per_month' => 1000,
                'features' => [
                    'Up to 500 products',
                    '3 users',
                    '1 branch',
                    'Basic POS features',
                    'Customer management',
                    'Sales reports',
                    'Email support',
                ],
                'is_featured' => false,
                'sort_order' => 2,
                'modules' => $basicModules,
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro',
                'description' => 'For growing businesses with multiple needs',
                'price' => 29.99,
                'currency' => 'USD',
                'billing_cycle' => 'monthly',
                'trial_days' => 14,
                'max_products' => 5000,
                'max_users' => 15,
                'max_branches' => 3,
                'max_transactions_per_month' => 10000,
                'features' => [
                    'Up to 5,000 products',
                    '15 users',
                    '3 branches',
                    'Full inventory management',
                    'Purchase orders & GRN',
                    'Suppliers',
                    'Loyalty program',
                    'All reports',
                    'Priority email support',
                ],
                'is_featured' => true,
                'sort_order' => 3,
                'modules' => $proModules,
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'description' => 'Unlimited everything for large businesses',
                'price' => 79.99,
                'currency' => 'USD',
                'billing_cycle' => 'monthly',
                'trial_days' => 14,
                'max_products' => null,
                'max_users' => null,
                'max_branches' => null,
                'max_transactions_per_month' => null,
                'features' => [
                    'Unlimited products',
                    'Unlimited users',
                    'Unlimited branches',
                    'All Pro features',
                    'Advanced credit management',
                    'Recurring expenses',
                    'Phone & priority support',
                    'Custom integrations',
                    'Dedicated account manager',
                ],
                'is_featured' => false,
                'sort_order' => 4,
                'modules' => $allModuleIds,
            ],
        ];

        foreach ($plans as $planData) {
            $moduleIds = $planData['modules'];
            unset($planData['modules']);

            $plan = Plan::updateOrCreate(
                ['slug' => $planData['slug']],
                array_merge($planData, ['is_active' => true])
            );

            $plan->modules()->sync($moduleIds);
        }
    }
}
