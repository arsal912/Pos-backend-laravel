<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PaymentGatewaySeeder extends Seeder
{
    public function run(): void
    {
        $gateways = [
            [
                'name' => 'Stripe',
                'slug' => 'stripe',
                'logo' => '/gateways/stripe.svg',
                'is_active' => false,
                'is_test_mode' => true,
                'supports_subscription' => true,
                'supported_currencies' => json_encode(['USD', 'EUR', 'GBP', 'PKR']),
                'sort_order' => 1,
            ],
            [
                'name' => 'PayPal',
                'slug' => 'paypal',
                'logo' => '/gateways/paypal.svg',
                'is_active' => false,
                'is_test_mode' => true,
                'supports_subscription' => true,
                'supported_currencies' => json_encode(['USD', 'EUR', 'GBP']),
                'sort_order' => 2,
            ],
            [
                'name' => 'JazzCash',
                'slug' => 'jazzcash',
                'logo' => '/gateways/jazzcash.svg',
                'is_active' => false,
                'is_test_mode' => true,
                'supports_subscription' => false,
                'supported_currencies' => json_encode(['PKR']),
                'sort_order' => 3,
            ],
            [
                'name' => 'Easypaisa',
                'slug' => 'easypaisa',
                'logo' => '/gateways/easypaisa.svg',
                'is_active' => false,
                'is_test_mode' => true,
                'supports_subscription' => false,
                'supported_currencies' => json_encode(['PKR']),
                'sort_order' => 4,
            ],
            [
                'name' => 'Manual / Bank Transfer',
                'slug' => 'manual',
                'logo' => '/gateways/bank.svg',
                'is_active' => true,
                'is_test_mode' => false,
                'supports_subscription' => false,
                'supported_currencies' => json_encode(['USD', 'PKR']),
                'sort_order' => 99,
            ],
        ];

        foreach ($gateways as $g) {
            DB::table('payment_gateways')->updateOrInsert(
                ['slug' => $g['slug']],
                array_merge($g, ['created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}
