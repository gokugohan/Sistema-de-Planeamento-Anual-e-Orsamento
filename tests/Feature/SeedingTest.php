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
use Database\Seeders\RolesAndPermissionsSeeder;
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
        $this->seed(RolesAndPermissionsSeeder::class);
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
        $this->assertSame(8, Program::count());
        $this->assertSame(24, Activity::count());
        $this->assertSame('MTC', SystemSetting::getLocalized('org_abbr', '', 'en'));
        $this->assertTrue(User::where('email', 'ministro@goku.tl')->first()->isMinister());
        // Every sample activity is recognisably marked.
        $this->assertSame(24, Activity::where('code', 'like', 'SAMPLE-%')->where('description', 'like', '[EXEMPLO]%')->count());

        $this->artisan('paa:purge-samples', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, Activity::count());
        $this->assertSame(0, Directorate::count());
        $this->assertSame(0, Program::count());
    }

    /** DL 75/2023 art. 7: five public bodies with administrative and financial autonomy; everything else is a ministry service. */
    public function test_mtc_autonomous_bodies_are_flagged_as_indirect_administration(): void
    {
        $this->seed(MtcSampleDataSeeder::class);

        $autonomous = Directorate::where('administration', Directorate::INDIRECT)->orderBy('code')->pluck('legal_form', 'code')->all();
        $this->assertSame(['AACTL' => 'I.P.', 'ANATL' => 'E.P.', 'ANC' => 'I.P.', 'APORTIL' => 'I.P.', 'TICTIMOR' => 'I.P.'], $autonomous);
        $this->assertSame(23, Directorate::where('administration', Directorate::DIRECT)->count());
        $this->assertStringContainsString('DL 75/2023', Directorate::where('code', 'ANC')->value('legal_basis'));

        // Autonomous bodies are top-level and have no invented sub-units; they plan activities of their own.
        $this->assertSame(0, Directorate::whereIn('parent_id', Directorate::where('administration', Directorate::INDIRECT)->pluck('id'))->count());
        foreach (array_keys($autonomous) as $code) {
            $this->assertGreaterThan(0, Activity::where('directorate_id', Directorate::where('code', $code)->value('id'))->count(), "$code has no sample activity");
        }
    }

    public function test_mtc_sample_data_is_refused_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(\RuntimeException::class);
        $this->app->make(MtcSampleDataSeeder::class)->run();
    }
}
