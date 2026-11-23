<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\BudgetDecision;
use App\Models\BudgetExecution;
use App\Models\EconomicClassification;
use App\Models\LineItem;
use App\Models\PlanBaseline;
use App\Models\User;
use App\Services\ActivityVersions;
use App\Services\ActivityWorkflow;
use App\Services\ApprovedPlanReport;
use App\Services\BudgetDecisions;
use App\Services\PlanBreakdown;
use App\Services\PlanningWindow;
use App\Services\ReportingWindows;
use App\Support\FiscalYear;
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
        FiscalYear::pin(2027);
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

        $decision = app(BudgetDecisions::class)->record($this->admin, 2027, 'ceiling', '2026-07-18', $amounts, 'Circular 1/2026');

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
        $decisions->record($this->admin, 2027, 'ceiling', '2026-07-18', $this->planWithCut(1000));
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

    public function test_a_decision_follows_the_stages_of_the_year(): void
    {
        (new PlanOptions(budgetDecisions: true))->save();
        $decisions = app(BudgetDecisions::class);
        $class = EconomicClassification::orderBy('code')->value('id');

        $this->assertSame(['ceiling', 'law'], $decisions->nextStages(2027), 'the ceiling, or straight the law');
        Volt::actingAs($this->admin)->test('pages.planning.budget-decisions')->call('openForm')->assertSet('stage', 'ceiling');

        $decisions->record($this->admin, 2027, 'ceiling', '2026-07-18', [$class => 1000], 'Circular 1/2026', null, null, null, '2026-08-15');
        $this->assertSame('2026-08-15', $decisions->latest(2027)->plans_due_on->toDateString(), 'the date for the corrected plans');
        $this->assertSame(['law'], $decisions->nextStages(2027), 'a stage passed is not offered again');
        $decisions->record($this->admin, 2027, 'law', '2026-12-15', [$class => 900], 'Law 1/2027');
        $this->assertSame(['supplementary'], $decisions->nextStages(2027), 'after the law: only a supplementary budget');
        Volt::actingAs($this->admin)->test('pages.planning.budget-decisions')->call('openForm')->assertSet('stage', 'supplementary');

        foreach ([
            fn () => $decisions->record($this->admin, 2027, 'ceiling', '2027-01-10', [$class => 900]),
            fn () => $decisions->record($this->admin, 2027, 'supplementary', '2026-12-01', [$class => 900]),
            fn () => $decisions->record($this->admin, 2027, 'proposal', '2027-01-10', [$class => 900]),
        ] as $wrong) {
            try {
                $wrong();
                $this->fail('a stage passed, a date before the latest decision, or a stage no longer recorded is refused');
            } catch (DomainException) {
            }
        }

        $decisions->record($this->admin, 2027, 'supplementary', '2027-06-08', [$class => 800], 'Law 8/2027');
        $second = $decisions->record($this->admin, 2027, 'supplementary', '2027-09-01', [$class => 850], 'Law 12/2027');
        $this->assertSame('Supplementary budget 2', $second->stageLabel());
        $this->assertEqualsWithDelta(50, $decisions->change(2027)['difference'], 0.01);
    }

    public function test_after_a_supplementary_budget_the_page_says_what_to_do(): void
    {
        (new PlanOptions(budgetDecisions: true))->save();
        $decisions = app(BudgetDecisions::class);
        $decisions->record($this->admin, 2027, 'law', '2026-12-15', $this->planWithCut(0), 'Law 1/2027');
        $this->assertSame('none', $decisions->nextSteps(2027)['steps']['adjust']['status'], 'the plan fits the law');

        $cut = $this->planWithCut(2000);
        $decisions->record($this->admin, 2027, 'supplementary', '2027-06-08', $cut, 'Law 8/2027');
        $steps = $decisions->nextSteps(2027);
        $this->assertEqualsWithDelta(-2000, $steps['change']['difference'], 0.01);
        $this->assertSame('todo', $steps['steps']['adjust']['status']);
        $this->assertSame('done', $steps['steps']['spent']['status']);

        // what was already spent in a category is more than the retificativo leaves in it
        $first = array_key_first($cut);
        $line = LineItem::where('economic_classification_id', $first)
            ->whereHas('activity', fn ($q) => $q->where('fiscal_year', 2027)->where('status', Activity::STATUS_APPROVED))->firstOrFail();
        BudgetExecution::create(['line_item_id' => $line->id, 'month' => 1, 'executed' => $cut[$first] + 10, 'status' => 'Verified']);
        $this->assertSame('problem', $decisions->nextSteps(2027)['steps']['spent']['status']);

        $this->actingAs($this->admin)->get(route('planning.budget-decisions'))->assertOk()
            ->assertSee('After the supplementary budget')->assertSee('Bring the plan to the new amount')->assertSee('already spent');
    }

    public function test_the_form_shows_the_plan_and_fills_the_amounts_from_it(): void
    {
        (new PlanOptions(budgetDecisions: true))->save();
        $plan = array_filter(array_map(fn ($c) => round($c['in_force'] + $c['preparing'], 2), app(BudgetDecisions::class)->planByClass(2027)), fn ($v) => $v > 0);
        $this->assertNotEmpty($plan);

        $page = Volt::actingAs($this->admin)->test('pages.planning.budget-decisions')
            ->call('openForm')
            ->assertSee('Whole plan')->assertSee('$ '.number_format(array_sum($plan), 0))
            ->call('copyPlan');
        $this->assertEqualsWithDelta(array_sum($plan), array_sum(array_map('floatval', $page->get('amounts'))), 0.01);

        // a total shared by the plan's percentages adds up to the total
        $page->set('spread', '1000000')->call('spreadTotal')->assertHasNoErrors();
        $amounts = array_map('floatval', $page->get('amounts'));
        $this->assertEqualsWithDelta(1000000, array_sum($amounts), 0.001);
        $first = array_key_first($plan);
        $this->assertEqualsWithDelta(1000000 * $plan[$first] / array_sum($plan), $amounts[$first], 1.01);
        $page->set('spread', '')->call('spreadTotal')->assertHasErrors('spread');
    }

    public function test_the_calendar_shows_the_stages_and_the_final_envelope(): void
    {
        (new PlanOptions(budgetDecisions: true))->save();
        $decisions = app(BudgetDecisions::class);
        $class = EconomicClassification::orderBy('code')->value('id');

        // the legal dates, filled in on the calendar page: the plans in May and June, the circular by 20 July, the law
        // from the proposal (1 October) to 1 January
        Volt::actingAs($this->admin)->test('pages.management.reporting-windows')->call('fillBudget')->assertHasNoErrors();
        $this->assertSame('2026-07-20', $decisions->window(2027, 'ceiling')->closes_on->toDateString());
        $this->assertSame('2026-10-01', $decisions->window(2027, 'law')->opens_on->toDateString());
        $this->assertSame('2026-12-31', $decisions->window(2027, 'law')->closes_on->toDateString());
        $plan = app(ReportingWindows::class)->find(2027, 'plan', 1);
        $this->assertSame(['2026-05-01', '2026-06-30'], [$plan->opens_on->toDateString(), $plan->closes_on->toDateString()]);

        $decisions->record($this->admin, 2027, 'ceiling', '2026-07-18', [$class => 1000], 'Circular 1/2026');
        $calendar = collect($decisions->calendar(2027)['rows'])->keyBy('key');
        $this->assertSame('recorded', $calendar['ceiling']['status']);
        $this->assertSame('open', $calendar['law']['status'], 'on 14 October 2026 the proposal is before Parliament: the law is expected now');
        $this->assertNull($decisions->finalEnvelope(2027), 'a ceiling is provisional');
        $this->get(route('planning.budget-decisions'))->assertOk()->assertSee('No final envelope yet.')->assertSee('Calendar of the 2027 budget');

        Carbon::setTestNow('2027-01-10 10:00:00');
        $this->assertSame('late', collect($decisions->calendar(2027)['rows'])->keyBy('key')['law']['status']);
        $this->assertTrue($decisions->calendar(2027)['extended'], 'no law on 1 January: the law of the year before is extended');
        $this->get(route('planning.budget-decisions'))->assertOk()->assertSee('one twelfth a month');

        $law = $decisions->record($this->admin, 2027, 'law', '2026-12-20', [$class => 900], 'Law 1/2027');
        $this->assertTrue($law->isFinal());
        $this->assertSame($law->id, $decisions->finalEnvelope(2027)?->id);
        $this->assertFalse($decisions->calendar(2027)['extended']);
        $this->get(route('planning.budget-decisions'))->assertOk()->assertSee('Final envelope');
    }
}
