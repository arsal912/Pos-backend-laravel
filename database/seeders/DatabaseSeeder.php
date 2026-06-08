<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            ModuleSeeder::class,
            PlanSeeder::class,
            SuperAdminSeeder::class,
            LandingPageSeeder::class,
            PaymentGatewaySeeder::class,
            CommunicationProviderSeeder::class,
        ]);
    }
}
