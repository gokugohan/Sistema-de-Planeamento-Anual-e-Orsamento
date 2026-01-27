<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            DirectorateSeeder::class,
            UserSeeder::class,
            ProgramSeeder::class,
            EconomicClassificationSeeder::class,
                // BudgetSeeder::class,
            SystemSettingsSeeder::class,
        ]);
    }
}
