<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\Indicator;
use App\Models\LineItem;
use App\Models\Program;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The implementation plan as the ministry's form has it: under each indicator several actions, each with its months;
 * and the budget allocated per indicator, the sum of the lines tied to it.
 */
class ImplementationPlanTest extends TestCase
{
    use RefreshDatabase;

    private int $year;

    private User $planner;

    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app()->setLocale('en');
        $this->year = \App\Support\FiscalYear::current();
        $unit = Directorate::create(['name' => 'Unit', 'code' => 'UNT', 'type' => 'General Directorate', 'active' => true]);
        $this->planner = User::factory()->create(['directorate_id' => $unit->id]);
        $this->planner->assignRole('Planning');
        $this->program = Program::create(['fiscal_year' => $this->year, 'oge_code' => '510', 'name' => 'Programme']);
        $this->actingAs($this->planner);
    }

    private function ind(array $over = []): array
    {
        return $over + ['name' => 'Inspections', 'type' => 'Output', 'unit' => 'ports', 'measure' => 'cumulative', 'target' => '6', 'quarters' => ['1', '2', '3', '4'], 'actions' => []];
    }

    private function form(array $indicators, array $lines = null, array $quarters = ['1', '2', '3', '4'])
    {
        $class = EconomicClassification::first();

        return Volt::test('pages.activities.activity-form')
            ->set('program_id', (string) $this->program->id)->set('description', 'Plan test '.uniqid())->set('quarters', $quarters)
            ->set('lines', $lines ?? [['class_id' => (string) $class->id, 'amount' => '100', 'indicator' => '']])
            ->set('indicators', $indicators);
    }

    public function test_several_actions_each_with_its_months_and_the_quarters_follow(): void
    {
        $this->form([$this->ind(['actions' => [
            ['text' => 'Study the southern ports', 'months' => ['3', '6']],
            ['text' => 'Consult the municipalities', 'months' => ['2', '7']],
            ['text' => 'Hire the team', 'months' => []],
        ]])])->call('save')->assertHasNoErrors();

        $i = Indicator::where('name', 'Inspections')->with('actions')->firstOrFail();
        $this->assertSame(['Study the southern ports', 'Consult the municipalities', 'Hire the team'], $i->actions->pluck('text')->all());
        $this->assertSame([3, 6], $i->actions[0]->monthList());
        $this->assertSame([], $i->actions[2]->monthList());
        $this->assertSame([2, 3, 6, 7], $i->plannedMonths());
        $this->assertSame([1, 2, 3], $i->actions->pluck("position")->all());
        $this->assertSame([1, 2, 3], $i->dueQuarters(), 'reported in the quarters of the months');
        $this->assertSame('Study the southern ports; Consult the municipalities; Hire the team', $i->implementationText());
    }

    public function test_without_months_the_plan_is_the_months_of_its_quarters(): void
    {
        $this->form([$this->ind(['quarters' => ['2', '4']])])->call('save')->assertHasNoErrors();

        $i = Indicator::where('name', 'Inspections')->with('actions')->firstOrFail();
        $this->assertFalse($i->hasMonthPlan());
        $this->assertSame([4, 5, 6, 10, 11, 12], $i->plannedMonths());
    }

    public function test_an_action_needs_text_and_its_months_must_fall_in_the_quarters_of_the_activity(): void
    {
        $this->form([$this->ind(['actions' => [['text' => '', 'months' => []]]])])->call('save')->assertHasErrors('indicators.0.actions.0.text');
        $this->form([$this->ind(['actions' => [['text' => 'Late work', 'months' => ['11']]]])], null, ['1', '2'])->call('save')->assertHasErrors('indicators.0.actions.0.months');

        $this->assertSame(0, Indicator::where('name', 'Inspections')->count());
    }

    public function test_the_budget_can_be_allocated_per_indicator(): void
    {
        $c = (string) EconomicClassification::first()->id;
        $this->form(
            [$this->ind(['name' => 'First']), $this->ind(['name' => 'Second'])],
            [['class_id' => $c, 'amount' => '100', 'indicator' => '0'], ['class_id' => $c, 'amount' => '250', 'indicator' => '1'], ['class_id' => $c, 'amount' => '40', 'indicator' => '']]
        )->call('save')->assertHasNoErrors();

        $first = Indicator::where('name', 'First')->firstOrFail();
        $second = Indicator::where('name', 'Second')->firstOrFail();
        $this->assertEquals(100, LineItem::where('indicator_id', $first->id)->sum('estimated_amount'));
        $this->assertEquals(250, LineItem::where('indicator_id', $second->id)->sum('estimated_amount'));
        $this->assertEquals(40, LineItem::whereNull('indicator_id')->where('activity_id', $first->activity_id)->sum('estimated_amount'), 'the rest belongs to the whole activity');
    }

    public function test_a_line_cannot_point_to_an_indicator_that_is_not_there_and_removing_one_frees_its_lines(): void
    {
        $c = (string) EconomicClassification::first()->id;
        $this->form([$this->ind()], [['class_id' => $c, 'amount' => '100', 'indicator' => '5']])->call('save')->assertHasErrors('lines.0.indicator');

        $page = $this->form([$this->ind(['name' => 'A']), $this->ind(['name' => 'B'])], [['class_id' => $c, 'amount' => '10', 'indicator' => '0'], ['class_id' => $c, 'amount' => '20', 'indicator' => '1']]);
        $page->call('removeIndicator', 0);
        $this->assertSame('', $page->get('lines')[0]['indicator'], 'its lines go back to the whole activity');
        $this->assertSame('0', $page->get('lines')[1]['indicator'], 'later indicators move up');
    }

    public function test_editing_shows_the_saved_plan_and_the_page_and_panel_mention_it(): void
    {
        $this->form([$this->ind(['actions' => [['text' => 'Open the tender', 'months' => ['4', '5']], ['text' => 'Award it', 'months' => ['6']]]])])->call('save');
        $activity = Activity::where('description', 'like', 'Plan test%')->firstOrFail();

        $edit = Volt::test('pages.activities.activity-form', ['activity' => $activity]);
        $this->assertSame(['4', '5'], $edit->get('indicators')[0]['actions'][0]['months']);
        $this->assertSame('Award it', $edit->get('indicators')[0]['actions'][1]['text']);

        Volt::test('pages.activities.show', ['activity' => $activity])->assertSee('Open the tender')->assertSee('Apr, May')->assertSee('Award it');

        $activity->forceFill(['status' => Activity::STATUS_APPROVED])->saveQuietly();
        Volt::test('pages.monitoring.execution-progress', ['activityId' => $activity->id, 'quarter' => 2])->assertSee('Planned for Q2')->assertSee('Open the tender');
    }
}
