<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\Indicator;
use App\Models\LineItem;
use App\Models\Program;
use App\Models\User;
use App\Services\ActivityRollForward;
use App\Services\ProgramYears;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ProgramYearsTest extends TestCase
{
    use RefreshDatabase;

    private int $year;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->seed(DatabaseSeeder::class);
        $this->year = \App\Support\FiscalYear::current();
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('Admin');
        $this->actingAs($u);

        return $u;
    }

    private function program(int $year, string $code, string $name = 'Programme', array $extra = []): Program
    {
        return Program::create(['fiscal_year' => $year, 'oge_code' => $code, 'name' => $name] + $extra);
    }

    public function test_the_same_code_can_be_registered_in_each_year_but_only_once_per_year(): void
    {
        $this->program(2025, '510', 'Funcionamento');
        $this->program(2026, '510', 'Funcionamento e Desenvolvimento Institucional');

        $this->assertSame(2, Program::where('oge_code', '510')->count());

        $this->expectException(QueryException::class);
        $this->program(2026, '510', 'Duplicate');
    }

    public function test_registering_a_programme_through_the_form_belongs_to_the_working_year(): void
    {
        $this->admin();

        Volt::test('pages.management.entities')->set('activeTab', 'programs')->call('openProgramModal')
            ->set('ogeCode', 'N-1')->set('programName', 'New programme')->call('saveProgram')->assertHasNoErrors();

        $this->assertSame($this->year, (int) Program::where('oge_code', 'N-1')->value('fiscal_year'));
    }

    public function test_a_code_already_in_the_working_year_is_refused_but_another_years_code_is_not(): void
    {
        $this->admin();
        $this->program($this->year, 'DUP', 'Already here');
        $this->program($this->year - 1, 'OLD', 'Last year only');

        Volt::test('pages.management.entities')->set('activeTab', 'programs')->call('openProgramModal')
            ->set('ogeCode', 'DUP')->set('programName', 'Again')->call('saveProgram')->assertHasErrors('ogeCode');

        Volt::test('pages.management.entities')->set('activeTab', 'programs')->call('openProgramModal')
            ->set('ogeCode', 'OLD')->set('programName', 'Last year only')->call('saveProgram')->assertHasNoErrors();
        $this->assertTrue(Program::forYear($this->year)->where('oge_code', 'OLD')->exists());
    }

    public function test_typing_suggests_programmes_from_other_years_that_this_year_lacks(): void
    {
        $this->program($this->year - 1, 'A08', 'Tecnologia de Informação', ['objective' => 'Connect everyone']);
        $this->program($this->year - 2, 'A08', 'Tecnologia (older name)');
        $this->program($this->year - 1, '510', 'Funcionamento');
        $this->program($this->year, '510', 'Funcionamento');   // already registered this year: not suggested
        $svc = app(ProgramYears::class);

        $byCode = $svc->suggestions($this->year, 'A0');
        $this->assertSame(['A08'], $byCode->pluck('oge_code')->all());
        $this->assertSame('Tecnologia de Informação', $byCode->first()->name, 'the newest version of a code is offered');

        $this->assertSame(['A08'], $svc->suggestions($this->year, 'tecnolog')->pluck('oge_code')->all(), 'by name too');
        $this->assertCount(0, $svc->suggestions($this->year, 'Funcion'), 'a code the year already has is not offered');
        $this->assertCount(0, $svc->suggestions($this->year, ''));
    }

    public function test_the_form_shows_the_suggestions_and_picking_one_fills_it_in(): void
    {
        $this->admin();
        $old = $this->program($this->year - 1, 'A08', 'Tecnologia de Informação', ['manager_name' => 'Maria', 'objective' => 'Connect everyone']);

        $page = Volt::test('pages.management.entities')->set('activeTab', 'programs')->call('openProgramModal')
            ->set('ogeCode', 'A0')->assertSee('Tecnologia de Informação')->assertSee((string) ($this->year - 1));

        $page->call('pickProgram', $old->id)
            ->assertSet('ogeCode', 'A08')->assertSet('programName', 'Tecnologia de Informação')
            ->assertSet('managerName', 'Maria')->assertSet('objective', 'Connect everyone')
            ->call('saveProgram')->assertHasNoErrors();

        $new = Program::forYear($this->year)->where('oge_code', 'A08')->firstOrFail();
        $this->assertNotSame($old->id, $new->id, 'registering it for this year makes a programme of this year; last year is untouched');
        $this->assertSame($this->year - 1, (int) $old->fresh()->fiscal_year);
    }

    public function test_copying_a_years_programmes_adds_only_the_missing_ones(): void
    {
        $this->admin();
        $this->program($this->year - 1, '502', 'Transportes');
        $this->program($this->year - 1, '510', 'Funcionamento');
        $this->program($this->year, '510', 'Funcionamento (this year)');

        Volt::test('pages.management.entities')->set('activeTab', 'programs')
            ->set('copyFromYear', (string) ($this->year - 1))->call('copyPrograms')->assertHasNoErrors();

        $this->assertSame(['502', '510'], Program::forYear($this->year)->orderBy('oge_code')->pluck('oge_code')->all());
        $this->assertSame('Funcionamento (this year)', Program::forYear($this->year)->where('oge_code', '510')->value('name'), 'an existing one is not overwritten');
    }

    public function test_only_administrators_manage_programmes(): void
    {
        $planner = User::factory()->create();
        $planner->assignRole('Planning');
        $this->actingAs($planner);

        Volt::test('pages.management.entities')->call('openProgramModal')->assertForbidden();
    }

    public function test_an_activity_can_only_use_a_programme_of_its_own_year(): void
    {
        $unit = Directorate::create(['name' => 'Unit', 'code' => 'UNT', 'type' => 'General Directorate']);
        $planner = User::factory()->create(['directorate_id' => $unit->id]);
        $planner->assignRole('Planning');
        $this->actingAs($planner);
        $mine = $this->program($this->year, 'THIS', 'This year');
        $other = $this->program($this->year - 1, 'LAST', 'Last year');

        Volt::test('pages.activities.activity-form')
            ->assertSee('This year')->assertDontSee('Last year')
            ->set('program_id', (string) $other->id)->call('save')->assertHasErrors('program_id');

        Volt::test('pages.activities.activity-form')
            ->set('program_id', (string) $mine->id)->call('save')->assertHasNoErrors('program_id');
    }

    public function test_rolling_a_plan_forward_carries_its_programmes_to_the_new_year(): void
    {
        $unit = Directorate::create(['name' => 'Unit', 'code' => 'UNT', 'type' => 'General Directorate']);
        $from = $this->year;
        $to = $this->year + 1;
        $source = $this->program($from, 'ROLL', 'Rolling programme', ['objective' => 'Keep going']);
        $a = Activity::create([
            'fiscal_year' => $from, 'program_id' => $source->id, 'directorate_id' => $unit->id, 'description' => 'Carry me forward',
            'priority' => 'Alta', 'planned_start' => "{$from}-02-01", 'planned_end' => "{$from}-06-30", 'quarter' => 1,
            'status' => Activity::STATUS_APPROVED, 'is_test_data' => false,
        ]);
        LineItem::create(['activity_id' => $a->id, 'economic_classification_id' => EconomicClassification::first()->id, 'estimated_amount' => 100, 'is_test_data' => false]);
        Indicator::create(['activity_id' => $a->id, 'name' => 'Reports', 'type' => 'Output', 'unit' => 'reports', 'annual_target' => 4]);

        app(ActivityRollForward::class)->copy($from, $to);

        $next = Program::forYear($to)->where('oge_code', 'ROLL')->first();
        $this->assertNotNull($next, 'the programme was registered for the new year');
        $this->assertSame('Keep going', $next->objective);
        $copy = Activity::where('fiscal_year', $to)->where('description', 'Carry me forward')->firstOrFail();
        $this->assertSame($next->id, $copy->program_id, 'the copied activity uses the programme of its own year');
        $this->assertSame($source->id, $a->fresh()->program_id, 'the original is untouched');

        // a second run reuses it rather than registering it again
        app(ActivityRollForward::class)->copy($from, $to);
        $this->assertSame(1, Program::forYear($to)->where('oge_code', 'ROLL')->count());
    }

    public function test_the_dashboard_shows_the_programmes_of_the_year_and_can_be_filtered_by_one(): void
    {
        Carbon::setTestNow('2026-10-14 10:00:00');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        $this->admin();
        session(['fiscal_year' => 2026]);

        $page = Volt::test('pages.dashboard.summary')
            ->assertSee('Funcionamento e Desenvolvimento Institucional')
            ->assertSee('Tecnologia de Informação e Comunicação');
        $codes = fn ($p) => collect($p->viewData('progRows'))->pluck('code')->sort()->values()->all();
        $this->assertSame(['510', 'A08'], $codes($page));

        $id = Program::forYear(2026)->where('oge_code', '510')->value('id');
        $page->set('program', (string) $id);
        $this->assertSame(['510'], $codes($page), 'only the chosen programme is left');
        $this->assertEqualsWithDelta(821702, collect($page->viewData('progRows'))->first()['plan'], 1);

        $page->call('clearFilters')->assertSet('program', '');
        $this->assertSame(['510', 'A08'], $codes($page));
        Carbon::setTestNow();
    }
}
