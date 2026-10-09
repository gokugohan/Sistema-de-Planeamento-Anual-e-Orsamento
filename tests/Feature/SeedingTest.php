<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\Program;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Samples\MtcSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedingTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_seed_is_institution_neutral(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(5, EconomicClassification::count());
        $this->assertSame(1, User::role('Admin')->count());
        $this->assertSame(0, Directorate::count());
        $this->assertSame(0, Program::count());
        $this->assertSame(0, Activity::count());
        $this->assertSame('Institution Name', SystemSetting::getLocalized('org_name', '', 'en'));
    }

    public function test_default_seed_can_be_repeated_without_overwriting_anything(): void
    {
        $this->seed(DatabaseSeeder::class);
        SystemSetting::set('org_name', ['en' => 'My Ministry'], 'json');

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::role('Admin')->count(), 'a second administrator was created');
        $this->assertSame('My Ministry', SystemSetting::getLocalized('org_name', '', 'en'), 'a customised setting was overwritten');
    }

    public function test_admin_seeder_refuses_to_run_in_production_without_a_password(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->app['env'] = 'production';
        putenv('ADMIN_PASSWORD');
        unset($_ENV['ADMIN_PASSWORD'], $_SERVER['ADMIN_PASSWORD']);

        $this->expectException(\RuntimeException::class);
        $this->app->make(AdminSeeder::class)->run(); // direct call: db:seed would ask for confirmation first
    }

    public function test_mtc_sample_data_loads_and_can_be_purged(): void
    {
        $this->seed(MtcSampleDataSeeder::class);

        $this->assertSame(28, Directorate::count());
        $this->assertSame(7, Program::count());
        $this->assertSame(19, Activity::count());
        $this->assertSame('MTC', SystemSetting::getLocalized('org_abbr', '', 'en'));
        $this->assertTrue(User::where('email', 'ministro@goku.tl')->first()->isMinister());
        // Every sample activity is recognisably marked.
        $this->assertSame(19, Activity::where('code', 'like', 'SAMPLE-%')->where('description', 'like', '[EXEMPLO]%')->count());

        $this->artisan('paa:purge-samples', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, Activity::count());
        $this->assertSame(0, Directorate::count());
        $this->assertSame(0, Program::count());
    }

    public function test_mtc_sample_data_is_refused_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(\RuntimeException::class);
        $this->app->make(MtcSampleDataSeeder::class)->run();
    }
}
