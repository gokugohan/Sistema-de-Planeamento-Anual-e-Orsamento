<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityReport;
use App\Models\AuditLog;
use App\Models\Directorate;
use App\Models\User;
use App\Services\ExecutionReport;
use App\Services\PerformanceReport;
use App\Support\FiscalYear;
use App\Support\ReportScope;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PhpOffice\PhpSpreadsheet\IOFactory;
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
        FiscalYear::pin(2026);
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
        $months = collect([7, 8, 9])->sum(fn ($m) => app(PerformanceReport::class)->build(2026, 'month', $m, $this->chief())['totals']['executed']);

        $this->assertGreaterThan(0, $quarter['totals']['activities']);
        $this->assertEqualsWithDelta($months, $quarter['totals']['executed'], 0.05);
        $this->assertEqualsWithDelta($quarter['totals']['executed'], $quarter['tree']->sum('executed'), 0.05);
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
            ->assertSee('Program')->assertSee('VERIFIED TEXT')->assertSee('LATE FUNDS')->assertDontSee('DRAFT TEXT');
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

    public function test_a_unit_can_be_chosen_and_the_excel_has_the_same_totals(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);
        $udig = Directorate::where('code', 'UDIG')->firstOrFail();

        $whole = app(PerformanceReport::class)->build(2026, 'quarter', 3, $admin);
        $unit = app(PerformanceReport::class)->build(2026, 'quarter', 3, $admin, ReportScope::for($admin, $udig->id));
        $this->assertGreaterThan(0, $unit['totals']['executed']);
        $this->assertLessThan($whole['totals']['approved'], $unit['totals']['approved'], 'one unit is part of the whole');
        $this->assertSame('PERF-2026-Q03-UDIG', $unit['reference']);

        $this->get(route('reports.performance', ['year' => 2026, 'type' => 'quarter', 'period' => 3, 'unit' => $udig->id]))
            ->assertOk()->assertSee('UDIG — '.$udig->name)->assertSee('Download Excel')->assertSee('All units');

        $response = $this->get(route('reports.performance.xlsx', ['year' => 2026, 'type' => 'quarter', 'period' => 3, 'unit' => $udig->id]));
        $response->assertOk()->assertDownload('PERF-2026-Q03-UDIG.xlsx');
        $file = tempnam(sys_get_temp_dir(), 'perf').'.xlsx';
        file_put_contents($file, $response->streamedContent());
        $rows = collect(IOFactory::load($file)->getActiveSheet()->toArray(null, true, false, false));
        @unlink($file);
        $total = $rows->first(fn ($r) => ($r[0] ?? null) === 'TOTAL EXECUTION');
        $this->assertEqualsWithDelta($unit['totals']['approved'], $total[9], 0.05, 'approved: a formula over the activities');
        $this->assertEqualsWithDelta($unit['totals']['executed'], $total[10], 0.05, 'executed in the quarter');
        $this->assertSame('UDIG', AuditLog::where('action', 'report.performance_xlsx')->first()->metadata['unit']);
    }

    public function test_a_unit_director_cannot_choose_another_unit_and_the_performance_page_is_theirs_only(): void
    {
        $director = User::where('email', 'director.udig@gdsc.tl')->firstOrFail();
        $usc = Directorate::where('code', 'USC')->firstOrFail();
        $this->actingAs($director);

        $this->get(route('reports.performance', ['year' => 2026, 'unit' => $usc->id]))->assertForbidden();
        $this->get(route('reports.performance.xlsx', ['year' => 2026, 'unit' => $usc->id]))->assertForbidden();

        // the Performance page shows their unit's execution, not the institution's
        $own = app(ExecutionReport::class);
        $mine = $own->summary($own->load(2026, Directorate::find($director->directorate_id)->getSelfAndDescendantIds()));
        $all = $own->summary($own->load(2026));
        $this->assertNotEquals($all['approved'], $mine['approved']);
        Volt::test('pages.monitoring.execution-dashboard')
            ->assertDontSee('All units')
            ->assertViewHas('summary', fn ($s) => abs($s['approved'] - $mine['approved']) < 0.01);
    }
}
