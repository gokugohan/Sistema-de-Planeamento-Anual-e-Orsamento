<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Institution-neutral installation seed. Safe to run on any deployment and
 * safe to run again: every seeder here only adds what is missing.
 *
 * It does NOT create directorates, programmes, activities or demo users. Those
 * belong to your institution (enter them in the app or import them), or load
 * the optional sample dataset:
 *
 *     php artisan db:seed --class="Database\Seeders\Samples\MtcSampleDataSeeder"
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            EconomicClassificationSeeder::class,
            SystemSettingsSeeder::class,
            AdminSeeder::class,
        ]);
    }
}
