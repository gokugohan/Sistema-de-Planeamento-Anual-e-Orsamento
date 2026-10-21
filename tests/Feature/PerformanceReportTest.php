<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityReport;
use App\Models\AuditLog;
use App\Models\Directorate;
use App\Models\User;
use App\Services\PerformanceReport;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** The compiled monthly and quarterly performance report, laid out like the printed one: verified figures, the units the reader may see. */
#[Group('office')]
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

    private function chief(): User
    {
        return User::where('email', 'chefe.gdsc@gdsc.tl')->firstOrFail();
    }

    public function test_the_quarter_is_the_sum_of_its_months_and_programmes_add_up_to_the_total(): void
    {
        $quarter = app(PerformanceReport::class)->build(2026, 'quarter', 3, $this->chief());
        $months = collect([7, 8, 9])->sum(fn ($m) => app(PerformanceReport::class)->build(2026, 'month', $m, $this->chief())['totals']['paid']);

        $this->assertGreaterThan(0, $quarter['totals']['activities']);
        $this->assertEqualsWithDelta($months, $quarter['totals']['paid'], 0.05);
        $this->assertEqualsWithDelta($quarter['totals']['paid'], $quarter['tree']->sum('paid'), 0.05);
        $this->assertEqualsWithDelta($quarter['totals']['approved'], $quarter['tree']->sum('approved'), 0.05);
    }

    public function test_a_unit_director_sees_only_their_own_units(): void
    {
        $director = User::where('email', 'director.udig@gdsc.tl')->firstOrFail();
        $ids = Directorate::find($director->directorate_id)->getSelfAndDescendantIds();

        $report = app(PerformanceReport::class)->build(2026, 'quarter', 3, $director);

        $rows = $report['tree']->flatMap(fn ($p) => $p['subprograms']->flatMap(fn ($s) => $s['rows'])->merge($p['loose']));
        $this->assertNotEmpty($rows);
        foreach ($rows as $r) {
            $this->assertContains($r['activity']->directorate_id, $ids);
        }
    }

    public function test_the_printed_layout_has_the_ministry_columns_and_only_verified_narrative(): void
    {
        $activity = Activity::where('fiscal_year', 2026)->where('status', 'Approved')->whereHas('lineItems.adjustedBudget')->firstOrFail();
        ActivityReport::create(['activity_id' => $activity->id, 'period_type' => 'month', 'period' => 9, 'results' => 'VERIFIED TEXT', 'constraints' => 'LATE FUNDS', 'status' => 'Verified']);
        ActivityReport::create(['activity_id' => $activity->id, 'period_type' => 'quarter', 'period' => 3, 'results' => 'DRAFT TEXT', 'status' => 'Draft']);

        $this->actingAs($this->chief())->get(route('reports.performance', ['year' => 2026, 'type' => 'month', 'period' => 9]))
            ->assertOk()->assertSee('Monthly performance report')
            ->assertSee('Performance indicators')->assertSee('Means of verification')->assertSee('Implementation constraints')->assertSee('TOTAL EXECUTION')
            ->assertSee('Programme')->assertSee('VERIFIED TEXT')->assertSee('LATE FUNDS')->assertDontSee('DRAFT TEXT');
    }

    public function test_the_pdf_downloads_and_is_audited(): void
    {
        $response = $this->actingAs($this->chief())->get(route('reports.performance.pdf', ['year' => 2026, 'type' => 'quarter', 'period' => 3]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertSame(1, AuditLog::where('action', 'report.performance_pdf')->count());
    }

    public function test_the_quarter_view_opens_and_a_user_without_reports_permission_is_refused(): void
    {
        $this->actingAs($this->chief())->get(route('reports.performance', ['type' => 'quarter', 'period' => 3]))->assertOk()->assertSee('Quarterly performance report');

        $nobody = User::factory()->create();
        $this->actingAs($nobody)->get(route('reports.performance'))->assertForbidden();
        $this->actingAs($nobody)->get(route('reports.performance.pdf'))->assertForbidden();
    }
}
