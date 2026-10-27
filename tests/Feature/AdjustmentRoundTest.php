<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\AdjustmentRound;
use App\Models\BudgetExecution;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\Program;
use App\Models\ReportingWindow;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Services\ActivityVersions;
use App\Services\AdjustmentRounds;
use App\Services\BudgetDecisions;
use App\Services\PlanBreakdown;
use App\Services\PlanningWindow;
use App\Services\ReportingWindows;
use App\Support\PlanOptions;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** The adjustment round: open after a decision, units revise what they choose, heads confirm, the administrator closes. */
#[Group('office')]
class AdjustmentRoundTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $head;

    private Directorate $root;

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
        PlanningWindow::forgetTree();
        session(['fiscal_year' => 2027]);
        (new PlanOptions(budgetDecisions: true))->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A decision $cut below the plan in force, in the first category that has money. */
    private function decideACut(float $cut): void
    {
        $plan = array_filter(array_map(fn ($c) => $c['in_force'], app(PlanBreakdown::class)->classTotals(2027)), fn ($v) => $v > 0);
        $plan[array_key_first($plan)] -= $cut;
        app(BudgetDecisions::class)->record($this->admin, 2027, 'law', '2026-12-15', $plan, 'Law 1/2027');
    }

    private function openRound(float $target): AdjustmentRound
    {
        return app(AdjustmentRounds::class)->open($this->admin, 2027, '2027-01-15', 'Protect salaries', [$this->root->id => $target]);
    }

    /** An approved activity of the directorate with a line of at least $amount. */
    private function activityWithLine(float $amount): Activity
    {
        return Activity::where('fiscal_year', 2027)->where('status', Activity::STATUS_APPROVED)->where('version', '>', 0)
            ->whereIn('directorate_id', app(PlanningWindow::class)->subtree($this->root->id))
            ->whereHas('lineItems', fn ($q) => $q->where('estimated_amount', '>=', $amount))->firstOrFail();
    }

    /** What the approval chain does in the end: the revision becomes the version in force. */
    private function approveRevision(Activity $activity): void
    {
        app(ActivityVersions::class)->approved($activity->fresh(), $this->admin);
        $activity->fresh()->forceFill(['status' => Activity::STATUS_APPROVED])->save();
    }

    public function test_a_round_needs_a_decision_suggests_targets_and_tells_the_directorates(): void
    {
        $rounds = app(AdjustmentRounds::class);
        try {
            $this->openRound(0);
            $this->fail('a round adjusts the plan to a decision');
        } catch (DomainException) {
        }

        $this->decideACut(4000);
        $this->assertEqualsWithDelta(4000, array_sum($rounds->suggestedTargets(2027)), 1, 'the difference is shared among the directorates');

        $round = $this->openRound(4000);
        $this->assertTrue($round->isOpen());
        $this->assertSame(['law'], [$round->decision->stage]);
        $this->assertGreaterThan(0, $this->head->fresh()->notifications()->count(), 'the head of the directorate is told');

        $this->expectException(DomainException::class);
        $this->openRound(0); // one open round per year
    }

    public function test_a_directorate_revises_what_it_chooses_and_confirms_when_the_target_is_met(): void
    {
        $this->decideACut(3000);
        $round = $this->openRound(3000);
        $rounds = app(AdjustmentRounds::class);
        $activity = $this->activityWithLine(3000);

        $rounds->revise($this->head, $round, $activity, 'Postpone the second workshop');
        $activity = $activity->fresh();
        $this->assertSame(Activity::STATUS_DRAFT, $activity->status);
        $this->assertStringContainsString('Adjustment to the State Budget law (Law 1/2027)', $activity->revision_reason);
        $this->assertStringContainsString('Postpone the second workshop', $activity->revision_reason);

        // the unit cuts 3000 from a line: proposed, not yet reduced
        $line = $activity->lineItems()->where('estimated_amount', '>=', 3000)->firstOrFail();
        $line->update(['estimated_amount' => $line->estimated_amount - 3000]);
        $row = $rounds->progress($round)[$this->root->id];
        $this->assertSame('revising', $row['state']);
        $this->assertEqualsWithDelta(3000, $row['proposed'], 0.01);
        $this->assertEqualsWithDelta(0, $row['reduced'], 0.01);

        $unit = $round->units()->firstOrFail();
        try {
            $rounds->confirm($this->head, $unit, 'Done');
            $this->fail('a revision of the round is still in the chain');
        } catch (DomainException) {
        }

        $this->approveRevision($activity);
        $row = $rounds->progress($round)[$this->root->id];
        $this->assertEqualsWithDelta(3000, $row['reduced'], 0.01);
        $this->assertEqualsWithDelta(0, $row['remaining'], 0.01);
        $this->assertSame('ready', $row['state']);
        $this->assertEqualsWithDelta(0, app(BudgetDecisions::class)->gap(2027)['difference'], 0.01, 'the plan now follows the decision');

        $rounds->confirm($this->head, $unit->fresh());
        $this->assertSame('confirmed', $rounds->progress($round)[$this->root->id]['state']);
        $this->assertFalse($rounds->canRevise($this->head, $round, $this->activityWithLine(1)), 'a confirmed directorate is done with the round');
    }

    public function test_confirming_below_the_target_needs_a_reason_and_only_the_head_confirms(): void
    {
        $this->decideACut(5000);
        $round = $this->openRound(5000);
        $rounds = app(AdjustmentRounds::class);
        $unit = $round->units()->firstOrFail();

        $director = User::where('email', 'director.udig@gdsc.tl')->firstOrFail(); // directs a unit below, not the directorate
        $this->assertFalse($rounds->canConfirm($director, $unit));

        try {
            $rounds->confirm($this->head, $unit, '');
            $this->fail('the target is not reached');
        } catch (DomainException $e) {
            $this->assertSame('The target is not reached: say why in the remark.', $e->getMessage());
        }
        $rounds->confirm($this->head, $unit, 'Every activity is committed by contract');
        $this->assertSame('Every activity is committed by contract', $unit->fresh()->remark);
    }

    public function test_closing_ends_the_round_and_the_page_shows_each_reader_their_part(): void
    {
        $this->decideACut(2000);
        $round = $this->openRound(2000);

        $this->actingAs($this->head);
        $this->get(route('planning.adjustment'))->assertOk()->assertSee('Adjustment to the State Budget law')->assertSee('Revise for this round');

        $activity = $this->activityWithLine(1);
        Volt::test('pages.planning.adjustment-round')->call('revise', $activity->id)->assertRedirect(route('activities.edit', $activity->id));
        $this->assertSame(1, $round->activities()->count());

        // someone from a directorate outside the round sees that it is not theirs
        $other = Directorate::create(['code' => 'XOTH', 'name' => 'Other directorate', 'type' => 'National Directorate', 'active' => true]);
        $outsider = User::factory()->create(['directorate_id' => $other->id]);
        $outsider->assignRole('Director');
        $this->actingAs($outsider)->get(route('planning.adjustment'))->assertOk()->assertSee('Your directorate is not in this round');

        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertSee('0 of 1 directorates have confirmed');
        app(AdjustmentRounds::class)->close($this->admin, $round, 'Law published');
        $this->assertFalse($round->fresh()->isOpen());
        $this->assertFalse(app(AdjustmentRounds::class)->canRevise($this->head, $round->fresh(), $this->activityWithLine(1)));
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertDontSee('adjustment round open until');
    }

    public function test_a_round_with_new_money_lets_a_finalized_directorate_add_activities_until_it_confirms(): void
    {
        UnitPlanning::firstOrCreate(['fiscal_year' => 2027, 'directorate_id' => $this->root->id], ['planning_by' => $this->admin->id, 'planning_at' => now()]);
        ReportingWindow::create(['fiscal_year' => 2027, 'period_type' => ReportingWindows::PLAN, 'period' => 1, 'opens_on' => '2026-08-01', 'closes_on' => '2026-08-31']);
        $unit = Directorate::where('code', 'UDIG')->firstOrFail();
        $window = app(PlanningWindow::class);
        $calendar = app(ReportingWindows::class);
        $this->decideACut(0.01);

        $this->assertFalse($window->isOpenFor(2027, $unit->id), 'the plan is finalized');
        $this->assertNotNull($calendar->planClosedReason($this->head, 2027, $unit->id), 'and submission is closed');

        // a round that does not take new activities changes nothing
        $plain = $this->openRound(0);
        $this->assertFalse($window->isOpenFor(2027, $unit->id));
        app(AdjustmentRounds::class)->close($this->admin, $plain);

        $round = app(AdjustmentRounds::class)->open($this->admin, 2027, '2027-04-30', 'Supplementary budget: new projects', [], true);
        $this->assertTrue($window->isOpenFor(2027, $unit->id));
        $this->assertNull($calendar->planClosedReason($this->head, 2027, $unit->id));
        $this->assertNull($calendar->closedReason($this->head, 2027, ReportingWindows::APPROVAL, 1, $unit->id));

        // a new activity of the round: on its way, then added once approved
        $new = Activity::create([
            'fiscal_year' => 2027, 'program_id' => Program::firstOrFail()->id, 'directorate_id' => $unit->id,
            'description' => 'New project funded by the supplementary budget', 'quarter' => 3, 'priority' => 'Alta',
            'planned_start' => '2027-07-01', 'planned_end' => '2027-12-31', 'status' => Activity::STATUS_DRAFT,
        ]);
        $new->lineItems()->create(['code' => 'N-1', 'economic_classification_id' => EconomicClassification::firstOrFail()->id, 'estimated_amount' => 8000]);
        $rounds = app(AdjustmentRounds::class);
        $row = $rounds->progress($round)[$this->root->id];
        $this->assertSame(1, $row['new']);
        $this->assertEqualsWithDelta(8000, $row['adding'], 0.01);
        $this->assertSame('revising', $row['state']);

        $this->approveRevision($new);
        $row = $rounds->progress($round)[$this->root->id];
        $this->assertEqualsWithDelta(8000, $row['added'], 0.01);
        $this->assertEqualsWithDelta(0, $row['reduced'], 0.01, 'nothing came off the activities it had');

        $this->actingAs($this->head)->get(route('planning.adjustment'))->assertOk()->assertSee('New project funded by the supplementary budget');

        // confirming ends it for the directorate
        $rounds->confirm($this->head, $round->units()->firstOrFail());
        $this->assertFalse($window->isOpenFor(2027, $unit->id));
        $this->assertNotNull($calendar->planClosedReason($this->head, 2027, $unit->id));
    }

    public function test_a_line_cannot_be_cut_below_what_was_reported_as_executed(): void
    {
        $activity = $this->activityWithLine(1000);
        $line = $activity->lineItems()->where('estimated_amount', '>=', 1000)->firstOrFail();
        BudgetExecution::create(['line_item_id' => $line->id, 'month' => 1, 'executed' => 600, 'status' => BudgetExecution::VERIFIED]);

        $line->update(['estimated_amount' => 700]); // still above what was executed
        $this->expectException(DomainException::class);
        $line->update(['estimated_amount' => 500]);
    }
}
