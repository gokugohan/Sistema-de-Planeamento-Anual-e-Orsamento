<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\Program;
use App\Models\ReportingWindow;
use App\Models\User;
use App\Services\ActivityWorkflow;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** The annual plan may be created, edited and submitted only inside its window; no window means open; administrators always can. */
class PlanSubmissionWindowTest extends TestCase
{
    use RefreshDatabase;

    private Activity $draft;

    private User $planner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        app()->setLocale('en');

        $unit = Directorate::where('code', 'UDIG')->firstOrFail();
        $this->draft = Activity::create([
            'fiscal_year' => 2027, 'program_id' => Program::firstOrFail()->id, 'directorate_id' => $unit->id, 'description' => 'Window test', 'quarter' => 1, 'priority' => 'Alta',
            'planned_start' => '2027-01-01', 'planned_end' => '2027-12-31', 'status' => Activity::STATUS_DRAFT,
        ]);
        $this->draft->lineItems()->create(['code' => 'W-1', 'economic_classification_id' => EconomicClassification::firstOrFail()->id, 'estimated_amount' => 100]);
        $this->draft->indicators()->create(['name' => 'Outputs', 'type' => 'Output', 'unit' => 'units', 'annual_target' => 5]);

        $this->planner = User::factory()->create(['directorate_id' => $unit->id]);
        $this->planner->assignRole('Planning');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function window(string $from, string $to): void
    {
        ReportingWindow::create(['fiscal_year' => 2027, 'period_type' => 'plan', 'period' => 1, 'opens_on' => $from, 'closes_on' => $to]);
    }

    public function test_without_a_window_the_planner_submits(): void
    {
        $this->assertSame(Activity::STATUS_SUBMITTED, app(ActivityWorkflow::class)->next($this->planner, $this->draft->fresh()));
    }

    public function test_outside_the_window_the_planner_cannot_submit_but_an_administrator_can(): void
    {
        $this->window('2026-11-01', '2026-11-30'); // not open yet
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $this->assertNull(app(ActivityWorkflow::class)->next($this->planner, $this->draft->fresh()));
        $this->assertSame(Activity::STATUS_SUBMITTED, app(ActivityWorkflow::class)->next($admin, $this->draft->fresh()));

        ReportingWindow::query()->update(['opens_on' => '2026-10-01', 'closes_on' => '2026-10-31']);
        $this->assertSame(Activity::STATUS_SUBMITTED, app(ActivityWorkflow::class)->next($this->planner, $this->draft->fresh()), 'inside the window it works');
    }

    public function test_the_form_refuses_to_save_outside_the_window_and_says_why(): void
    {
        $this->actingAs($this->planner);
        $this->window('2026-08-01', '2026-08-31'); // closed

        $page = Volt::test('pages.activities.activity-form', ['activity' => $this->draft])
            ->set('description', 'Changed too late')->call('save');

        $page->assertDispatched('toast')->assertSee('Submission of the annual plan closed on 31/08/2026.');
        $this->assertSame('Window test', $this->draft->fresh()->description);

        ReportingWindow::query()->update(['closes_on' => '2026-12-31']);
        // inside the window the save is no longer refused by the window (whatever else the form validates)
        Volt::test('pages.activities.activity-form', ['activity' => $this->draft->fresh()])
            ->set('description', 'Changed in time')->call('save')->assertNotDispatched('toast');
    }

    public function test_only_an_administrator_sets_a_plan_window_and_it_needs_no_period(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        Volt::actingAs($admin)->test('pages.management.reporting-windows')
            ->set('periodType', 'plan')->set('opensOn', '2026-10-01')->set('closesOn', '2026-10-31')->call('save')->assertHasNoErrors();

        $w = ReportingWindow::where('period_type', 'plan')->firstOrFail();
        $this->assertSame(1, (int) $w->period);
    }

    public function test_a_revision_is_not_held_back_by_the_closed_plan_window(): void
    {
        $this->window('2026-08-01', '2026-08-31'); // closed
        $this->draft->forceFill(['version' => 1, 'revision_reason' => 'Correct the target'])->save(); // an approved activity being revised

        $this->assertSame(Activity::STATUS_SUBMITTED, app(ActivityWorkflow::class)->next($this->planner, $this->draft->fresh()), 'the revision can be submitted');

        $this->actingAs($this->planner);
        Volt::test('pages.activities.activity-form', ['activity' => $this->draft->fresh()])
            ->set('description', 'Revised after the window closed')->call('save')->assertNotDispatched('toast');
    }

    public function test_a_new_activity_is_still_held_back_by_the_closed_plan_window(): void
    {
        $this->window('2026-08-01', '2026-08-31');

        $this->assertNull(app(ActivityWorkflow::class)->next($this->planner, $this->draft->fresh()));
    }
}
