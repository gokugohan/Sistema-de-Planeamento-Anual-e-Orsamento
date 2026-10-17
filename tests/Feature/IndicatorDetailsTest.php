<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Alignment;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\Indicator;
use App\Models\LineItem;
use App\Models\IndicatorProgress;
use App\Models\Program;
use App\Models\ResultIndicator;
use App\Models\User;
use App\Services\DeliveryReport;
use App\Services\ProgramYears;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Richer indicators: how an indicator is verified, funded and when it is reported; and the national
 * objectives and result indicators of programmes and subprogrammes.
 */
class IndicatorDetailsTest extends TestCase
{
    use RefreshDatabase;

    private int $year;

    private Directorate $unit;

    private User $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app()->setLocale('en');
        $this->year = \App\Support\FiscalYear::current();
        $this->unit = Directorate::create(['name' => 'Unit', 'code' => 'UNT', 'type' => 'General Directorate', 'active' => true]);
        $this->planner = User::factory()->create(['directorate_id' => $this->unit->id]);
        $this->planner->assignRole('Planning');
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('Admin');
        $this->actingAs($u);

        return $u;
    }

    private function program(int $year = null, string $code = '510'): Program
    {
        return Program::create(['fiscal_year' => $year ?? $this->year, 'oge_code' => $code, 'name' => "Programme {$code}"]);
    }

    private function activity(Program $program, string $status = 'Approved'): Activity
    {
        return Activity::create([
            'fiscal_year' => $this->year, 'program_id' => $program->id, 'directorate_id' => $this->unit->id, 'description' => 'Activity '.uniqid(),
            'priority' => 'Alta', 'planned_start' => "{$this->year}-01-01", 'planned_end' => "{$this->year}-12-31", 'quarter' => 1, 'status' => $status, 'is_test_data' => false,
        ]);
    }

    // ── activity indicators

    public function test_an_indicator_is_due_every_quarter_unless_it_says_otherwise(): void
    {
        $all = new Indicator(['due_quarters' => null]);
        $some = new Indicator(['due_quarters' => [2, 4]]);

        $this->assertSame([1, 2, 3, 4], $all->dueQuarters());
        $this->assertTrue($all->isDueIn(3));
        $this->assertTrue($some->isDueIn(2));
        $this->assertFalse($some->isDueIn(3));
        $this->assertSame([1, 2, 3, 4], (new Indicator(['due_quarters' => [9, 0]]))->dueQuarters(), 'nonsense falls back to every quarter');
    }

    public function test_the_form_saves_the_new_details_and_stores_nothing_for_every_quarter(): void
    {
        $this->actingAs($this->planner);
        $program = $this->program();
        $class = EconomicClassification::first();

        Volt::test('pages.activities.activity-form')
            ->set('program_id', (string) $program->id)->set('description', 'Inspect ports')->set('quarters', ['1'])
            ->set('lines', [['class_id' => (string) $class->id, 'amount' => '100']])
            ->set('indicators', [
                ['name' => 'Inspections', 'type' => 'Output', 'unit' => 'ports', 'measure' => 'cumulative', 'target' => '6', 'baseline' => '2', 'means' => 'Signed reports', 'quarters' => ['2', '4']],
                ['name' => 'Reports', 'type' => 'Output', 'unit' => 'reports', 'measure' => 'cumulative', 'target' => '4', 'quarters' => ['1', '2', '3', '4']],
            ])->call('save')->assertHasNoErrors();

        $a = Indicator::where('name', 'Inspections')->firstOrFail();
        $this->assertSame('2', $a->baseline);
        $this->assertSame('Signed reports', $a->means_of_verification);
        $this->assertSame('State Budget', LineItem::where('activity_id', $a->activity_id)->first()->fundingSource->name, 'a line without a chosen source takes the default');
        $this->assertSame([2, 4], $a->due_quarters);
        $this->assertNull(Indicator::where('name', 'Reports')->value('due_quarters'), 'every quarter is the default: nothing is stored');
    }

    public function test_editing_an_activity_keeps_its_indicators_and_their_progress(): void
    {
        $this->actingAs($this->planner);
        $program = $this->program();
        $activity = $this->activity($program, 'Draft');
        $class = EconomicClassification::first();
        $activity->lineItems()->create(['economic_classification_id' => $class->id, 'estimated_amount' => 100, 'is_test_data' => false]);
        $keep = Indicator::create(['activity_id' => $activity->id, 'name' => 'Keep', 'type' => 'Output', 'unit' => 'x', 'measure' => 'cumulative', 'annual_target' => 4, 'target_text' => 'four']);
        $drop = Indicator::create(['activity_id' => $activity->id, 'name' => 'Drop', 'type' => 'Output', 'unit' => 'x', 'measure' => 'cumulative', 'annual_target' => 2]);
        IndicatorProgress::create(['indicator_id' => $keep->id, 'quarter' => 1, 'achieved_value' => 1, 'status' => 'Verified']);

        $page = Volt::test('pages.activities.activity-form', ['activity' => $activity]);
        $rows = collect($page->get('indicators'))->reject(fn ($r) => $r['name'] === 'Drop')->values()
            ->map(fn ($r) => $r['name'] === 'Keep' ? array_merge($r, ['means' => 'Logs']) : $r)->all();
        $page->set('indicators', $rows)->call('save')->assertHasNoErrors();

        $this->assertSame($keep->id, Indicator::where('name', 'Keep')->value('id'), 'updated in place, not recreated');
        $this->assertSame('Logs', $keep->fresh()->means_of_verification);
        $this->assertSame('four', $keep->fresh()->target_text, 'what the form does not show survives an edit');
        $this->assertSame(1, IndicatorProgress::where('indicator_id', $keep->id)->count(), 'its progress is kept');
        $this->assertFalse(Indicator::whereKey($drop->id)->exists(), 'a removed row is deleted');
    }

    public function test_reports_that_are_due_and_not_yet_verified_are_counted_for_the_quarter(): void
    {
        $program = $this->program();
        $activity = $this->activity($program);
        $draft = $this->activity($program, 'Draft');
        $mk = fn ($act, $name, $due) => Indicator::create(['activity_id' => $act->id, 'name' => $name, 'type' => 'Output', 'unit' => 'x', 'measure' => 'cumulative', 'annual_target' => 4, 'due_quarters' => $due]);
        $everyQuarter = $mk($activity, 'A', null);
        $mk($activity, 'B', [1, 3]);          // not due in Q2
        $reported = $mk($activity, 'C', [2]);
        $mk($draft, 'D', null);               // not approved: not counted
        IndicatorProgress::create(['indicator_id' => $reported->id, 'quarter' => 2, 'achieved_value' => 1, 'status' => 'Verified']);
        IndicatorProgress::create(['indicator_id' => $everyQuarter->id, 'quarter' => 2, 'achieved_value' => 1, 'status' => 'Submitted']); // submitted is not verified

        $ids = DB::table('activities')->where('fiscal_year', $this->year)->select('id');

        $this->assertSame(1, app(DeliveryReport::class)->dueWithoutFigure($ids, 2), 'only A: B is not due, C is verified, D is a draft');
        $this->assertSame(2, app(DeliveryReport::class)->dueWithoutFigure(DB::table('activities')->where('fiscal_year', $this->year)->select('id'), 3), 'A and B are due in Q3');
    }

    // ── programmes and subprogrammes

    public function test_an_administrator_adds_objectives_and_result_indicators_to_a_programme(): void
    {
        $this->admin();
        $program = $this->program();

        $page = Volt::test('pages.management.structure-details', ['type' => 'program', 'id' => $program->id])
            ->set('framework', 'SDG')->set('reference', 'SDG 9')->set('description', 'Industry, innovation and infrastructure')->call('saveAlignment')->assertHasNoErrors()
            ->set('indName', '% of institutions connected')->set('indBaseline', '20%')->set('indTarget', '100%')->set('indMeans', 'Network inventory')->call('saveIndicator')->assertHasNoErrors();

        $this->assertSame('SDG 9', $program->alignments()->first()->reference);
        $ri = $program->resultIndicators()->firstOrFail();
        $this->assertSame('20%', $ri->baseline);

        $page->call('editIndicator', $ri->id)->assertSet('indName', '% of institutions connected')->set('indTarget', '90%')->call('saveIndicator');
        $this->assertSame('90%', $ri->fresh()->target);
        $this->assertSame(1, $program->resultIndicators()->count(), 'editing does not add another');

        $page->call('deleteIndicator', $ri->id)->call('deleteAlignment', $program->alignments()->first()->id);
        $this->assertSame(0, ResultIndicator::count() + Alignment::count());
    }

    public function test_objectives_need_a_known_framework_and_a_reference_and_only_administrators_edit_them(): void
    {
        $program = $this->program();
        $sub = $program->subprograms()->create(['code' => '51001', 'name' => 'Operation']);

        $this->admin();
        Volt::test('pages.management.structure-details', ['type' => 'subprogram', 'id' => $sub->id])
            ->set('framework', 'Nonsense')->set('reference', 'X')->call('saveAlignment')->assertHasErrors('framework')
            ->set('framework', 'PED')->set('reference', '')->call('saveAlignment')->assertHasErrors('reference');
        $this->assertSame(0, Alignment::count());

        $this->actingAs($this->planner);
        Volt::test('pages.management.structure-details', ['type' => 'program', 'id' => $program->id])->assertForbidden();
    }

    public function test_copying_a_year_brings_the_objectives_and_result_indicators_and_deleting_removes_them(): void
    {
        $old = $this->program($this->year - 1);
        $old->alignments()->create(['framework' => 'SDG', 'reference' => 'SDG 16']);
        $old->resultIndicators()->create(['name' => 'Trust in institutions', 'target' => '80%']);
        $sub = $old->subprograms()->create(['code' => '51001', 'name' => 'Operation']);
        $sub->alignments()->create(['framework' => 'PED', 'reference' => 'PED 2011-2030']);

        app(ProgramYears::class)->copy($this->year - 1, $this->year);

        $new = Program::forYear($this->year)->where('oge_code', '510')->firstOrFail();
        $this->assertSame(['SDG 16'], $new->alignments()->pluck('reference')->all());
        $this->assertSame(['Trust in institutions'], $new->resultIndicators()->pluck('name')->all());
        $this->assertSame(['PED 2011-2030'], $new->subprograms()->first()->alignments()->pluck('reference')->all());

        $new->delete();
        $this->assertSame(2, Alignment::count(), 'only the old year keeps its own');
        $this->assertSame(1, ResultIndicator::count());
    }

    public function test_the_sample_programmes_carry_objectives_and_the_activity_page_shows_them(): void
    {
        Carbon::setTestNow('2026-10-14 10:00:00');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        $this->admin();

        $program = Program::forYear(2026)->where('oge_code', 'A08')->firstOrFail();
        $this->assertTrue($program->alignments()->where('reference', 'SDG 9')->exists());

        $activity = Activity::where('fiscal_year', 2026)->where('program_id', $program->id)->firstOrFail();
        Volt::test('pages.activities.show', ['activity' => $activity])->assertSee('Contributes to')->assertSee('SDG 9');

        $indicator = Indicator::whereNotNull('means_of_verification')->firstOrFail();
        $this->assertNotNull($indicator->means_of_verification);
        Carbon::setTestNow();
    }
}
