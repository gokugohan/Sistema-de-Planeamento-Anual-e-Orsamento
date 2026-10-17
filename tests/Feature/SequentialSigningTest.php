<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivitySignature;
use App\Models\ApprovalDelegation;
use App\Models\ApproverAssignment;
use App\Models\Directorate;
use App\Models\User;
use App\Services\ActivityWorkflow;
use App\Services\ApprovalTrail;
use Database\Seeders\Samples\MtcSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** A list can ask everyone named to sign, one after the other as listed; the activity moves on only after the last signature. */
class SequentialSigningTest extends TestCase
{
    use RefreshDatabase;

    private ActivityWorkflow $workflow;

    private Directorate $dgt;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->seed(MtcSampleDataSeeder::class);
        $this->workflow = app(ActivityWorkflow::class);
        $this->dgt = Directorate::where('code', 'DGT')->firstOrFail();
    }

    private function activity(string $status): Activity
    {
        $a = Activity::where('fiscal_year', 2027)->whereHas('directorate', fn ($q) => $q->where('code', 'DNTT'))->firstOrFail();
        $a->forceFill(['status' => $status, 'approved_at' => null, 'approved_by' => null])->saveQuietly();

        return $a->fresh();
    }

    /** @return array<int, User> the people named, in order */
    private function list(string $step, int $n, bool $inOrder = true): array
    {
        $people = [];
        foreach (range(1, $n) as $i) {
            $people[] = $u = User::factory()->create();
            $u->assignRole('Director');
            ApproverAssignment::create(['directorate_id' => $this->dgt->id, 'user_id' => $u->id, 'step' => $step, 'position' => $i, 'sequential' => $inOrder]);
        }

        return $people;
    }

    public function test_the_final_approval_waits_for_each_name_in_turn(): void
    {
        [$a, $b, $c] = $this->list('approve', 3);
        $activity = $this->activity(Activity::STATUS_VALIDATED);

        $this->assertFalse($this->workflow->canApprove($b, $activity), 'the second cannot sign before the first');
        $this->assertTrue($this->workflow->canApprove($a, $activity));

        $this->workflow->approve($a, $activity);
        $this->assertSame(Activity::STATUS_VALIDATED, $activity->fresh()->status, 'one signature is not the approval');
        $this->assertFalse($this->workflow->canApprove($a->fresh(), $activity->fresh()), 'nobody signs twice');
        $this->assertFalse($this->workflow->canApprove($c, $activity->fresh()), 'the third waits for the second');
        $this->assertStringContainsString($b->name, $this->workflow->awaiting($activity->fresh()));
        $this->assertStringContainsString('1 of 3', $this->workflow->awaiting($activity->fresh()));

        $this->workflow->approve($b, $activity->fresh());
        $this->assertSame(Activity::STATUS_VALIDATED, $activity->fresh()->status);

        $this->workflow->approve($c, $activity->fresh());
        $fresh = $activity->fresh();
        $this->assertSame(Activity::STATUS_APPROVED, $fresh->status);
        $this->assertSame($c->id, $fresh->approved_by);
        $this->assertSame(3, ActivitySignature::where(['activity_id' => $activity->id, 'state' => 'completed'])->count(), 'the signatures stay on record once it has moved on');
        $this->assertSame(0, ActivitySignature::where(['activity_id' => $activity->id, 'state' => 'active'])->count());
    }

    public function test_a_list_that_is_not_in_order_still_lets_any_one_act(): void
    {
        [$a, $b] = $this->list('approve', 2, false);
        $activity = $this->activity(Activity::STATUS_VALIDATED);

        $this->assertTrue($this->workflow->canApprove($b, $activity));
        $this->workflow->approve($b, $activity);
        $this->assertSame(Activity::STATUS_APPROVED, $activity->fresh()->status);
    }

    public function test_an_administrator_is_never_held_up_by_the_order(): void
    {
        $this->list('approve', 2);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $activity = $this->activity(Activity::STATUS_VALIDATED);

        $this->workflow->approve($admin, $activity);
        $this->assertSame(Activity::STATUS_APPROVED, $activity->fresh()->status);
    }

    public function test_certifying_in_order_moves_the_activity_up_after_the_last_name(): void
    {
        [$a, $b] = $this->list('certify', 2);
        $activity = $this->activity(Activity::STATUS_SUBMITTED);

        $this->assertNull($this->workflow->next($b, $activity), 'not yet the second one\'s turn');
        $this->workflow->advance($a, $activity);
        $this->assertSame(Activity::STATUS_SUBMITTED, $activity->fresh()->status);

        $this->workflow->advance($b, $activity->fresh());
        $this->assertNotSame(Activity::STATUS_SUBMITTED, $activity->fresh()->status, 'moved on after the last name signed');
    }

    public function test_returning_the_activity_clears_the_signatures_and_the_round_starts_again(): void
    {
        [$a, $b] = $this->list('approve', 2);
        $activity = $this->activity(Activity::STATUS_VALIDATED);
        $this->workflow->approve($a, $activity);
        $this->assertSame(1, ActivitySignature::where('activity_id', $activity->id)->count());

        $this->workflow->sendBack($b, $activity->fresh(), 'Fix the budget lines.');
        $kept = ActivitySignature::where('activity_id', $activity->id)->get();
        $this->assertCount(1, $kept, 'the signature is kept, not deleted');
        $this->assertSame('voided', $kept[0]->state);
        $this->assertSame(1, $kept[0]->round);
        $this->assertSame($b->id, $kept[0]->closed_by);
        $this->assertSame('Fix the budget lines.', $kept[0]->close_reason);

        // the next round: the same person signs again and both signatures are on record, in their rounds
        $again = $activity->fresh();
        $again->forceFill(['status' => Activity::STATUS_VALIDATED])->saveQuietly();
        $this->workflow->approve($a, $again->fresh());
        $rows = ActivitySignature::where(['activity_id' => $activity->id, 'user_id' => $a->id])->orderBy('id')->get();
        $this->assertSame([1, 2], $rows->pluck('round')->all());
        $this->assertSame(['voided', 'active'], $rows->pluck('state')->all());

        $labels = collect(app(ApprovalTrail::class)->for($activity->fresh()))->where('kind', 'signature');
        $this->assertCount(2, $labels, 'the trail shows the signature given before the reset too');
        $this->assertStringContainsString('voided', $labels->first()['note']);
        $this->assertSame($a->name, $labels->first()['by']);
    }

    public function test_the_interim_of_the_unit_signs_in_the_place_of_the_next_name(): void
    {
        [$a, $b] = $this->list('approve', 2);
        $stand = User::factory()->create();
        ApprovalDelegation::create(['user_id' => $stand->id, 'directorate_id' => $this->dgt->id, 'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addDays(5)->toDateString()]);
        $activity = $this->activity(Activity::STATUS_VALIDATED);

        $this->workflow->approve($stand->fresh(), $activity);
        $sig = ActivitySignature::where('activity_id', $activity->id)->first();
        $this->assertSame($a->id, $sig->user_id, 'it takes the slot of the first name');
        $this->assertSame($stand->id, $sig->signed_by, 'and records who actually signed');
    }

    public function test_the_lists_page_switches_the_order_on_and_off(): void
    {
        $this->list('approve', 2, false);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $c = Volt::test('pages.approvals.approver-lists')->set('unitId', (string) $this->dgt->id);
        $c->call('toggleSequential', 'approve');
        $this->assertTrue(ApproverAssignment::where(['directorate_id' => $this->dgt->id, 'step' => 'approve'])->get()->every->sequential);
        $c->call('toggleSequential', 'approve');
        $this->assertFalse(ApproverAssignment::where(['directorate_id' => $this->dgt->id, 'step' => 'approve'])->get()->contains->sequential);
        $c->call('toggleSequential', 'prepare')->assertForbidden();
    }
}
