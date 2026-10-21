<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityVersion;
use App\Models\CorrectiveAction;
use App\Models\Directorate;
use App\Models\PlanBaseline;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Services\ActivityVersions;
use App\Services\ActivityWorkflow;
use App\Services\ApprovedPlanReport;
use App\Services\PlanBaselines;
use App\Services\PlanningWindow;
use App\Support\PlanOptions;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Nil returns, outside approvals, structure at approval, corrective actions and baselines: off by default, as described when on. */
#[Group('office')]
class OptionalPlanRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $head;

    private User $admin;

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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function approvedIn(string $unit, int $year = 2027): Activity
    {
        return Activity::where('fiscal_year', $year)->where('status', Activity::STATUS_APPROVED)->where('version', '>', 0)
            ->whereHas('directorate', fn ($q) => $q->where('code', $unit))->firstOrFail();
    }

    // ── submission obligations and nil returns

    public function test_an_active_directorate_without_activities_is_expected_and_can_declare_a_nil_return(): void
    {
        $idle = Directorate::create(['code' => 'XIDLE', 'name' => 'Idle directorate', 'type' => 'National Directorate', 'active' => true]);
        $window = app(PlanningWindow::class);
        $this->assertFalse($window->plannedRoots(2027)->contains($idle->id), 'rule off: a directorate without activities is not expected');

        (new PlanOptions(obligations: true))->save();
        PlanningWindow::forgetTree();

        $this->assertTrue($window->plannedRoots(2027)->contains($idle->id));
        $row = collect($window->state(2027)['units'])->firstWhere('id', $idle->id);
        $this->assertTrue($row['nothing_yet']);
        $this->assertTrue($row['may_declare_nil']);
        $this->assertNull($window->endedBy(2027), 'the year cannot end while it has neither planned nor declared');

        try {
            $window->finalize($this->admin, 2027, ' ', $idle->id, true);
            $this->fail('a nil return needs a reason');
        } catch (DomainException) {
        }
        $window->finalize($this->admin, 2027, 'No programme of its own in 2027', $idle->id, true);

        $this->assertTrue(UnitPlanning::where(['fiscal_year' => 2027, 'directorate_id' => $idle->id])->firstOrFail()->nil_return);
    }

    public function test_a_directorate_with_activities_cannot_declare_a_nil_return_and_the_rule_must_be_on(): void
    {
        $root = Directorate::where('code', 'GDSC')->firstOrFail();
        $window = app(PlanningWindow::class);

        $this->expectException(DomainException::class);
        try {
            $window->declareNilReturn($this->admin, 2027, 'Nothing', $root->id); // rule off
        } catch (DomainException $e) {
            (new PlanOptions(obligations: true))->save();
            $window->declareNilReturn($this->admin, 2027, 'Nothing', $root->id); // it has approved activities
        }
    }

    // ── a revision that needs an approval outside the institution

    public function test_a_marked_revision_is_approved_only_with_the_reference_of_the_outside_approval(): void
    {
        (new PlanOptions(externalReference: true))->save();
        $activity = $this->approvedIn('UDIG');
        $workflow = app(ActivityWorkflow::class);
        $workflow->reopen($this->head, $activity, 'Moves money between programmes');
        $activity->forceFill(['status' => Activity::STATUS_VALIDATED, 'external_approval_required' => true])->save();
        $activity = $activity->fresh();

        $this->assertTrue($workflow->externalApprovalMissing($activity));
        $this->assertFalse($workflow->canApprove($this->head, $activity));
        $this->assertNull($workflow->next($this->head, $activity));

        $this->actingAs($this->head);
        Volt::test('pages.activities.show', ['activity' => $activity])->set('externalReference', 'MF/2027/123')->call('saveExternalReference')->assertHasNoErrors();
        $activity = $activity->fresh();
        $this->assertSame('MF/2027/123', $activity->external_approval_reference);
        $this->assertTrue($workflow->canApprove($this->head, $activity));

        $workflow->approve($this->head, $activity);
        $version = ActivityVersion::where('activity_id', $activity->id)->orderByDesc('version')->firstOrFail();
        $this->assertSame('MF/2027/123', $version->snapshot['activity']['external_approval_reference']);

        $workflow->reopen($this->head, $activity->fresh(), 'Another change');
        $this->assertFalse((bool) $activity->fresh()->external_approval_required, 'a new revision starts unmarked');
    }

    public function test_with_the_rule_off_a_mark_does_not_hold_back_the_approval(): void
    {
        $activity = $this->approvedIn('UDIG');
        $activity->forceFill(['status' => Activity::STATUS_VALIDATED, 'external_approval_required' => true])->save();

        $this->assertFalse(app(ActivityWorkflow::class)->externalApprovalMissing($activity->fresh()));
    }

    // ── reports keep the structure at approval

    public function test_the_report_can_show_the_unit_an_activity_had_when_it_was_approved(): void
    {
        $activity = Activity::where('fiscal_year', 2027)->whereHas('directorate', fn ($q) => $q->where('code', 'UDIG'))->firstOrFail();
        $activity->forceFill(['status' => Activity::STATUS_APPROVED])->save();
        app(ActivityVersions::class)->approved($activity->fresh(), $this->admin); // a version with the unit path
        $moved = Directorate::where('code', 'USC')->firstOrFail();
        $activity->fresh()->update(['directorate_id' => $moved->id]); // a restructuring after the approval

        $unitsOf = fn () => collect((new ApprovedPlanReport)->build(2027)['by_unit'])->flatMap(fn ($r) => collect($r['units'])->filter(fn ($u) => collect($u['rows'])->contains(fn ($row) => $row['activity']->id === $activity->id))->pluck('code'))->all();

        $this->assertSame(['USC'], $unitsOf(), 'rule off: the unit it has now');
        (new PlanOptions(structureAtApproval: true))->save();
        $this->assertSame(['UDIG'], $unitsOf(), 'rule on: the unit it had when approved');
    }

    // ── corrective actions

    public function test_corrective_actions_are_recorded_and_closed_only_when_tracked(): void
    {
        $activity = $this->approvedIn('UDIG', 2026);
        $director = User::where('email', 'director.udig@gdsc.tl')->firstOrFail();
        $page = fn () => Volt::actingAs($director)->test('pages.monitoring.execution-narrative', ['activityId' => $activity->id, 'month' => 10]);

        $page()->set('action', ['description' => 'Hire a surveyor', 'owner_name' => 'Ana', 'due_on' => '2026-11-30'])->call('addAction')->assertForbidden();

        (new PlanOptions(correctiveActions: true))->save();
        $page()->set('action', ['description' => 'Hire a surveyor', 'owner_name' => 'Ana', 'due_on' => '2026-11-30'])->call('addAction')->assertHasNoErrors();
        $item = CorrectiveAction::where('activity_id', $activity->id)->firstOrFail();
        $this->assertSame(CorrectiveAction::OPEN, $item->status);
        $this->assertSame(4, (int) $item->quarter);

        $page()->set('closingId', $item->id)->set('closingNote', 'Surveyor hired')->call('closeAction');
        $this->assertSame(CorrectiveAction::DONE, $item->fresh()->status);
        $this->assertSame($director->id, $item->fresh()->closed_by);
    }

    // ── baselines

    public function test_a_baseline_prints_the_plan_as_it_stood_and_approves_nothing(): void
    {
        try {
            app(PlanBaselines::class)->record($this->admin, 2027);
            $this->fail('the rule is off');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        (new PlanOptions(baselines: true))->save();
        $before = (new ApprovedPlanReport)->build(2027);
        $baseline = app(PlanBaselines::class)->record($this->admin, 2027, 'Plan sent with the budget proposal');
        $this->assertSame($before['totals']['count'], $baseline->activity_count);

        // a revision approved afterwards changes the plan in force, not the baseline
        $activity = $this->approvedIn('UDIG');
        $workflow = app(ActivityWorkflow::class);
        $workflow->reopen($this->head, $activity, 'More money');
        $activity->lineItems()->first()->update(['estimated_amount' => 999999]);
        app(ActivityVersions::class)->approved($activity->fresh(), $this->admin);
        $activity->fresh()->forceFill(['status' => Activity::STATUS_APPROVED])->save();

        $now = (new ApprovedPlanReport)->build(2027);
        $then = (new ApprovedPlanReport)->build(2027, $baseline->fresh());
        $this->assertNotEquals($before['totals']['total'], $now['totals']['total']);
        $this->assertEquals($before['totals']['total'], $then['totals']['total']);
        $this->assertSame($baseline->id, $then['baseline']['id']);
    }

    public function test_only_an_administrator_records_a_baseline_from_the_report(): void
    {
        (new PlanOptions(baselines: true))->save();
        $this->actingAs($this->head);
        $this->post(route('reports.approved-plan.baseline', ['year' => 2027]))->assertForbidden();

        $this->actingAs($this->admin);
        $this->post(route('reports.approved-plan.baseline', ['year' => 2027]), ['note' => 'For the record'])->assertRedirect();
        $b = PlanBaseline::firstOrFail();
        $this->get(route('reports.approved-plan', ['year' => 2027, 'baseline' => $b->id]))->assertOk()->assertSee('Baseline:');
    }
}
