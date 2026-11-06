<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\PlanningWindow;
use App\Services\YearAtAGlance;
use App\Support\FiscalYear;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\Samples\MtcSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Which years can be planned (the current and the next; any in testing mode), what the year at a glance and the
 * dashboard say when a year has no plan, and the three states the sample plans can be loaded in.
 */
class PlanningYearsAndSampleStatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 10:00:00');
        app()->setLocale('en');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        return $admin;
    }

    public function test_only_the_current_and_the_next_year_take_new_activities_unless_testing_mode_is_on(): void
    {
        $this->assertFalse(PlanningWindow::yearAllowed(2025));
        $this->assertTrue(PlanningWindow::yearAllowed(2026));
        $this->assertTrue(PlanningWindow::yearAllowed(2027));
        $this->assertFalse(PlanningWindow::yearAllowed(2028));
        $this->assertSame('Year', FiscalYear::planningEndedBy(2025));
        $this->assertNull(FiscalYear::planningEndedBy(2027));
        $this->assertFalse(FiscalYear::planningOpen(2028));

        SystemSetting::set('enable_paa_testing_mode', true, 'boolean');
        $this->assertTrue(PlanningWindow::yearAllowed(2020));
        $this->assertTrue(FiscalYear::planningOpen(2030));
        $this->assertContains(2021, FiscalYear::available());
    }

    public function test_with_no_plan_reporting_has_not_started_and_the_dashboard_says_how_to_begin(): void
    {
        $admin = $this->admin();

        $glance = app(YearAtAGlance::class)->for($admin, 2026);
        $this->assertSame(['Planning'], $glance['now']);
        $reporting = collect($glance['phases'])->firstWhere('key', 'reporting');
        $this->assertSame('soon', $reporting['state']);
        $this->assertNull($reporting['cta']);

        $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertSee('There is no plan for 2026 yet')
            ->assertSee('It is the last quarter of 2026')
            ->assertSee('Plan 2027')
            ->assertDontSee('Money by economic category');
    }

    public function test_the_sample_loads_the_current_and_next_year_as_drafts_and_an_earlier_year_cannot_be_planned(): void
    {
        app(MtcSampleDataSeeder::class)->setContainer(app())->run('draft');

        $statuses = fn (int $year) => Activity::where('fiscal_year', $year)->distinct()->pluck('status')->all();
        $this->assertSame([Activity::STATUS_APPROVED], $statuses(2025));
        $this->assertSame([Activity::STATUS_DRAFT], $statuses(2026));
        $this->assertSame([Activity::STATUS_DRAFT], $statuses(2027));
        $this->assertSame(0, Activity::where('fiscal_year', 2026)->where('version', '>', 0)->count());
        $executed = fn (int $year) => DB::table('budget_executions as e')->join('line_items as l', 'l.id', '=', 'e.line_item_id')
            ->join('activities as a', 'a.id', '=', 'l.activity_id')->where('a.fiscal_year', $year)->count();
        $this->assertGreaterThan(0, $executed(2025));
        $this->assertSame(0, $executed(2026));

        // 2025 has a plan, so it can be chosen, but it takes no new activities; testing mode lifts that and says so
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        \App\Support\FiscalYear::pin(2025);
        $this->actingAs($admin);
        $this->get(route('activities.create'))->assertOk()->assertSee('2025 cannot be planned')->assertSee('2026 or 2027');
        SystemSetting::set('enable_paa_testing_mode', true, 'boolean');
        $this->get(route('activities.create'))->assertOk()->assertDontSee('cannot be planned')->assertSee('Testing mode is on:');
    }

    public function test_halfway_puts_each_directorate_at_one_stage_with_one_returned_activity(): void
    {
        app(MtcSampleDataSeeder::class)->setContainer(app())->run('halfway');

        $year = Activity::where('fiscal_year', 2027)->get();
        $byStatus = $year->groupBy('status');
        foreach ([Activity::STATUS_DRAFT, Activity::STATUS_SUBMITTED, Activity::STATUS_VALIDATED, Activity::STATUS_APPROVED, Activity::STATUS_REJECTED] as $status) {
            $this->assertTrue($byStatus->has($status), "no {$status} activity in 2027");
        }
        // every activity of a directorate is at the same stage (its returned one aside)
        $window = app(PlanningWindow::class);
        $year->where('status', '!=', Activity::STATUS_REJECTED)->groupBy(fn ($a) => $window->rootOf((int) $a->directorate_id))
            ->each(fn ($g) => $this->assertCount(1, $g->pluck('status')->unique()));
        // approved ones are version 1, the others are not; the returned one carries its reason in the audit trail
        $this->assertTrue($byStatus[Activity::STATUS_APPROVED]->every(fn ($a) => $a->version === 1));
        $this->assertTrue($byStatus[Activity::STATUS_DRAFT]->every(fn ($a) => $a->version === 0));
        $this->assertTrue(DB::table('audit_logs')->where('auditable_id', $byStatus[Activity::STATUS_REJECTED]->first()->id)
            ->where('metadata', 'like', '%split the budget by quarter%')->exists());
    }

    public function test_only_the_chosen_years_of_the_sample_are_loaded(): void
    {
        app(\Database\Seeders\Samples\MtcOfficeSampleDataSeeder::class)->setContainer(app())->run(null, 'draft', [2027]);

        $this->assertSame([2027], Activity::distinct()->pluck('fiscal_year')->map(fn ($y) => (int) $y)->all());
        $this->assertSame([2027], \App\Models\Program::distinct()->pluck('fiscal_year')->map(fn ($y) => (int) $y)->all(), 'programs follow the years');
    }

    public function test_a_year_outside_the_sample_takes_the_plan_of_the_nearest_sample_year(): void
    {
        app(\Database\Seeders\Samples\MtcOfficeSampleDataSeeder::class)->setContainer(app())->run(null, 'draft', range(2027, 2028));

        $count = fn (int $year) => Activity::where('fiscal_year', $year)->count();
        $this->assertGreaterThan(0, $count(2028));
        $this->assertSame($count(2027), $count(2028), '2028 takes the plan of 2027');
        $this->assertSame('2028-01-01', Activity::where('fiscal_year', 2028)->value('planned_start')?->toDateString() ?? substr((string) Activity::where('fiscal_year', 2028)->value('planned_start'), 0, 10));
    }

    public function test_an_office_borrows_the_nearest_year_that_has_a_plan_for_it_and_every_year_can_be_chosen(): void
    {
        app(\Database\Seeders\Samples\MtcOfficeSampleDataSeeder::class)->setContainer(app())->run(null, 'draft', range(2024, 2026));

        $count = fn (int $year) => Activity::where('fiscal_year', $year)->count();
        $this->assertGreaterThan(0, $count(2026));
        $this->assertSame($count(2026), $count(2024), 'the office has no 2025 plan in the books: 2024 borrows 2026');
        $this->assertSame($count(2026), $count(2025));
        foreach ([2024, 2025, 2026] as $year) {
            $this->assertContains($year, FiscalYear::available());
        }
    }
}
