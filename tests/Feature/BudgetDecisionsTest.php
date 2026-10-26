<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\BudgetDecision;
use App\Models\EconomicClassification;
use App\Models\PlanBaseline;
use App\Models\User;
use App\Services\ActivityVersions;
use App\Services\ActivityWorkflow;
use App\Services\ApprovedPlanReport;
use App\Services\BudgetDecisions;
use App\Services\PlanBreakdown;
use App\Services\PlanningWindow;
use App\Support\Navigation;
use App\Support\PlanOptions;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Budget decisions: off by default; recorded by category with the plan as it stood; the plan compared with the latest one. */
#[Group('office')]
class BudgetDecisionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $head;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        app()->setLocale('en');
        $this->head = User::where('email', 'chefe.gdsc@gdsc.tl')->firstOrFail();
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        PlanningWindow::forgetTree();
        session(['fiscal_year' => 2027]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** The plan in force by category, with a cut of $cut taken from the first category that has money. @return array<int, float> */
    private function planWithCut(float $cut): array
    {
        $plan = array_map(fn ($c) => $c['in_force'], app(PlanBreakdown::class)->classTotals(2027));
        $plan = array_filter($plan, fn ($v) => $v > 0);
        $first = array_key_first($plan);
        $plan[$first] -= $cut;

        return $plan;
    }

    public function test_the_rule_is_off_by_default(): void
    {
        $this->actingAs($this->admin)->get(route('planning.budget-decisions'))->assertNotFound();
        $this->assertNotContains(route('planning.budget-decisions'), collect(Navigation::quickLinks($this->admin))->pluck('url')->all());
        (new PlanOptions(budgetDecisions: true))->save();
        $this->assertContains(route('planning.budget-decisions'), collect(Navigation::quickLinks($this->admin))->pluck('url')->all());
        (new PlanOptions)->save();

        try {
            app(BudgetDecisions::class)->record($this->admin, 2027, 'law', '2026-12-15', [1 => 100]);
            $this->fail('the rule is off');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_a_decision_keeps_the_plan_as_it_stood_and_shows_the_gap_by_category(): void
    {
        (new PlanOptions(budgetDecisions: true))->save();
        $before = app(PlanBreakdown::class)->classTotals(2027);
        $amounts = $this->planWithCut(5000);

        $decision = app(BudgetDecisions::class)->record($this->admin, 2027, 'committee', '2026-10-10', $amounts, 'Minutes 12/2026');

        $this->assertEqualsWithDelta(array_sum($amounts), (float) $decision->total, 0.01);
        $this->assertEqualsWithDelta(array_sum(array_column($before, 'in_force')), (float) $decision->plan_total, 0.01);
        $this->assertNotNull($decision->plan_baseline_id, 'the plan is kept as a baseline');

        $gap = app(BudgetDecisions::class)->gap(2027);
        $this->assertEqualsWithDelta(5000, $gap['difference'], 0.01);
        $this->assertSame(1, collect($gap['rows'])->filter(fn ($r) => abs($r['difference']) > 0.01)->count(), 'only the category that was cut differs');

        // a decision approves nothing and changes no activity
        $this->assertEquals($before, app(PlanBreakdown::class)->classTotals(2027));
    }

    public function test_the_latest_decision_counts_and_a_revision_closes_the_gap(): void
    {
        (new PlanOptions(budgetDecisions: true))->save();
        $decisions = app(BudgetDecisions::class);
        $decisions->record($this->admin, 2027, 'committee', '2026-10-10', $this->planWithCut(1000));
        $decisions->record($this->admin, 2027, 'law', '2026-12-15', $this->planWithCut(3000), 'Law 1/2027');
        $this->assertSame('law', $decisions->latest(2027)->stage);
        $this->assertEqualsWithDelta(3000, $decisions->gap(2027)['difference'], 0.01);

        // a unit revises an activity and cuts 3000 from a line: once approved, the plan follows the decision
        $activity = Activity::where('fiscal_year', 2027)->where('status', Activity::STATUS_APPROVED)->where('version', '>', 0)
            ->whereHas('lineItems', fn ($q) => $q->where('estimated_amount', '>=', 3000))->firstOrFail();
        app(ActivityWorkflow::class)->reopen($this->head, $activity, 'Following the State Budget law');
        $line = $activity->lineItems()->where('estimated_amount', '>=', 3000)->firstOrFail();
        $line->update(['estimated_amount' => $line->estimated_amount - 3000]);

        $this->assertEqualsWithDelta(3000, $decisions->gap(2027)['difference'], 0.01, 'the version in force counts until the revision is approved');

        app(ActivityVersions::class)->approved($activity->fresh(), $this->admin);
        $activity->fresh()->forceFill(['status' => Activity::STATUS_APPROVED])->save();

        $this->assertEqualsWithDelta(0, $decisions->gap(2027)['difference'], 0.01);
    }

    public function test_only_an_administrator_records_and_a_decision_needs_an_amount(): void
    {
        (new PlanOptions(budgetDecisions: true))->save();
        $class = EconomicClassification::orderBy('code')->firstOrFail();

        $this->expectExceptionObject(new DomainException(__('Give the amount decided for at least one category.')));
        try {
            app(BudgetDecisions::class)->record($this->head, 2027, 'law', '2026-12-15', [$class->id => 100]);
            $this->fail('only an administrator records a decision');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        app(BudgetDecisions::class)->record($this->admin, 2027, 'law', '2026-12-15', [$class->id => '']);
    }

    public function test_the_page_records_a_decision_and_the_dashboard_shows_the_difference(): void
    {
        (new PlanOptions(budgetDecisions: true))->save();
        $amounts = array_map(fn ($v) => (string) $v, $this->planWithCut(2500));

        $this->actingAs($this->head);
        $this->get(route('planning.budget-decisions'))->assertForbidden();

        $this->actingAs($this->admin);
        $this->get(route('planning.budget-decisions'))->assertOk()->assertSee('No budget decision for 2027 yet');
        Volt::test('pages.planning.budget-decisions')
            ->call('openForm')
            ->set('stage', 'law')->set('decidedOn', '2026-12-15')->set('reference', 'Law 1/2027')->set('amounts', $amounts)
            ->call('record')->assertHasNoErrors();

        $decision = BudgetDecision::firstOrFail();
        $this->assertSame('Law 1/2027', $decision->reference);
        $this->get(route('planning.budget-decisions'))->assertOk()->assertSee('Law 1/2027')->assertSee('The plan is above the decision');
        $this->get(route('dashboard'))->assertOk()->assertSee('above the latest decision');

        // the plan as it stood opens from the decision, even with baselines switched off
        $this->get(route('reports.approved-plan', ['year' => 2027, 'baseline' => $decision->plan_baseline_id]))->assertOk()->assertSee('Baseline:');
        $this->assertSame(1, PlanBaseline::count());
        $this->assertEquals((new ApprovedPlanReport)->build(2027)['totals']['total'], (float) $decision->plan_total);
    }
}
