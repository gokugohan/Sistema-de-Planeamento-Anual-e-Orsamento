<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\Program;
use App\Models\ReportingWindow;
use App\Models\User;
use App\Services\ApprovalInbox;
use App\Support\FiscalYear;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two plans are alive at once: in October 2026 the 2026 plan is carried out and reported on, and the 2027 plan is being
 * prepared. Each page opens on its own year without anybody choosing it, and another year asked for on one page changes
 * nothing elsewhere.
 */
class PageYearTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Directorate $unit;

    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        app()->setLocale('en');
        $this->seed(DatabaseSeeder::class);
        $this->unit = Directorate::create(['code' => 'DNTT', 'name' => 'Terrestres', 'type' => 'National Directorate', 'active' => true]);
        $this->program = Program::create(['oge_code' => 'P1', 'name' => 'Transportes', 'fiscal_year' => 2026]);
        $this->admin = User::where('email', 'admin@example.org')->firstOrFail();
        $this->actingAs($this->admin);

        $this->activity(2026, Activity::STATUS_APPROVED, 1); // the plan in force
        $this->activity(2027, Activity::STATUS_DRAFT, 0);    // the plan being prepared
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function activity(int $year, string $status, int $version): Activity
    {
        return Activity::create([
            'fiscal_year' => $year, 'program_id' => $this->program->id, 'directorate_id' => $this->unit->id, 'description' => "Activity $year $status",
            'quarter' => 1, 'priority' => 'Alta', 'planned_start' => "$year-01-01", 'planned_end' => "$year-12-31", 'status' => $status, 'version' => $version,
        ]);
    }

    public function test_the_plan_carried_out_and_the_plan_prepared_are_known_without_choosing(): void
    {
        $this->assertSame(2026, FiscalYear::execution());
        $this->assertSame(2027, FiscalYear::planning());
    }

    public function test_planning_pages_open_on_the_plan_being_prepared_and_reporting_pages_on_the_plan_carried_out(): void
    {
        $this->get(route('activities.index'))->assertOk()->assertSee('Planning 2027')->assertDontSee('You are looking at');
        $this->get(route('monitoring.report'))->assertOk()->assertSee('Reporting 2026')->assertDontSee('You are looking at');
    }

    public function test_another_year_on_one_page_says_so_and_changes_nothing_elsewhere(): void
    {
        $this->get(route('activities.index', ['year' => 2026]))->assertOk()
            ->assertSee('Planning 2026')->assertSee('You are looking at 2026. This page normally shows 2027')->assertSee('Back to 2027');

        // the next page, and the same page without the year, are back on their own years
        $this->get(route('monitoring.report'))->assertOk()->assertSee('Reporting 2026')->assertDontSee('You are looking at');
        $this->get(route('activities.index'))->assertOk()->assertSee('Planning 2027')->assertDontSee('You are looking at');
    }

    public function test_open_submission_dates_name_the_planning_year(): void
    {
        Activity::where('fiscal_year', 2027)->delete();
        $this->assertSame(2027, FiscalYear::planning(), 'the 2026 plan is approved: next is 2027');

        Activity::where('fiscal_year', 2026)->update(['version' => 0, 'status' => Activity::STATUS_DRAFT]);
        $this->assertSame(2026, FiscalYear::planning(), 'nothing approved yet, nothing prepared for 2027: still planning 2026');

        ReportingWindow::create(['fiscal_year' => 2027, 'period_type' => 'plan', 'period' => 1, 'opens_on' => '2026-10-01', 'closes_on' => '2026-11-30']);
        $this->assertSame(2027, FiscalYear::planning(), 'the open submission dates say which plan is being prepared');
    }

    public function test_the_inbox_covers_both_plans(): void
    {
        $this->activity(2026, Activity::STATUS_SUBMITTED, 1); // a revision of the plan in force
        $this->activity(2027, Activity::STATUS_SUBMITTED, 0); // the plan being prepared

        $texts = collect(app(ApprovalInbox::class)->items($this->admin))->pluck('text');
        $this->assertContains('2 activities prepared, waiting to be certified', $texts->all());

        $one = collect(app(ApprovalInbox::class)->items($this->admin, 2027))->pluck('text');
        $this->assertContains('1 activity prepared, waiting to be certified', $one->all(), 'a year asked for narrows it');
    }

    public function test_pages_without_a_year_show_no_year_bar(): void
    {
        $this->get(route('management.users'))->assertOk()->assertDontSee('data-year-bar', false);
        $this->get(route('activities.index'))->assertOk()->assertSee('data-year-bar', false);
    }
}
