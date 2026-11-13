<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Directorate;
use App\Models\LineItem;
use App\Models\Program;
use App\Models\User;
use App\Services\ImplementationPlanReport;
use App\Support\FiscalYear;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** The PAA in the ministry's form, organised by programme across the units the reader may see. */
#[Group('office')]
class PlanByProgramReportTest extends TestCase
{
    use RefreshDatabase;

    private User $head;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        // the real DGC plan of 2026: four programmes (510, 558, A08, A38) over four units
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run('DGC');
        app()->setLocale('en');
        FiscalYear::pin(2026);
        $this->head = User::where('email', 'chefe.dgc@gdsc.tl')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_every_programme_holds_the_activities_of_all_units_with_the_same_total(): void
    {
        $report = app(ImplementationPlanReport::class)->byProgram($this->head, 2026, null);

        $this->assertSame(['510', '558', 'A08', 'A38'], $report['tree']->map(fn ($p) => $p['program']->oge_code)->all());
        $units = $report['tree']->flatMap(fn ($p) => $p['subprograms']->flatMap(fn ($s) => $s['rows'])->merge($p['loose']))
            ->map(fn ($r) => $r['activity']->directorate_id)->unique();
        $this->assertGreaterThan(1, $units->count(), 'several units in one document');

        $approved = Activity::where('fiscal_year', 2026)->where('status', Activity::STATUS_APPROVED)->pluck('id');
        $this->assertEqualsWithDelta((float) LineItem::whereIn('activity_id', $approved)->sum('estimated_amount'), $report['total'], 0.01);
        $this->assertSame('PAA-PROG-2026-ALL', $report['reference']);
    }

    public function test_one_programme_can_be_printed_alone(): void
    {
        $a08 = Program::forYear(2026)->where('oge_code', 'A08')->firstOrFail();
        $report = app(ImplementationPlanReport::class)->byProgram($this->head, 2026, $a08->id);

        $this->assertSame(['A08'], $report['tree']->map(fn ($p) => $p['program']->oge_code)->all());
        $this->assertSame('PAA-PROG-2026-A08', $report['reference']);
        $this->assertEqualsWithDelta(1976747, $report['total'], 1, 'DGC programme A08 in the 2026 book');
    }

    public function test_the_page_names_each_unit_and_puts_each_programme_on_its_own_page(): void
    {
        $html = $this->actingAs($this->head)->get(route('reports.plan-by-program', ['year' => 2026]))->assertOk()
            ->assertSee('Implementation plan of activities by quarter')
            ->assertSee('PAA by program')
            ->assertSee('All programs')
            ->assertSee(route('reports.plan-by-program.pdf', ['year' => 2026]), false)
            ->getContent();

        $this->assertSame(3, substr_count($html, 'class="rep newpage"'), 'four programmes: three start a new page');
        $unit = Activity::where('fiscal_year', 2026)->with('directorate')->firstOrFail()->directorate;
        $this->assertStringContainsString($unit->code.' — '.e($unit->name), $html);
    }

    public function test_a_unit_sees_only_its_own_activities(): void
    {
        $unit = Directorate::where('code', 'DNIC')->firstOrFail();
        $director = User::where('email', 'director.dnic@gdsc.tl')->firstOrFail();

        $report = app(ImplementationPlanReport::class)->byProgram($director, 2026, null);
        $ids = $report['tree']->flatMap(fn ($p) => $p['subprograms']->flatMap(fn ($s) => $s['rows'])->merge($p['loose']))->map(fn ($r) => $r['activity']->directorate_id)->unique()->values();

        $this->assertNotEmpty($ids);
        $this->assertEmpty(array_diff($ids->all(), $unit->getSelfAndDescendantIds()));
    }

    public function test_the_pdf_and_excel_download_and_are_audited(): void
    {
        $this->actingAs($this->head);

        $this->get(route('reports.plan-by-program.pdf', ['year' => 2026]))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('reports.plan-by-program.xlsx', ['year' => 2026]))->assertOk();

        $this->assertTrue(AuditLog::where('action', 'report.implementation_plan_pdf')->where('description', 'like', '%PAA-PROG-2026-ALL%')->exists());
    }

    public function test_the_reporting_page_links_to_both_plan_prints(): void
    {
        $this->actingAs($this->head)->get(route('reports.paa', ['year' => 2026]))->assertOk()
            ->assertSee(route('reports.plan-by-program', ['year' => 2026]), false)
            ->assertSee(route('reports.implementation-plan', ['year' => 2026]), false);
    }
}
