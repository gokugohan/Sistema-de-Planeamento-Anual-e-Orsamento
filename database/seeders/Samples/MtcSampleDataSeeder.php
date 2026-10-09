<?php

namespace Database\Seeders\Samples;

use Database\Seeders\EconomicClassificationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Seeder;

/**
 * Optional sample dataset based on the Ministério dos Transportes e Comunicações
 * (MTC) of Timor-Leste. The application itself is institution-neutral; MTC is
 * only the example used for demos, training and development.
 *
 * Load it from Management > Sample Data (Admin), or:
 *
 *     php artisan db:seed --class="Database\Seeders\Samples\MtcSampleDataSeeder"
 *
 * Adds: the MTC organic structure, working programmes (MTC-001 …), branding,
 * demo accounts and ~24 illustrative 2027 activities (marked [EXEMPLO]).
 *
 * Every record it creates is registered, so it can be purged again with
 * Management > Sample Data or `php artisan paa:purge-samples`. Records that
 * already exist are never modified.
 *
 * Refused in production: it creates accounts with a known password.
 */
class MtcSampleDataSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new \RuntimeException('The sample dataset creates demo accounts with a known password and must not be loaded in production.');
        }

        $this->call([
            RolesAndPermissionsSeeder::class,
            EconomicClassificationSeeder::class,
            MtcBrandingSeeder::class,
            MtcDirectorateSeeder::class,
            MtcProgramSeeder::class,
            MtcDemoUsersSeeder::class,
            MtcSampleActivitiesSeeder::class,
        ]);
    }
}
