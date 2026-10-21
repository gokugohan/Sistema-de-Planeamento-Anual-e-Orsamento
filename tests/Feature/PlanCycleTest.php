<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\ReportingWindow;
use App\Models\User;
use App\Services\PlanCycle;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** The cycle of the annual action plan: windows, approval, end of planning, budget and reporting. */
#[Group('office')]
class PlanCycleTest extends TestCase
{
    use RefreshDatabase;

    private User $chief;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        app()->setLocale('en');
        session(['fiscal_year' => 2026]);
        $this->chief = User::where('email', 'chefe.gdsc@gdsc.tl')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function step(array $cycle, string $key): array
    {
        return collect($cycle['steps'])->firstWhere('key', $key);
    }

    public function test_the_steps_follow_the_plan_and_one_is_current(): void
    {
        $cycle = app(PlanCycle::class)->build($this->chief, 2026);

        $this->assertSame('done', $this->step($cycle, 'approval')['state'], 'every sample activity of 2026 is approved');
        $this->assertSame(1, collect($cycle['steps'])->where('state', 'current')->count());
        $this->assertSame($cycle['approval']['approved'], $cycle['approval']['total']);
        $this->assertGreaterThan(0, $cycle['approval']['total']);
    }

    public function test_the_plan_window_states(): void
    {
        $svc = app(PlanCycle::class);
        $this->assertSame('none', $svc->build($this->chief, 2026)['plan_window']['state']);

        ReportingWindow::create(['fiscal_year' => 2026, 'period_type' => 'plan', 'period' => 1, 'opens_on' => '2026-11-01', 'closes_on' => '2026-11-30']);
        $this->assertSame('upcoming', $svc->build($this->chief, 2026)['plan_window']['state']);

        ReportingWindow::where('period_type', 'plan')->update(['opens_on' => '2026-10-01', 'closes_on' => '2026-10-31']);
        $this->assertSame('open', $svc->build($this->chief, 2026)['plan_window']['state']);

        ReportingWindow::where('period_type', 'plan')->update(['opens_on' => '2026-09-01', 'closes_on' => '2026-09-30']);
        $cycle = $svc->build($this->chief, 2026);
        $this->assertSame('closed', $cycle['plan_window']['state']);
        $this->assertSame('done', $this->step($cycle, 'submission')['state'], 'a closed window ends the submission step');
    }

    public function test_reporting_counts_follow_the_months_and_windows(): void
    {
        ReportingWindow::create(['fiscal_year' => 2026, 'period_type' => 'month', 'period' => 9, 'opens_on' => '2026-10-01', 'closes_on' => '2026-10-20']);

        $r = app(PlanCycle::class)->build($this->chief, 2026)['reporting'];

        $this->assertSame('open', $r['months'][9]['window']['state']);
        $this->assertSame('none', $r['months'][3]['window']['state']);
        $this->assertGreaterThan(0, $r['activities_due']);
        $this->assertLessThanOrEqual($r['activities_due'], $r['months'][3]['verified']);
        $this->assertSame(4, count($r['quarters']));
    }

    public function test_a_directors_numbers_cover_only_their_units(): void
    {
        $director = User::where('email', 'director.udig@gdsc.tl')->firstOrFail();
        $ids = Directorate::find($director->directorate_id)->getSelfAndDescendantIds();
        $expected = Activity::where('fiscal_year', 2026)->whereIn('directorate_id', $ids)->count();

        $this->assertSame($expected, app(PlanCycle::class)->build($director, 2026)['approval']['total']);
        $this->assertGreaterThan($expected, app(PlanCycle::class)->build($this->chief, 2026)['approval']['total']);
    }

    public function test_the_page_opens_and_names_no_committee(): void
    {
        $html = $this->actingAs($this->chief)->get(route('planning.cycle'))->assertOk()->assertSee('Plan cycle')->assertSee('Plan submission')->assertSee('Reporting')->getContent();

        $this->assertStringNotContainsString('CRO', $html);
        $this->get('/planning/budget-cycle')->assertRedirect(route('planning.cycle'));
    }

    public function test_the_head_of_a_directorate_is_pointed_to_where_the_plan_is_finalized(): void
    {
        $this->actingAs($this->chief)->get(route('planning.cycle'))->assertOk()->assertSee('Finalize the plan')->assertSee(route('activities.index'));

        $officer = User::where('email', 'tecnico.udig@gdsc.tl')->firstOrFail();
        $this->actingAs($officer)->get(route('planning.cycle'))->assertOk()->assertDontSee('Finalize the plan');
    }
}
