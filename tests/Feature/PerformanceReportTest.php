<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityReport;
use App\Models\Directorate;
use App\Models\User;
use App\Services\PerformanceReport;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The compiled monthly and quarterly performance report: verified figures, the units the reader may see. */
class PerformanceReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        app()->setLocale('en');
        session(['fiscal_year' => 2026]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_month_report_adds_up_the_verified_figures_of_that_month(): void
    {
        $chief = User::where('email', 'chefe.gdsc@goku.tl')->firstOrFail();
        $report = app(PerformanceReport::class)->build(2026, 'month', 9, $chief);
        $quarter = app(PerformanceReport::class)->build(2026, 'quarter', 3, $chief);

        $this->assertGreaterThan(0, $report['totals']['activities']);
        $this->assertGreaterThan(0, $report['totals']['paid']);
        $this->assertEqualsWithDelta($report['totals']['paid_so_far'], $quarter['totals']['paid_so_far'], 0.01, 'September closes the third quarter');
        $this->assertGreaterThanOrEqual($report['totals']['paid'], $quarter['totals']['paid']);
    }

    public function test_a_unit_director_sees_only_their_own_units(): void
    {
        $director = User::where('email', 'director.udig@goku.tl')->firstOrFail();
        $ids = Directorate::find($director->directorate_id)->getSelfAndDescendantIds();

        $report = app(PerformanceReport::class)->build(2026, 'quarter', 3, $director);

        foreach ($report['units'] as $u) {
            $this->assertContains($u['unit']->id, $ids);
        }
        $this->assertNotEmpty($report['units']);
    }

    public function test_only_verified_narrative_appears(): void
    {
        $chief = User::where('email', 'chefe.gdsc@goku.tl')->firstOrFail();
        $activity = Activity::where('fiscal_year', 2026)->where('status', 'Approved')->whereHas('lineItems.adjustedBudget')->firstOrFail();
        ActivityReport::create(['activity_id' => $activity->id, 'period_type' => 'month', 'period' => 9, 'results' => 'VERIFIED TEXT', 'status' => 'Verified']);
        ActivityReport::create(['activity_id' => $activity->id, 'period_type' => 'quarter', 'period' => 3, 'results' => 'DRAFT TEXT', 'status' => 'Draft']);

        $this->actingAs($chief)->get(route('reports.performance', ['year' => 2026, 'type' => 'month', 'period' => 9]))
            ->assertOk()->assertSee('Monthly performance report')->assertSee('VERIFIED TEXT')->assertDontSee('DRAFT TEXT');
    }

    public function test_the_quarter_view_opens_and_a_viewer_without_reports_permission_is_refused(): void
    {
        $chief = User::where('email', 'chefe.gdsc@goku.tl')->firstOrFail();
        $this->actingAs($chief)->get(route('reports.performance', ['type' => 'quarter', 'period' => 3]))->assertOk()->assertSee('Quarterly performance report');

        $nobody = User::factory()->create();
        $this->actingAs($nobody)->get(route('reports.performance'))->assertForbidden();
    }
}
