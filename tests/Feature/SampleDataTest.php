<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\Program;
use App\Models\SampleRecord;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\SampleRegistry;
use Database\Seeders\EconomicClassificationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\Samples\MtcSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SampleDataTest extends TestCase
{
    use RefreshDatabase;

    private function load(): void
    {
        $this->seed(MtcSampleDataSeeder::class);
    }

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $u = User::factory()->create();
        $u->assignRole('Admin');
        $this->actingAs($u);

        return $u;
    }

    private function realActivityIn(Directorate $dir, Program $program, string $description = 'Real activity'): Activity
    {
        return Activity::create([
            'fiscal_year' => 2027, 'program_id' => $program->id, 'directorate_id' => $dir->id, 'description' => $description,
            'quarter' => 1, 'priority' => 'Alta', 'planned_start' => '2027-01-01', 'planned_end' => '2027-03-31', 'status' => 'Draft',
        ]);
    }

    public function test_loading_registers_exactly_what_it_creates(): void
    {
        $this->load();

        $counts = app(SampleRegistry::class)->counts();
        $this->assertSame(['activities' => 274, 'users' => 99, 'directorates' => 49, 'programs' => 18], $counts);
        $this->assertSame(Activity::count(), $counts['activities']);
        $this->assertTrue((bool) SystemSetting::get(SampleRegistry::FLAG));
    }

    public function test_purge_removes_all_sample_data_and_resets_branding(): void
    {
        $this->load();
        $this->assertSame('MTC', SystemSetting::getLocalized('org_abbr', '', 'en'));

        $report = app(SampleRegistry::class)->purge('mtc');

        $this->assertSame(['activities' => 274, 'users' => 99, 'directorates' => 49, 'programs' => 18], $report['deleted']);
        $this->assertSame([], $report['kept']);
        $this->assertSame(0, Activity::count() + Directorate::count() + Program::count() + User::count());
        $this->assertSame(0, SampleRecord::count());
        $this->assertFalse((bool) SystemSetting::get(SampleRegistry::FLAG));
        $this->assertSame('ORG', SystemSetting::getLocalized('org_abbr', '', 'en'));
    }

    public function test_purge_never_touches_real_data(): void
    {
        $realDir = Directorate::create(['code' => 'REAL', 'name' => 'Real Directorate', 'type' => 'Independent', 'active' => true]);
        $realProgram = Program::create(['oge_code' => '900', 'name' => 'Real Programme']);
        $realUser = User::factory()->create(['email' => 'real@institution.example']);
        $realActivity = $this->realActivityIn($realDir, $realProgram);

        $this->load();
        app(SampleRegistry::class)->purge('mtc');

        $this->assertNotNull($realDir->fresh());
        $this->assertNotNull($realProgram->fresh());
        $this->assertNotNull($realUser->fresh());
        $this->assertNotNull($realActivity->fresh());
        $this->assertSame(1, Directorate::count());
    }

    public function test_an_existing_record_with_a_sample_code_is_left_alone_and_never_purged(): void
    {
        $real = Directorate::create(['code' => 'DNTT', 'name' => 'My own DNTT', 'type' => 'Independent', 'active' => true]);

        $this->load();

        $this->assertSame('My own DNTT', $real->fresh()->name, 'seeding overwrote a real record');
        $this->assertNotEmpty(app(SampleRegistry::class)->skipped);
        $this->assertFalse(app(SampleRegistry::class)->isRegistered($real));

        app(SampleRegistry::class)->purge('mtc');

        $this->assertNotNull($real->fresh(), 'purge deleted a real record that only shared a sample code');
    }

    public function test_sample_units_and_programmes_used_by_real_activities_are_kept(): void
    {
        $this->load();
        $unit = Directorate::where('code', 'DNTT')->first();
        $program = Program::where('oge_code', '502')->first();
        $real = $this->realActivityIn($unit, $program, 'Real work done by DNTT');

        $report = app(SampleRegistry::class)->purge('mtc');

        $this->assertNotNull($real->fresh(), 'a real activity was deleted with its sample unit');
        $this->assertNotNull($unit->fresh());
        $this->assertNotNull(Directorate::where('code', 'DGT')->first(), 'the parent of a kept unit must stay');
        $this->assertNotNull($program->fresh());
        $this->assertNull(Directorate::where('code', 'GJ')->first(), 'unused sample units must go');
        $this->assertNotEmpty($report['kept']);
        $this->assertSame(0, SampleRecord::count(), 'kept records become ordinary data');
    }

    public function test_an_administrator_is_never_deleted_even_if_registered(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        app(SampleRegistry::class)->register('mtc', $admin);

        $report = app(SampleRegistry::class)->purge('mtc');

        $this->assertNotNull($admin->fresh());
        $this->assertStringContainsString('administrator', implode(' ', $report['kept']));
    }

    public function test_branding_the_admin_customised_survives_a_purge(): void
    {
        $this->load();
        SystemSetting::set('org_name', ['en' => 'Ministry of Public Works'], 'json');

        app(SampleRegistry::class)->purge('mtc');

        $this->assertSame('Ministry of Public Works', SystemSetting::getLocalized('org_name', '', 'en'));
    }

    public function test_reloading_is_idempotent(): void
    {
        $this->load();
        $this->load();

        $this->assertSame(49, Directorate::count());
        $this->assertSame(274, Activity::count());
        $this->assertSame(['activities' => 274, 'users' => 99, 'directorates' => 49, 'programs' => 18], app(SampleRegistry::class)->counts());
    }

    public function test_adopt_registers_matching_pre_existing_records_but_not_changed_ones(): void
    {
        $this->load();
        SampleRecord::query()->delete(); // simulate data loaded before tracking existed
        SystemSetting::set(SampleRegistry::FLAG, false, 'boolean');
        Directorate::where('code', 'DNTT')->update(['name' => 'Renamed by someone']);

        $this->artisan('paa:adopt-samples', ['--force' => true])->assertSuccessful();

        $registry = app(SampleRegistry::class);
        $this->assertFalse($registry->isRegistered(Directorate::where('code', 'DNTT')->first()), 'a modified record must not be adopted');
        $this->assertTrue($registry->isRegistered(Directorate::where('code', 'DGT')->first()));
        $this->assertSame(0, $registry->counts()['activities'], 'activities are never adopted: the plans are loaded with their own budget records');
        $this->assertSame(48, $registry->counts()['directorates']);
    }

    public function test_purge_command_removes_everything(): void
    {
        $this->load();

        $this->artisan('paa:purge-samples', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, Directorate::count());
        $this->assertSame(0, SampleRecord::count());
    }

    public function test_admin_page_loads_and_purges_sample_data(): void
    {
        $this->admin();
        $this->seed(EconomicClassificationSeeder::class);

        Volt::test('pages.management.sample-data')->set('scope', 'ministry')->call('load');
        $this->assertSame(49, Directorate::count());
        $this->assertTrue(app(SampleRegistry::class)->hasSamples());

        Volt::test('pages.management.sample-data')->call('purge')->assertSet('report.action', 'purged');
        $this->assertFalse(app(SampleRegistry::class)->hasSamples());
        $this->assertSame(0, Directorate::count());
    }

    public function test_only_administrators_can_open_or_use_the_page(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $minister = User::factory()->create();
        $minister->assignRole('Minister');
        $this->actingAs($minister);

        $this->get(route('management.sample-data'))->assertForbidden();
        Volt::test('pages.management.sample-data')->assertForbidden();
    }

    public function test_loading_is_refused_in_production_but_purging_is_allowed(): void
    {
        $this->admin();
        $this->load(); // loaded earlier, outside production (e.g. a staging copy)
        $this->app['env'] = 'production';

        $this->get(route('management.sample-data'))->assertOk()
            ->assertSee(__('Sample data is present in a production system.'));

        Volt::test('pages.management.sample-data')->call('purge');
        $this->assertFalse(app(SampleRegistry::class)->hasSamples());

        Volt::test('pages.management.sample-data')->call('load');
        $this->assertSame(0, Directorate::count(), 'sample data was loaded in production');
    }
}
