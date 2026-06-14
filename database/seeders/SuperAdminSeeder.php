<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        // is_super_admin and email_verified_at are intentionally excluded from
        // User::$fillable to prevent mass-assignment privilege escalation.
        // updateOrCreate only receives fillable fields; the sensitive columns
        // are set via direct property assignment below.
        $admin = User::updateOrCreate(
            ['email' => 'admin@possystem.com'],
            [
                'name' => 'Super Admin',
                'password' => 'password', // hashed automatically by User model cast
                'is_active' => true,
            ]
        );

        // Direct assignment — bypasses $fillable intentionally (server-controlled fields).
        $admin->is_super_admin = true;
        $admin->email_verified_at = $admin->email_verified_at ?? now();
        $admin->save();

        if (!$admin->hasRole('super-admin')) {
            $admin->assignRole('super-admin');
        }

        $this->command->info('Super admin created: admin@possystem.com / password');
    }
}
