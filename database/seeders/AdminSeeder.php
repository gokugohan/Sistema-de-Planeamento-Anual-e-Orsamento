<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the first administrator, and only if no Admin exists yet, so it is
 * safe to run on an installed system.
 *
 * Configure with ADMIN_NAME, ADMIN_EMAIL and ADMIN_PASSWORD in .env.
 * In production ADMIN_PASSWORD is mandatory: there is no default password.
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        if (User::role('Admin')->exists()) {
            $this->command?->info('An administrator already exists; nothing to do.');

            return;
        }

        $password = env('ADMIN_PASSWORD');

        if (! $password) {
            if (app()->isProduction()) {
                throw new \RuntimeException('Set ADMIN_PASSWORD in .env before seeding in production.');
            }
            $password = 'password';
            $this->command?->warn('ADMIN_PASSWORD not set: using the development password "password". Never do this in production.');
        }

        $admin = User::create([
            'name' => env('ADMIN_NAME', 'System Administrator'),
            'email' => env('ADMIN_EMAIL', 'admin@example.org'),
            'password' => Hash::make($password),
        ]);
        $admin->forceFill(['email_verified_at' => now()])->save();
        $admin->assignRole('Admin');

        $this->command?->info("Administrator created: {$admin->email}");
    }
}
