<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityVersion;
use App\Models\AdjustedBudget;
use App\Models\BudgetExecution;
use App\Models\IndicatorProgress;
use App\Models\User;
use App\Services\ActivityVersions;
use App\Services\ActivityWorkflow;
use App\Services\PlanningWindow;
use Database\Seeders\Samples\MtcSampleDataSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The plan is the centre: there is no envelope. An approval makes a version whose amounts are in force; a change to
 * an approved activity is a revision with a reason, through the whole chain again, and the version in force stays
 * until the revision is approved. Nothing is overwritten.
 */
class ActivityRevisionTest extends TestCase
{
    use RefreshDatabase;

    private ActivityWorkflow $workflow;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->seed(MtcSampleDataSeeder::class);
        $this->workflow = app(ActivityWorkflow::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    private function approved(): Activity
    {
        return Activity::where('fiscal_year', 2027)->where('status', Activity::STATUS_APPROVED)
            ->whereHas('directorate', fn ($q) => $q->where('code', 'DNTT'))->with('lineItems', 'indicators')->firstOrFail();
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    /** Move an activity through the whole chain as an administrator. */
    private function approveAll(Activity $activity): Activity
    {
        for ($n = 0; $n < 6 && $activity->status !== Activity::STATUS_APPROVED; $n++) {
            $this->workflow->canApprove($this->admin, $activity) ? $this->workflow->approve($this->admin, $activity) : $this->workflow->advance($this->admin, $activity);
            $activity = $activity->fresh();
        }

        return $activity;
    }

    public function test_an_approved_sample_activity_is_version_one_and_its_amounts_are_in_force(): void
    {
        $a = $this->approved();

        $this->assertSame(1, (int) $a->version);
        $this->assertSame(1, ActivityVersion::where('activity_id', $a->id)->count());
        foreach ($a->lineItems as $line) {
            $this->assertEqualsWithDelta((float) $line->estimated_amount, (float) AdjustedBudget::where('line_item_id', $line->id)->value('approved_amount'), 0.001);
        }
    }

    public function test_a_revision_needs_a_reason_and_the_right_people(): void
    {
        $a = $this->approved();

        $this->assertFalse($this->workflow->canReopen($this->user('planner.dgc@goku.tl'), $a), 'a planner of another directorate');
        $this->assertTrue($this->workflow->canReopen($this->user('planner.dntt@goku.tl'), $a), 'the unit prepares its revision');
        $this->assertTrue($this->workflow->canReopen($this->user('director.dntt@goku.tl'), $a));

        try {
            $this->workflow->reopen($this->user('planner.dntt@goku.tl'), $a, ' ');
            $this->fail('a reason is required');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Say why', $e->getMessage());
        }
        $this->assertSame(Activity::STATUS_APPROVED, $a->fresh()->status);
    }

    public function test_the_version_in_force_stays_until_the_revision_is_approved_and_then_the_next_version_takes_over(): void
    {
        $a = $this->approved();
        $line = $a->lineItems->first();
        $before = (float) $line->estimated_amount;

        $this->workflow->reopen($this->user('planner.dntt@goku.tl'), $a, 'The cost of fuel went up.');
        $a = $a->fresh();
        $this->assertSame(Activity::STATUS_DRAFT, $a->status);
        $this->assertTrue(ActivityVersions::revising($a));
        $this->assertSame('The cost of fuel went up.', $a->revision_reason);

        // the unit changes the amount: the approved amount does not move while the revision is pending
        $line->update(['estimated_amount' => $before + 500]);
        $this->assertEqualsWithDelta($before, (float) AdjustedBudget::where('line_item_id', $line->id)->value('approved_amount'), 0.001);
        $changes = app(ActivityVersions::class)->changes($a->fresh());
        $this->assertNotEmpty($changes['lines']);
        $this->assertEqualsWithDelta($changes['total_from'] + 500, $changes['total_to'], 0.001);

        $a = $this->approveAll($a->fresh());
        $this->assertSame(2, (int) $a->version);
        $this->assertNull($a->revision_reason);
        $this->assertEqualsWithDelta($before + 500, (float) AdjustedBudget::where('line_item_id', $line->id)->value('approved_amount'), 0.001);
        $v2 = ActivityVersion::where(['activity_id' => $a->id, 'version' => 2])->firstOrFail();
        $this->assertSame('The cost of fuel went up.', $v2->reason);
        $this->assertEqualsWithDelta($before, (float) collect(ActivityVersion::where(['activity_id' => $a->id, 'version' => 1])->first()->snapshot['lines'])->firstWhere('id', $line->id)['estimated_amount'], 0.001, 'version 1 is kept as it was');
    }

    public function test_spending_and_progress_keep_their_lines_and_indicators(): void
    {
        $a = $this->approved();
        $line = $a->lineItems->first();
        BudgetExecution::create(['line_item_id' => $line->id, 'month' => 1, 'quarter' => 1, 'committed' => 10, 'paid' => 5, 'status' => BudgetExecution::SUBMITTED]);
        $indicator = $a->indicators->first();
        IndicatorProgress::create(['indicator_id' => $indicator->id, 'quarter' => 1, 'achieved_value' => 1, 'status' => 'Submitted']);

        $this->expectException(DomainException::class);
        $line->delete();
    }

    public function test_an_indicator_with_progress_cannot_be_removed(): void
    {
        $a = $this->approved();
        $indicator = $a->indicators->first();
        IndicatorProgress::create(['indicator_id' => $indicator->id, 'quarter' => 1, 'achieved_value' => 1, 'status' => 'Submitted']);

        $this->expectException(DomainException::class);
        $indicator->delete();
    }

    public function test_an_activity_approved_once_is_revised_not_deleted(): void
    {
        $a = $this->approved();
        $this->workflow->reopen($this->admin, $a, 'Rework the plan.');

        $this->assertFalse($this->admin->can('delete', $a->fresh()), 'it is part of the record of the plan');
    }

    public function test_finalizing_the_directorate_does_not_stop_a_revision(): void
    {
        $a = $this->approved();
        $window = app(PlanningWindow::class);
        // decide everything in the directorate so it can be finalized
        Activity::where('fiscal_year', 2027)->whereIn('directorate_id', \App\Models\Directorate::find($window->rootOf($a->directorate_id))->getSelfAndDescendantIds())
            ->update(['status' => Activity::STATUS_APPROVED]);
        $window->finalize($this->admin, 2027, null, $a->directorate_id);
        $this->assertFalse($window->isOpenFor(2027, $a->directorate_id));

        $this->assertTrue($this->workflow->canReopen($this->user('planner.dntt@goku.tl'), $a->fresh()));
    }

    public function test_the_activity_page_offers_a_revision_and_shows_the_versions(): void
    {
        $a = $this->approved();
        $this->actingAs($this->user('planner.dntt@goku.tl'));

        Volt::test('pages.activities.show', ['activity' => $a])
            ->assertSee('Revise')->assertSee('Version 1')
            ->set('note', 'A new road section was added.')->call('reopen')
            ->assertSee('Revision in progress: version 2')->assertSee('A new road section was added.');
    }
}
