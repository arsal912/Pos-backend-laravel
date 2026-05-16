<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'admin@possystem.com'],
            [
                'name' => 'Super Admin',
                'password' => 'password', // hashed automatically by User model cast
                'is_super_admin' => true,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        if (!$admin->hasRole('super-admin')) {
            $admin->assignRole('super-admin');
        }

        $this->command->info('Super admin created: admin@possystem.com / password');
    }
}
