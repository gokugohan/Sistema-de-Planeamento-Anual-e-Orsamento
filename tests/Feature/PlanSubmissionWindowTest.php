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
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** The annual plan is submitted only inside its window, by everybody, administrators included; without dates activities stay drafts. */
#[Group('office')]
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

    public function test_where_the_rule_is_off_the_planner_submits_without_a_window(): void
    {
        config(['paa.submission_needs_dates' => false]); // as the other automated tests run
        $this->assertSame(Activity::STATUS_SUBMITTED, app(ActivityWorkflow::class)->next($this->planner, $this->draft->fresh()));
    }

    public function test_without_submission_dates_the_planner_prepares_but_does_not_submit(): void
    {
        config(['paa.submission_needs_dates' => true]);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $this->assertNull(app(ActivityWorkflow::class)->next($this->planner, $this->draft->fresh()), 'no dates: no submission');
        $this->assertNull(app(ActivityWorkflow::class)->next($admin, $this->draft->fresh()), 'nor does an administrator: the dates hold for everybody');

        // drafts are still prepared: the form saves
        $this->actingAs($this->planner);
        Volt::test('pages.activities.activity-form', ['activity' => $this->draft])
            ->set('description', 'Prepared before the dates')->call('save')->assertNotDispatched('toast');

        // the activity says why, and the dashboard tells the planner and asks the administrator for the dates
        $this->get(route('activities.show', $this->draft))->assertOk()
            ->assertSee('Submission is closed')->assertSee('Submission of the 2027 plan has no dates yet');
        $this->assertNotEmpty(collect(app(\App\Services\CalendarAlerts::class)->for($this->planner, 2027))->filter(fn ($a) => str_contains($a['text'], 'has no dates yet')));
        $this->assertNotEmpty(collect(app(\App\Services\CalendarAlerts::class)->for($admin, 2027))->filter(fn ($a) => str_contains($a['text'], 'Set the dates in the Calendar')));

        $this->window('2026-10-01', '2026-10-31');
        $this->assertSame(Activity::STATUS_SUBMITTED, app(ActivityWorkflow::class)->next($this->planner, $this->draft->fresh()), 'with dates, inside them, it works');
    }

    public function test_testing_mode_needs_no_submission_dates(): void
    {
        config(['paa.submission_needs_dates' => true]);
        \App\Models\SystemSetting::set('enable_paa_testing_mode', true, 'boolean');

        $this->assertSame(Activity::STATUS_SUBMITTED, app(ActivityWorkflow::class)->next($this->planner, $this->draft->fresh()));
    }

    public function test_the_calendar_fills_the_reporting_dates_from_one_rule_and_approval_dates_cover_finalizing(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $page = Volt::actingAs($admin)->test('pages.management.reporting-windows')->set('year', 2027);

        $page->set('monthDays', 10)->set('quarterDays', 15)->call('fillReports')->assertHasNoErrors();
        $this->assertSame(16, ReportingWindow::where('fiscal_year', 2027)->whereIn('period_type', ['month', 'quarter'])->count());
        $feb = ReportingWindow::where(['fiscal_year' => 2027, 'period_type' => 'month', 'period' => 2])->firstOrFail();
        $this->assertSame(['2027-02-01', '2027-03-10'], [$feb->opens_on->toDateString(), $feb->closes_on->toDateString()], 'open from the first day, due 10 days after the month');
        $q4 = ReportingWindow::where(['fiscal_year' => 2027, 'period_type' => 'quarter', 'period' => 4])->firstOrFail();
        $this->assertSame(['2027-10-01', '2028-01-15'], [$q4->opens_on->toDateString(), $q4->closes_on->toDateString()]);

        $page->set('dates.approval_1.opens', '2027-01-01')->set('dates.approval_1.closes', '2027-02-28')->call('saveStep', 'approval_1')->assertHasNoErrors();
        $this->assertSame(2, ReportingWindow::where('fiscal_year', 2027)->whereIn('period_type', ['approval', 'finalize'])->count(), 'approving the plan finalizes it: same dates');

        $page->call('clearReports');
        $this->assertSame(0, ReportingWindow::where('fiscal_year', 2027)->whereIn('period_type', ['month', 'quarter'])->count());
    }

    public function test_outside_the_window_nobody_submits_not_even_an_administrator(): void
    {
        $this->window('2026-11-01', '2026-11-30'); // not open yet
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $this->assertNull(app(ActivityWorkflow::class)->next($this->planner, $this->draft->fresh()));
        $this->assertNull(app(ActivityWorkflow::class)->next($admin, $this->draft->fresh()), 'the dates hold for administrators too');

        ReportingWindow::query()->update(['opens_on' => '2026-10-01', 'closes_on' => '2026-10-31']);
        $this->assertSame(Activity::STATUS_SUBMITTED, app(ActivityWorkflow::class)->next($this->planner, $this->draft->fresh()), 'inside the window it works');
        $this->assertSame(Activity::STATUS_SUBMITTED, app(ActivityWorkflow::class)->next($admin, $this->draft->fresh()), 'for an administrator too');
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
            ->set('dates.plan_1.opens', '2026-10-01')->set('dates.plan_1.closes', '2026-10-31')->call('saveStep', 'plan_1')->assertHasNoErrors();

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
