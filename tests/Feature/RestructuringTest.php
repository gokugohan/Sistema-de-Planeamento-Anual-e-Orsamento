<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ApprovalDelegation;
use App\Models\ApproverAssignment;
use App\Models\AuditLog;
use App\Models\Directorate;
use App\Models\User;
use App\Services\ActivityWorkflow;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** A reshuffle or an absence must not stop the approvals: lists can be replaced, an interim covers a named step, activities can move. */
class RestructuringTest extends TestCase
{
    use RefreshDatabase;

    private ActivityWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        app()->setLocale('en');
        $this->seed(MtcSampleDataSeeder::class);
        $this->workflow = app(ActivityWorkflow::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function activityIn(string $unitCode, string $status): Activity
    {
        $a = Activity::where('fiscal_year', 2027)->whereHas('directorate', fn ($q) => $q->where('code', $unitCode))->firstOrFail();
        $a->forceFill(['status' => $status, 'approved_at' => null, 'approved_by' => null])->saveQuietly();

        return $a->fresh();
    }

    private function appoint(Directorate $unit, User $user, string $step): void
    {
        ApproverAssignment::create(['directorate_id' => $unit->id, 'user_id' => $user->id, 'step' => $step, 'position' => 1]);
    }

    public function test_an_interim_approves_where_the_unit_has_a_list_but_only_while_the_appointment_runs(): void
    {
        $dgt = Directorate::where('code', 'DGT')->firstOrFail();
        $named = User::factory()->create();
        $this->appoint($dgt, $named, 'approve');
        $stand = User::factory()->create();
        $activity = $this->activityIn('DNTT', Activity::STATUS_VALIDATED);

        $this->assertFalse($this->workflow->canApprove($stand->fresh(), $activity), 'not named, not appointed');

        $d = ApprovalDelegation::create(['user_id' => $stand->id, 'directorate_id' => $dgt->id, 'starts_on' => '2026-10-10', 'ends_on' => '2026-10-20']);
        $this->assertTrue($this->workflow->canApprove($stand->fresh(), $activity), 'standing in for the head, they act at the step the list governs');

        $d->update(['revoked_at' => now()]);
        $this->assertFalse($this->workflow->canApprove($stand->fresh(), $activity), 'it ends with the appointment');
    }

    public function test_an_interim_certifies_for_the_unit_but_does_not_prepare_in_place_of_the_preparers(): void
    {
        $dntt = Directorate::where('code', 'DNTT')->firstOrFail();
        $certifier = User::factory()->create();
        $preparer = User::factory()->create();
        $this->appoint($dntt, $certifier, 'certify');
        $this->appoint($dntt, $preparer, 'prepare');
        $stand = User::factory()->create();
        $stand->assignRole('Planning'); // someone who can work in the unit: the appointment gives the head's steps
        ApprovalDelegation::create(['user_id' => $stand->id, 'directorate_id' => $dntt->id, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);

        $submitted = $this->activityIn('DNTT', Activity::STATUS_SUBMITTED);
        $this->assertNotNull($this->workflow->next($stand->fresh(), $submitted), 'certifying is the head\'s step');

        $draft = $this->activityIn('DNTT', Activity::STATUS_DRAFT);
        $this->assertNull($this->workflow->next($stand->fresh(), $draft), 'preparing stays with the preparers');
    }

    public function test_one_person_takes_over_in_every_list_of_the_units_a_director_keeps(): void
    {
        $head = $this->user('director.dgt@gdsc.tl');
        $dgt = Directorate::where('code', 'DGT')->firstOrFail();
        $dntt = Directorate::where('code', 'DNTT')->firstOrFail();
        $other = Directorate::where('code', 'DGC')->firstOrFail();
        $old = User::factory()->create();
        $new = User::factory()->create();
        $this->appoint($dgt, $old, 'approve');
        $this->appoint($dntt, $old, 'certify');
        $this->appoint($dntt, $new, 'certify'); // already named here: the old one just leaves
        $this->appoint($other, $old, 'approve'); // a unit this director does not keep

        Volt::actingAs($head)->test('pages.approvals.approver-lists')->set('replaceOld', (string) $old->id)->set('replaceNew', (string) $new->id)->call('replaceEverywhere')->assertHasNoErrors();

        $this->assertTrue(ApproverAssignment::where(['directorate_id' => $dgt->id, 'step' => 'approve', 'user_id' => $new->id])->exists());
        $this->assertSame(1, ApproverAssignment::where(['directorate_id' => $dntt->id, 'step' => 'certify', 'user_id' => $new->id])->count());
        $this->assertFalse(ApproverAssignment::where('user_id', $old->id)->whereIn('directorate_id', [$dgt->id, $dntt->id])->exists());
        $this->assertTrue(ApproverAssignment::where(['directorate_id' => $other->id, 'user_id' => $old->id])->exists(), 'another directorate is not theirs to change');
    }

    public function test_leaving_a_list_empty_says_so_and_the_default_rule_returns(): void
    {
        $head = $this->user('director.dgt@gdsc.tl');
        $dgt = Directorate::where('code', 'DGT')->firstOrFail();
        $solo = User::factory()->create();
        $this->appoint($dgt, $solo, 'approve');
        $row = ApproverAssignment::where('user_id', $solo->id)->firstOrFail();

        Volt::actingAs($head)->test('pages.approvals.approver-lists')->call('remove', $row->id)->assertDispatched('toast');

        $activity = $this->activityIn('DNTT', Activity::STATUS_VALIDATED);
        $this->assertTrue($this->workflow->canApprove($head->fresh(), $activity), 'no list: the head approves again');
    }

    public function test_an_administrator_moves_an_activity_to_another_unit_with_a_remark_and_the_new_heads_take_over(): void
    {
        $admin = User::where('email', 'admin@gdsc.tl')->first() ?? tap(User::factory()->create(), fn ($u) => $u->assignRole('Admin'));
        $activity = $this->activityIn('DNTT', Activity::STATUS_VALIDATED);
        $oldHead = $this->user('director.dgt@gdsc.tl');
        $newUnit = Directorate::where('code', 'DGC')->firstOrFail();
        $newHead = $this->user('director.dgc@gdsc.tl');
        $this->assertTrue($this->workflow->canApprove($oldHead, $activity));

        Volt::actingAs($admin)->test('pages.activities.show', ['activity' => $activity])
            ->set('moveUnitId', (string) $newUnit->id)->call('moveToUnit')->assertHasErrors('moveRemark')
            ->set('moveRemark', 'The department moved to the communications directorate')->call('moveToUnit')->assertHasNoErrors();

        $activity->refresh();
        $this->assertSame($newUnit->id, $activity->directorate_id);
        $this->assertFalse($this->workflow->canApprove($oldHead->fresh(), $activity), 'the old directorate no longer decides');
        $this->assertTrue($this->workflow->canApprove($newHead->fresh(), $activity), 'the new one does, from the status where it stands');
        $this->assertTrue(AuditLog::where('metadata->moved', true)->orWhere('new_values', 'like', '%moved%')->exists() || AuditLog::where('auditable_id', $activity->id)->exists());
    }

    public function test_only_an_administrator_moves_an_activity(): void
    {
        $activity = $this->activityIn('DNTT', Activity::STATUS_DRAFT);
        $head = $this->user('director.dgt@gdsc.tl');

        Volt::actingAs($head)->test('pages.activities.show', ['activity' => $activity])
            ->set('moveUnitId', (string) Directorate::where('code', 'DGC')->value('id'))->set('moveRemark', 'because')->call('moveToUnit')->assertForbidden();
    }

    public function test_when_a_unit_is_moved_in_the_structure_the_new_parent_decides_without_any_change_to_the_activities(): void
    {
        $dnc = Directorate::where('code', 'DNTT')->firstOrFail();
        $newParent = Directorate::where('code', 'DGC')->firstOrFail();
        $activity = $this->activityIn('DNTT', Activity::STATUS_VALIDATED);
        $oldHead = $this->user('director.dgt@gdsc.tl');
        $newHead = $this->user('director.dgc@gdsc.tl');

        $dnc->update(['parent_id' => $newParent->id]);

        $this->assertFalse($this->workflow->canApprove($oldHead->fresh(), $activity->fresh()));
        $this->assertTrue($this->workflow->canApprove($newHead->fresh(), $activity->fresh()));
    }
}
