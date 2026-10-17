<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\FiscalEnvelope;
use App\Models\Indicator;
use App\Models\LineItem;
use App\Models\Program;
use App\Models\Subprogram;
use App\Models\User;
use App\Services\ActivityRollForward;
use App\Services\ProgramYears;
use App\Services\SampleRegistry;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** The budget structure: programme, subprogramme, activity. */
class SubprogramTest extends TestCase
{
    use RefreshDatabase;

    private int $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app()->setLocale('en');
        $this->year = \App\Support\FiscalYear::current();
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('Admin');
        $this->actingAs($u);

        return $u;
    }

    private function program(int $year, string $code = '510'): Program
    {
        return Program::create(['fiscal_year' => $year, 'oge_code' => $code, 'name' => "Programme {$code}"]);
    }

    private function sub(Program $p, string $code = '51001', string $name = 'Institutional operation'): Subprogram
    {
        return $p->subprograms()->create(['code' => $code, 'name' => $name, 'objective' => 'Keep going']);
    }

    public function test_the_registry_creates_edits_and_deletes_subprogrammes_of_the_working_year(): void
    {
        $this->admin();
        $program = $this->program($this->year);

        $page = Volt::test('pages.management.entities')->set('activeTab', 'subprograms')->call('openSubprogramModal')
            ->set('subProgramId', (string) $program->id)->set('subCode', '51001')->set('subName', 'Operation')->call('saveSubprogram')->assertHasNoErrors();
        $sub = Subprogram::where('code', '51001')->firstOrFail();
        $this->assertSame($program->id, $sub->program_id);

        // one code per programme
        $page->call('openSubprogramModal')->set('subProgramId', (string) $program->id)->set('subCode', '51001')->set('subName', 'Again')->call('saveSubprogram')->assertHasErrors('subCode');

        $page->call('openSubprogramModal', $sub->id)->set('subName', 'Renamed')->call('saveSubprogram')->assertHasNoErrors();
        $this->assertSame('Renamed', $sub->fresh()->name);

        $page->call('deleteSubprogram', $sub->id);
        $this->assertSame(0, Subprogram::count());
    }

    public function test_a_subprogramme_must_belong_to_a_programme_of_the_working_year(): void
    {
        $this->admin();
        $old = $this->program($this->year - 1);

        Volt::test('pages.management.entities')->set('activeTab', 'subprograms')->call('openSubprogramModal')
            ->set('subProgramId', (string) $old->id)->set('subCode', 'X1')->set('subName', 'Elsewhere')->call('saveSubprogram')->assertHasErrors('subProgramId');
        $this->assertSame(0, Subprogram::count());
    }

    public function test_only_administrators_manage_subprogrammes_and_one_with_activities_cannot_be_deleted(): void
    {
        $planner = User::factory()->create();
        $planner->assignRole('Planning');
        $this->actingAs($planner);
        Volt::test('pages.management.entities')->call('openSubprogramModal')->assertForbidden();

        $this->admin();
        $unit = Directorate::create(['name' => 'Unit', 'code' => 'UNT', 'type' => 'General Directorate', 'active' => true]);
        $program = $this->program($this->year);
        $sub = $this->sub($program);
        Activity::create([
            'fiscal_year' => $this->year, 'program_id' => $program->id, 'subprogram_id' => $sub->id, 'directorate_id' => $unit->id, 'description' => 'Uses it',
            'priority' => 'Alta', 'planned_start' => "{$this->year}-01-01", 'planned_end' => "{$this->year}-12-31", 'quarter' => 1, 'status' => 'Draft', 'is_test_data' => false,
        ]);

        Volt::test('pages.management.entities')->call('deleteSubprogram', $sub->id);
        $this->assertTrue(Subprogram::whereKey($sub->id)->exists());
    }

    public function test_copying_programmes_brings_their_subprogrammes_and_fills_in_missing_ones(): void
    {
        $from = $this->program($this->year - 1, '510');
        $this->sub($from, '51001', 'Operation');
        $this->sub($from, '51002', 'Development');
        $have = $this->program($this->year, '510');           // the year already has the programme...
        $this->sub($have, '51001', 'Operation (this year)');   // ...and one of its subprogrammes

        $r = app(ProgramYears::class)->copy($this->year - 1, $this->year);

        $this->assertSame(['copied' => 0, 'skipped' => 1, 'subprograms' => 1], $r);
        $this->assertSame(['51001', '51002'], $have->subprograms()->orderBy('code')->pluck('code')->all());
        $this->assertSame('Operation (this year)', $have->subprograms()->where('code', '51001')->value('name'), 'an existing one is not overwritten');
    }

    public function test_rolling_a_plan_forward_carries_the_subprogramme(): void
    {
        $unit = Directorate::create(['name' => 'Unit', 'code' => 'UNT', 'type' => 'General Directorate', 'active' => true]);
        $program = $this->program($this->year);
        $sub = $this->sub($program);
        $a = Activity::create([
            'fiscal_year' => $this->year, 'program_id' => $program->id, 'subprogram_id' => $sub->id, 'directorate_id' => $unit->id, 'description' => 'Carry me',
            'priority' => 'Alta', 'planned_start' => "{$this->year}-02-01", 'planned_end' => "{$this->year}-06-30", 'quarter' => 1, 'status' => 'Approved', 'is_test_data' => false,
        ]);
        LineItem::create(['activity_id' => $a->id, 'economic_classification_id' => EconomicClassification::first()->id, 'estimated_amount' => 100, 'is_test_data' => false]);
        Indicator::create(['activity_id' => $a->id, 'name' => 'I', 'type' => 'Output', 'unit' => 'x', 'measure' => 'cumulative', 'annual_target' => 4]);

        app(ActivityRollForward::class)->copy($this->year, $this->year + 1);

        $copy = Activity::where('fiscal_year', $this->year + 1)->where('description', 'Carry me')->firstOrFail();
        $this->assertSame('51001', $copy->subprogram->code);
        $this->assertSame($copy->program_id, $copy->subprogram->program_id, 'the subprogramme belongs to the copied activity\'s own programme');
        $this->assertNotSame($sub->id, $copy->subprogram_id);
    }

    public function test_the_form_asks_for_a_subprogramme_when_the_programme_has_some_and_checks_it_belongs(): void
    {
        $unit = Directorate::create(['name' => 'Unit', 'code' => 'UNT', 'type' => 'General Directorate', 'active' => true]);
        $planner = User::factory()->create(['directorate_id' => $unit->id]);
        $planner->assignRole('Planning');
        $this->actingAs($planner);
        $p1 = $this->program($this->year, '510');
        $sub1 = $this->sub($p1);
        $p2 = $this->program($this->year, '502');
        $sub2 = $this->sub($p2, '50201', 'Other');
        $class = EconomicClassification::first();

        $fill = fn ($page) => $page->set('description', 'Inspect ports')->set('quarters', ['1'])
            ->set('lines', [['class_id' => (string) $class->id, 'amount' => '100']])
            ->set('indicators', [['name' => 'Inspections', 'type' => 'Output', 'unit' => 'ports', 'measure' => 'cumulative', 'target' => '6']]);

        // none chosen
        $fill(Volt::test('pages.activities.activity-form')->set('program_id', (string) $p1->id))->call('save')->assertHasErrors('subprogram_id');
        // one of another programme
        $fill(Volt::test('pages.activities.activity-form')->set('program_id', (string) $p1->id)->set('subprogram_id', (string) $sub2->id))->call('save')->assertHasErrors('subprogram_id');
        $this->assertSame(0, Activity::count());

        $fill(Volt::test('pages.activities.activity-form')->set('program_id', (string) $p1->id)->set('subprogram_id', (string) $sub1->id))->call('save')->assertHasNoErrors();
        $this->assertSame($sub1->id, Activity::firstOrFail()->subprogram_id);

        // changing the programme starts the choice again
        Volt::test('pages.activities.activity-form')->set('program_id', (string) $p1->id)->set('subprogram_id', (string) $sub1->id)
            ->set('program_id', (string) $p2->id)->assertSet('subprogram_id', '');
    }

    public function test_a_programme_without_subprogrammes_needs_none(): void
    {
        $unit = Directorate::create(['name' => 'Unit', 'code' => 'UNT', 'type' => 'General Directorate', 'active' => true]);
        $planner = User::factory()->create(['directorate_id' => $unit->id]);
        $planner->assignRole('Planning');
        $this->actingAs($planner);
        $p = $this->program($this->year, '980');
        $class = EconomicClassification::first();

        Volt::test('pages.activities.activity-form')->set('program_id', (string) $p->id)->set('description', 'Inclusion work')->set('quarters', ['1'])
            ->set('lines', [['class_id' => (string) $class->id, 'amount' => '100']])
            ->set('indicators', [['name' => 'Cases', 'type' => 'Output', 'unit' => 'cases', 'measure' => 'cumulative', 'target' => '6']])
            ->call('save')->assertHasNoErrors();

        $this->assertNull(Activity::firstOrFail()->subprogram_id);
    }

    public function test_the_sample_files_activities_under_the_budget_books_subprogrammes_and_the_dashboard_shows_them(): void
    {
        Carbon::setTestNow('2026-10-14 10:00:00');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        $this->admin();
        session(['fiscal_year' => 2026]);

        $sub = Subprogram::where('code', '51001')->firstOrFail();
        $this->assertSame('Funcionamento Institucional', $sub->name);
        $this->assertGreaterThan(0, Activity::where('subprogram_id', $sub->id)->count());

        $rows = Volt::test('pages.dashboard.summary')->viewData('progRows');
        $programme = collect($rows)->firstWhere('code', '510');
        $this->assertSame('51001', $programme['subs'][0]['code']);
        $this->assertGreaterThan(0, $programme['subs'][0]['plan']);

        Carbon::setTestNow();
    }

    public function test_purging_the_sample_removes_its_subprogrammes(): void
    {
        Carbon::setTestNow('2026-10-14 10:00:00');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        $this->assertGreaterThan(0, Subprogram::count());
        $this->actingAs(tap(User::factory()->create(), fn ($u) => $u->assignRole('Admin')));

        app(SampleRegistry::class)->purge('mtc');

        $this->assertSame(0, Subprogram::count());
        Carbon::setTestNow();
    }
}
