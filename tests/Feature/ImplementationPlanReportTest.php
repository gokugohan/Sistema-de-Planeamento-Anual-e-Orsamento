<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ApproverAssignment;
use App\Models\AuditLog;
use App\Models\Directorate;
use App\Models\IndicatorAction;
use App\Models\User;
use App\Services\ImplementationPlanReport;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** The implementation plan of a unit in the ministry's form: actions, months, budget per indicator, the signatures. */
#[Group('office')]
class ImplementationPlanReportTest extends TestCase
{
    use RefreshDatabase;

    private User $chief;

    private Activity $activity;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        app()->setLocale('en');
        session(['fiscal_year' => 2026]);
        $this->chief = User::where('email', 'chefe.gdsc@gdsc.tl')->firstOrFail();

        $this->activity = Activity::where('fiscal_year', 2026)->where('status', 'Approved')->whereHas('indicators')->whereHas('lineItems')->firstOrFail();
        $ind = $this->activity->indicators()->first();
        IndicatorAction::query()->delete(); // the sample brings its own
        IndicatorAction::create(['indicator_id' => $ind->id, 'position' => 1, 'text' => 'Study the ports', 'months' => [3, 6]]);
        IndicatorAction::create(['indicator_id' => $ind->id, 'position' => 2, 'text' => 'Hire the team', 'months' => null]);
        $this->activity->lineItems()->update(['indicator_id' => null]);
        $this->activity->lineItems()->first()->forceFill(['indicator_id' => $ind->id, 'estimated_amount' => 1234])->saveQuietly();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function findRow(array $report): ?array
    {
        return collect($report['tree'])->flatMap(fn ($p) => $p['subprograms']->flatMap(fn ($s) => $s['rows'])->merge($p['loose']))->first(fn ($r) => $r['activity']->id === $this->activity->id);
    }

    public function test_the_budget_is_per_indicator_and_the_rest_belongs_to_the_activity(): void
    {
        $report = app(ImplementationPlanReport::class)->build($this->chief, 2026, null);
        $row = $this->findRow($report);

        $this->assertNotNull($row);
        $this->assertEquals(1234, $row['indicators'][0]['budget']);
        $this->assertEquals($row['total'], collect($row['indicators'])->sum('budget') + $row['whole'], 'the indicators and the rest add up to the activity');
        $this->assertSame(2, $row['indicators'][0]['indicator']->actions->count());
        $this->assertEqualsWithDelta($report['total'], collect($report['tree'])->sum('total'), 0.01);
    }

    public function test_the_page_shows_actions_months_budget_and_signatures_with_the_names_on_the_lists(): void
    {
        $unit = $this->activity->directorate;
        $preparer = User::factory()->create(['name' => 'Anastasia Prepares']);
        $approver = User::factory()->create(['name' => 'Maria Approves']);
        ApproverAssignment::create(['directorate_id' => $unit->id, 'user_id' => $preparer->id, 'step' => 'prepare', 'position' => 1]);
        ApproverAssignment::create(['directorate_id' => $unit->id, 'user_id' => $approver->id, 'step' => 'approve', 'position' => 1]);

        $html = $this->actingAs($this->chief)->get(route('reports.implementation-plan', ['year' => 2026, 'unit' => $unit->id]))
            ->assertOk()->assertSee('Implementation plan of activities by quarter')->assertSee('Implementation of the activities')->assertSee('Budget allocated')
            ->assertSee('1. Study the ports')->assertSee('2. Hire the team')->assertSee('1,234.00')->assertSee('Anastasia Prepares')->assertSee('Maria Approves')
            ->assertSee('Prepared by')->assertSee('Approved by')->getContent();

        $this->assertGreaterThanOrEqual(2, substr_count($html, 'class="m"'), 'the months of the first action are shaded');
        $this->assertStringContainsString('class="ml"', $html, 'an action without months follows the quarters of its indicator, lightly');
    }

    public function test_a_unit_only_lists_what_it_may_see_and_activities_not_approved_are_opt_in(): void
    {
        $director = User::where('email', 'director.udig@gdsc.tl')->firstOrFail();
        $mine = Directorate::find($director->directorate_id)->getSelfAndDescendantIds();
        $units = app(ImplementationPlanReport::class)->units($director, 2026);
        $this->assertTrue($units->every(fn ($u) => in_array($u->id, $mine, true)));

        $draft = Activity::where('fiscal_year', 2026)->whereIn('directorate_id', $mine)->firstOrFail();
        $draft->forceFill(['status' => Activity::STATUS_SUBMITTED])->saveQuietly();
        $without = app(ImplementationPlanReport::class)->build($director, 2026, $director->directorate_id, true);
        $with = app(ImplementationPlanReport::class)->build($director, 2026, $director->directorate_id, false);

        $this->assertSame($without['activities'] + 1, $with['activities']);
    }

    public function test_the_pdf_downloads_is_audited_and_access_follows_the_reports_permission(): void
    {
        $response = $this->actingAs($this->chief)->get(route('reports.implementation-plan.pdf', ['year' => 2026]))->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertSame(1, AuditLog::where('action', 'report.implementation_plan_pdf')->count());

        $nobody = User::factory()->create();
        $this->actingAs($nobody)->get(route('reports.implementation-plan'))->assertForbidden();
    }
}
