<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\BudgetDistribution;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Services\AdjustmentRounds;
use App\Services\ApprovalInbox;
use App\Services\BudgetDecisions;
use App\Services\BudgetDistributions;
use App\Services\PlanningWindow;
use App\Support\FiscalYear;
use App\Support\Navigation;
use App\Support\PlanOptions;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The distribution of the budget among the units: off by default; the budget unit prepares the shares of the latest
 * decision, the head of the institution approves them with the act; each unit's plan is compared with its share, and a
 * head approving a plan above it is warned (a remark) or stopped, as the institution chose.
 */
#[Group('office')]
class BudgetDistributionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $head;

    private User $finance;

    private User $minister;

    private Directorate $root;

    private Directorate $budgetUnit;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        app()->setLocale('en');
        $this->head = User::where('email', 'chefe.gdsc@gdsc.tl')->firstOrFail();
        $this->root = Directorate::where('code', 'GDSC')->firstOrFail();
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        // the finance and administration directorate prepares the distribution; the Minister approves it
        $this->budgetUnit = Directorate::create(['code' => 'XDGAF', 'name' => 'Finance and administration', 'type' => 'General Directorate', 'administration' => 'direct', 'active' => true]);
        $this->finance = User::factory()->create(['directorate_id' => $this->budgetUnit->id]);
        $this->finance->assignRole('Finance');
        $this->minister = User::factory()->create();
        $this->minister->assignRole('Minister');
        PlanningWindow::forgetTree();
        FiscalYear::pin(2027);
        $this->rules();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function rules(string $mode = PlanOptions::CEILING_WARN, bool $changeChecks = false): void
    {
        (new PlanOptions(budgetDistribution: true, ceilingMode: $mode, budgetUnitId: $this->budgetUnit->id, budgetChangeChecks: $changeChecks))->save();
    }

    private function service(): BudgetDistributions
    {
        app()->forgetScopedInstances(); // a new request reads afresh

        return app(BudgetDistributions::class);
    }

    /** A category (not salaries) in which the directorate has an approved plan, with that amount. @return array{0: int, 1: float} */
    private function plannedClass(): array
    {
        $salaries = (int) EconomicClassification::where('code', '10')->value('id');
        foreach ($this->service()->plan(2027)[$this->root->id] ?? [] as $class => $p) {
            if ($class !== $salaries && $p['in_force'] > 100) {
                return [$class, $p['in_force']];
            }
        }
        $this->fail('the sample directorate has an approved plan');
    }

    /** Record a ceiling of $amount in a category and approve a distribution giving the directorate $share of it. */
    private function distribute(int $class, float $amount, float $share, string $stage = 'ceiling'): BudgetDistribution
    {
        app(BudgetDecisions::class)->record($this->finance, 2027, $stage, $stage === 'law' ? '2026-12-15' : '2026-07-20', [$class => $amount], 'Circular 1/2026');
        $service = $this->service();
        $d = $service->save($this->finance, 2027, [$this->root->id => [$class => $share]]);
        $service->submit($this->finance, $d);
        $this->service()->approve($this->minister, $d->fresh(), 'Despacho n.º 5/2026', '2026-07-25');

        return $d->fresh();
    }

    public function test_the_rule_is_off_by_default_and_brings_the_budget_decisions_with_it(): void
    {
        (new PlanOptions)->save();
        $this->assertFalse(BudgetDistributions::on());
        $this->assertFalse($this->service()->canPrepare($this->admin));
        Volt::actingAs($this->admin)->test('pages.planning.budget-distribution', ['year' => 2027])->assertStatus(404);

        $this->rules();
        $this->assertTrue(PlanOptions::current()->budgetDecisions, 'sharing a decision needs the decisions');
        $this->assertTrue(BudgetDistributions::on());
    }

    public function test_the_budget_unit_prepares_and_the_head_of_the_institution_approves(): void
    {
        [$class, $planned] = $this->plannedClass();
        $decisions = app(BudgetDecisions::class);
        $service = $this->service();
        $this->assertTrue($decisions->canRecord($this->finance), 'the budget unit records the ceiling');
        $this->assertFalse($service->canPrepare($this->head), 'a directorate does not share the budget');
        $this->assertTrue($service->canPrepare($this->finance));

        $decision = $decisions->record($this->finance, 2027, 'ceiling', '2026-07-20', [$class => $planned + 1000], 'Circular 1/2026', null, 500, 200);
        $this->assertSame('500.00', (string) $decision->committed_amount);

        // a starting point from the plan, then the shares as decided; the rest stays with the institution
        $suggested = $service->suggest(2027, 'plan');
        $this->assertEqualsWithDelta(floor($planned), $suggested[$this->root->id][$class], 0.01);
        $d = $service->save($this->finance, 2027, [$this->root->id => [$class => $planned]], 'By last year');
        $this->assertSame(BudgetDistribution::DRAFT, $d->status);
        $this->assertEqualsWithDelta(1000, $d->matrix()[0][$class], 0.01, 'the reserve is what the decision leaves');

        $service->submit($this->finance, $d);
        $this->assertSame(BudgetDistribution::SUBMITTED, $d->fresh()->status);
        $this->assertGreaterThan(0, $this->minister->notifications()->count(), 'the approver is told');
        $this->assertNotEmpty(collect(app(ApprovalInbox::class)->items($this->minister, 2027))->where('group', 'plan')->all(), 'and finds it waiting for them');
        $this->assertFalse($this->service()->canApprove($this->finance, $d->fresh()), 'the one who submits does not approve');

        try {
            $this->service()->approve($this->minister, $d->fresh(), '', '2026-07-25');
            $this->fail('the act is required');
        } catch (DomainException) {
        }
        $this->service()->approve($this->minister, $d->fresh(), 'Despacho n.º 5/2026', '2026-07-25');
        $d = $d->fresh();
        $this->assertSame(BudgetDistribution::APPROVED, $d->status);
        $this->assertFalse($d->on_behalf);
        $this->assertSame($d->id, $this->service()->inForce(2027)?->id);
        $this->assertGreaterThan(0, $this->head->fresh()->notifications()->where('data->title', 'Budget distributed')->count(), 'the units learn their share');

        // a newer decision: the distribution in force is no longer current; the next one supersedes it
        $decisions->record($this->finance, 2027, 'law', '2026-12-15', [$class => $planned], 'Law 1/2027');
        $this->assertFalse($this->service()->isCurrent(2027));
        $next = $this->service()->save($this->finance, 2027, [$this->root->id => [$class => $planned - 100]]);
        $this->assertSame(2, $next->version);
        $this->service()->submit($this->finance, $next);
        $this->service()->approve($this->admin, $next->fresh(), 'Despacho n.º 9/2026', '2026-12-20');
        $this->assertSame(BudgetDistribution::SUPERSEDED, $d->fresh()->status);
        $this->assertTrue($next->fresh()->on_behalf, 'an administrator approves on behalf of the approver');
        $this->assertTrue($this->service()->isCurrent(2027));
    }

    public function test_shares_above_the_decision_cannot_be_submitted_and_salaries_stay_with_the_institution(): void
    {
        [$class] = $this->plannedClass();
        $salaries = (int) EconomicClassification::where('code', '10')->value('id');
        app(BudgetDecisions::class)->record($this->finance, 2027, 'ceiling', '2026-07-20', [$class => 1000, $salaries => 5000], 'Circular 1/2026');
        $service = $this->service();

        $d = $service->save($this->finance, 2027, [$this->root->id => [$class => 1500, $salaries => 5000]]);
        $this->assertEqualsWithDelta(5000, $d->matrix()[0][$salaries], 0.01, 'salaries are not shared');
        $this->assertArrayNotHasKey($salaries, $d->matrix()[$this->root->id]);
        $this->assertNotEmpty($service->problems($d));
        $this->expectException(DomainException::class);
        $service->submit($this->finance, $d);
    }

    public function test_a_plan_above_its_share_is_approved_only_with_a_remark_or_not_at_all(): void
    {
        [$class, $planned] = $this->plannedClass();
        $this->distribute($class, $planned, $planned - 500, 'law');
        $this->assertEqualsWithDelta(500, $this->service()->overruns(2027)[$this->root->id][0]['over'], 0.01);

        // the directorate's plan waits for its head: one certified activity, the plan not finalized
        UnitPlanning::where('fiscal_year', 2027)->delete();
        PlanningWindow::forgetTree();
        $activity = Activity::where('fiscal_year', 2027)->where('status', Activity::STATUS_APPROVED)
            ->whereIn('directorate_id', app(PlanningWindow::class)->subtree($this->root->id))->firstOrFail();
        $activity->forceFill(['status' => Activity::STATUS_VALIDATED])->saveQuietly();
        $window = app(PlanningWindow::class);
        $this->assertTrue($window->planReadiness(2027, $this->root->id)['ready']);

        try {
            $window->approvePlan($this->head, 2027, $this->root->id);
            $this->fail('a plan above its share needs a remark');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Give a remark', $e->getMessage());
        }

        $this->rules(PlanOptions::CEILING_BLOCK);
        try {
            $window->approvePlan($this->head, 2027, $this->root->id, 'The minister agreed');
            $this->fail('the institution chose to stop it');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Reduce it', $e->getMessage());
        }

        $this->rules(PlanOptions::CEILING_WARN);
        $this->assertTrue($window->approvePlan($this->head, 2027, $this->root->id, 'The minister agreed to the extra')['finalized']);
    }

    public function test_the_adjustment_round_asks_each_unit_for_what_is_above_its_new_share(): void
    {
        [$class, $planned] = $this->plannedClass();
        $this->distribute($class, $planned, $planned - 700);

        $this->assertEqualsWithDelta(700, app(AdjustmentRounds::class)->suggestedTargets(2027)[$this->root->id], 1);
    }

    public function test_who_sees_the_distribution(): void
    {
        [$class, $planned] = $this->plannedClass();
        $d = $this->distribute($class, $planned, $planned);

        $this->actingAs($this->minister)->get(route('planning.budget-distribution.print', $d))->assertOk()->assertSee('Despacho n.º 5/2026');
        $this->actingAs($this->finance)->get(route('planning.budget-decisions', ['tab' => 'distribution']))->assertOk()->assertSee('Distribution among the units');
        $this->assertContains(route('planning.budget-decisions'), collect(Navigation::quickLinks($this->finance))->pluck('url')->all(), 'the budget unit finds the page');

        $planner = User::factory()->create(['directorate_id' => $this->root->id]);
        $planner->assignRole('Planning');
        $this->actingAs($planner)->get(route('planning.budget-distribution.print', $d))->assertForbidden();
        $this->actingAs($planner)->get(route('planning.budget-decisions'))->assertForbidden();

        // the directorate sees its own share on its page
        $this->actingAs($this->head)->get(route('units.show', $this->root->id))->assertOk()->assertSee('Share of the budget 2027');
    }

    public function test_the_distribution_tab_prepares_submits_and_approves(): void
    {
        [$class, $planned] = $this->plannedClass();
        app(BudgetDecisions::class)->record($this->finance, 2027, 'ceiling', '2026-07-20', [$class => $planned], 'Circular 1/2026');

        Volt::actingAs($this->finance)->test('pages.planning.budget-distribution', ['year' => 2027])
            ->call('start')
            ->assertSet('editing', true)
            ->set("amounts.{$this->root->id}.{$class}", (string) $planned)
            ->call('submit')
            ->assertHasNoErrors();
        $d = BudgetDistribution::firstOrFail();
        $this->assertSame(BudgetDistribution::SUBMITTED, $d->status);

        Volt::actingAs($this->minister)->test('pages.planning.budget-distribution', ['year' => 2027])
            ->set('actReference', 'Despacho n.º 7/2026')
            ->call('approve', $d->id)
            ->assertHasNoErrors();
        $this->assertSame(BudgetDistribution::APPROVED, $d->fresh()->status);
    }

    public function test_shares_are_typed_as_percentages_of_each_category(): void
    {
        [$class] = $this->plannedClass();
        app(BudgetDecisions::class)->record($this->finance, 2027, 'ceiling', '2026-07-20', [$class => 10000], 'Circular 1/2026');

        $page = Volt::actingAs($this->finance)->test('pages.planning.budget-distribution', ['year' => 2027])
            ->call('start')
            ->set("amounts.{$this->root->id}.{$class}", '3333.33')
            ->set('entry', 'percent')
            ->assertSet("percents.{$this->root->id}.{$class}", '33.33');
        // a percentage left alone keeps its exact amount; a changed one gives the amount of the new percentage
        $page->set('entry', 'amount')->assertSet("amounts.{$this->root->id}.{$class}", '3333.33');
        $page->set('entry', 'percent')->set("percents.{$this->root->id}.{$class}", '40')->call('save')->assertHasNoErrors();
        $d = BudgetDistribution::firstOrFail();
        $this->assertEqualsWithDelta(4000, $d->matrix()[$this->root->id][$class], 0.01);
        $this->assertEqualsWithDelta(6000, $d->matrix()[0][$class], 0.01, 'the rest is the reserve');

        // one percentage for the whole row
        $page->set("rowPercents.{$this->root->id}", '25')->assertSet("percents.{$this->root->id}.{$class}", '25');
        $page->set('entry', 'amount')->assertSet("amounts.{$this->root->id}.{$class}", '2500');
        $page->set('entry', 'percent')->set("percents.{$this->root->id}.{$class}", '120')->call('save')->assertHasErrors("percents.{$this->root->id}.{$class}");
    }

    public function test_percentages_never_round_above_the_decision(): void
    {
        [$class] = $this->plannedClass();
        $decision = app(BudgetDecisions::class)->record($this->finance, 2027, 'ceiling', '2026-07-20', [$class => 100], 'Circular 1/2026');
        $other = Directorate::create(['code' => 'XOTHER', 'name' => 'Other', 'type' => 'General Directorate', 'administration' => 'direct', 'active' => true]);
        $amounts = $this->service()->fromPercents($decision, [$this->root->id => [$class => 33.335], $other->id => [$class => 66.665]]);

        $this->assertEqualsWithDelta(100, $amounts[$this->root->id][$class] + $amounts[$other->id][$class], 0.001);
    }

    public function test_a_new_decision_is_shared_with_the_same_percentages(): void
    {
        [$class, $planned] = $this->plannedClass();
        $this->distribute($class, 10000, 2500); // 25 %
        app(BudgetDecisions::class)->record($this->finance, 2027, 'law', '2026-12-15', [$class => 16000], 'Law 1/2027');

        $this->assertEqualsWithDelta(4000, $this->service()->suggest(2027, 'same_percent')[$this->root->id][$class], 0.01);
        Volt::actingAs($this->finance)->test('pages.planning.budget-distribution', ['year' => 2027])
            ->call('start')
            ->assertSet('method', 'same_percent')
            ->assertSet("amounts.{$this->root->id}.{$class}", '4000');
    }

    public function test_only_the_final_envelope_stops_an_approval(): void
    {
        [$class, $planned] = $this->plannedClass();
        $this->distribute($class, $planned, $planned - 500); // a provisional ceiling
        $this->rules(PlanOptions::CEILING_BLOCK);
        UnitPlanning::where('fiscal_year', 2027)->delete();
        PlanningWindow::forgetTree();
        $activity = Activity::where('fiscal_year', 2027)->where('status', Activity::STATUS_APPROVED)
            ->whereIn('directorate_id', app(PlanningWindow::class)->subtree($this->root->id))->firstOrFail();
        $activity->forceFill(['status' => Activity::STATUS_VALIDATED])->saveQuietly();

        // above a provisional share the head is only warned: the amount still changes until the law
        $this->assertTrue(app(PlanningWindow::class)->approvePlan($this->head, 2027, $this->root->id, 'The law will settle it')['finalized']);
    }
}
