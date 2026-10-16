<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\FiscalEnvelope;
use App\Models\Indicator;
use App\Models\Program;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** The implementation plan of an indicator: what will be done and in which months; its quarters follow from the months. */
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
        $this->year = FiscalEnvelope::getEffectiveYear();
        $unit = Directorate::create(['name' => 'Unit', 'code' => 'UNT', 'type' => 'General Directorate', 'active' => true]);
        $this->planner = User::factory()->create(['directorate_id' => $unit->id]);
        $this->planner->assignRole('Planning');
        $this->program = Program::create(['fiscal_year' => $this->year, 'oge_code' => '510', 'name' => 'Programme']);
        $this->actingAs($this->planner);
    }

    private function form(array $indicator, array $quarters = ['1', '2', '3', '4'])
    {
        $class = EconomicClassification::first();

        return Volt::test('pages.activities.activity-form')
            ->set('program_id', (string) $this->program->id)->set('description', 'Plan test '.uniqid())->set('quarters', $quarters)
            ->set('lines', [['class_id' => (string) $class->id, 'amount' => '100']])
            ->set('indicators', [$indicator + ['name' => 'Inspections', 'type' => 'Output', 'unit' => 'ports', 'measure' => 'cumulative', 'target' => '6', 'quarters' => ['1', '2', '3', '4']]]);
    }

    public function test_the_months_and_the_text_are_saved_and_the_quarters_follow(): void
    {
        $this->form(['implementation' => 'Inspect the southern ports', 'months' => ['2', '3', '7']])->call('save')->assertHasNoErrors();

        $i = Indicator::where('name', 'Inspections')->firstOrFail();
        $this->assertSame('Inspect the southern ports', $i->implementation);
        $this->assertSame([2, 3, 7], $i->plan_months);
        $this->assertSame([1, 3], $i->due_quarters, 'reported in the quarters of its months');
        $this->assertSame([2, 3, 7], $i->plannedMonths());
    }

    public function test_without_months_the_plan_is_the_months_of_its_quarters(): void
    {
        $this->form(['quarters' => ['2', '4']])->call('save')->assertHasNoErrors();

        $i = Indicator::where('name', 'Inspections')->firstOrFail();
        $this->assertNull($i->plan_months);
        $this->assertFalse($i->hasMonthPlan());
        $this->assertSame([4, 5, 6, 10, 11, 12], $i->plannedMonths());
    }

    public function test_a_month_outside_the_quarters_of_the_activity_is_refused(): void
    {
        $this->form(['months' => ['11']], ['1', '2'])->call('save')->assertHasErrors('indicators.0.months');

        $this->assertSame(0, Indicator::where('name', 'Inspections')->count());
    }

    public function test_editing_shows_the_saved_plan_and_the_page_and_panel_mention_it(): void
    {
        $this->form(['implementation' => 'Open the tender', 'months' => ['4', '5']])->call('save');
        $activity = Activity::where('description', 'like', 'Plan test%')->firstOrFail();

        $edit = Volt::test('pages.activities.activity-form', ['activity' => $activity]);
        $this->assertSame(['4', '5'], $edit->get('indicators')[0]['months']);
        $this->assertSame('Open the tender', $edit->get('indicators')[0]['implementation']);

        Volt::test('pages.activities.show', ['activity' => $activity])->assertSee('Planned: Open the tender')->assertSee('Months: Apr, May');

        $activity->forceFill(['status' => Activity::STATUS_APPROVED])->saveQuietly();
        Volt::test('pages.monitoring.execution-progress', ['activityId' => $activity->id, 'quarter' => 2])->assertSee('Planned for Q2')->assertSee('Open the tender');
    }
}
