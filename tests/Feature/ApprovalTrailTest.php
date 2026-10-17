<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\Program;
use App\Models\User;
use App\Services\ActivityWorkflow;
use App\Services\ApprovalTrail;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApprovalTrailTest extends TestCase
{
    use RefreshDatabase;

    private ActivityWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run(); // an office: its head approves
        $this->workflow = app(ActivityWorkflow::class);
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function draft(string $unit = 'UDIG', string $name = 'Trail test activity'): Activity
    {
        $activity = Activity::create([
            'fiscal_year' => 2027, 'program_id' => Program::firstOrFail()->id, 'directorate_id' => Directorate::where('code', $unit)->value('id'),
            'description' => $name, 'quarter' => 1, 'priority' => 'Alta', 'planned_start' => '2027-01-01', 'planned_end' => '2027-12-31',
            'status' => Activity::STATUS_DRAFT,
        ]);
        $activity->lineItems()->create(['code' => 'T-1', 'economic_classification_id' => EconomicClassification::firstOrFail()->id, 'estimated_amount' => 1000]);
        $activity->indicators()->create(['name' => 'Outputs', 'type' => 'Output', 'unit' => 'units', 'annual_target' => 5]);

        return $activity->fresh();
    }

    /** Put activities straight at a status without going through the chain (so the trail has no steps for them). */
    private function setStatus(Activity $a, string $status): Activity
    {
        DB::table('activities')->where('id', $a->id)->update(['status' => $status]);

        return $a->fresh();
    }

    public function test_the_trail_lists_every_step_with_who_and_when_and_the_approvers_note(): void
    {
        $a = $this->draft();
        $a = tap($a, fn () => $this->workflow->advance($this->user('tecnico.udig@gdsc.tl'), $a))->fresh();
        $this->workflow->advance($this->user('director.udig@gdsc.tl'), $a->fresh());
        $this->workflow->advance($this->user('chefe.gdsc@gdsc.tl'), $a->fresh());
        $this->workflow->approve($this->user('chefe.gdsc@gdsc.tl'), $a->fresh(), 'Within the ceiling.');

        $trail = app(ApprovalTrail::class)->for($a->fresh());

        $this->assertSame(['Created', 'Prepared', 'Verified', 'Certified', 'Approved'], array_column($trail, 'label'));
        $this->assertSame([null, 'Technical Officer UDIG', 'Director UDIG', 'Chefe GDSC', 'Chefe GDSC'], array_column($trail, 'by'));
        $this->assertSame('Within the ceiling.', end($trail)['note']);

        $approval = app(ApprovalTrail::class)->approval($a->fresh());
        $this->assertSame('Chefe GDSC', $approval['by']);
    }

    public function test_returning_an_activity_needs_a_reason_and_the_reason_is_in_the_trail(): void
    {
        $a = $this->setStatus($this->draft(), Activity::STATUS_VALIDATED);
        $chefe = $this->user('chefe.gdsc@gdsc.tl');

        try {
            $this->workflow->sendBack($chefe, $a, '   ');
            $this->fail('a return without a reason must be refused');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Say why', $e->getMessage());
        }
        $this->assertSame(Activity::STATUS_VALIDATED, $a->fresh()->status, 'nothing changed');

        $this->workflow->sendBack($chefe, $a->fresh(), 'The budget line for travel is missing.');

        $last = collect(app(ApprovalTrail::class)->for($a->fresh()))->last();
        $this->assertSame('Returned for correction', $last['label']);
        $this->assertTrue($last['is_reason']);
        $this->assertSame('The budget line for travel is missing.', $last['note']);
        $this->assertSame('Chefe GDSC', $last['by']);
    }

    public function test_reopening_an_approved_activity_shows_in_the_trail(): void
    {
        $a = $this->setStatus($this->draft(), Activity::STATUS_APPROVED);
        $this->workflow->reopen($this->user('chefe.gdsc@gdsc.tl'), $a->fresh(), 'A new quarter was added.');

        $last = collect(app(ApprovalTrail::class)->for($a->fresh()))->last();
        $this->assertSame('Revision started', $last['label']);
        $this->assertSame('A new quarter was added.', $last['note']);
    }

    public function test_bulk_approval_approves_what_it_can_and_explains_the_rest(): void
    {
        $chefe = $this->user('chefe.gdsc@gdsc.tl');
        $ready1 = $this->setStatus($this->draft('UDIG', 'Ready one'), Activity::STATUS_VALIDATED);
        $ready2 = $this->setStatus($this->draft('USC', 'Ready two'), Activity::STATUS_VALIDATED);
        $draft = $this->draft('UDIG', 'Still a draft');
        $done = $this->setStatus($this->draft('UDIG', 'Already approved'), Activity::STATUS_APPROVED);

        $result = $this->workflow->approveMany($chefe, [$ready1->id, $ready2->id, $draft->id, $done->id, 999999], 'Batch note');

        $this->assertEqualsCanonicalizing([$ready1->id, $ready2->id], $result['approved']);
        $this->assertCount(3, $result['skipped']);
        $this->assertSame(Activity::STATUS_APPROVED, $ready1->fresh()->status);
        $this->assertSame($chefe->id, $ready1->fresh()->approved_by);
        $this->assertSame(Activity::STATUS_DRAFT, $draft->fresh()->status, 'a draft is never approved by a bulk action');

        $reasons = collect($result['skipped'])->pluck('reason')->implode(' ');
        $this->assertStringContainsString('Cannot be approved now', $reasons);
        $this->assertStringContainsString('Not found', $reasons);

        // each approval carries its note and the batch; the batch itself is one audit row
        $trailStep = collect(app(ApprovalTrail::class)->for($ready1->fresh()))->last();
        $this->assertTrue($trailStep['bulk']);
        $this->assertSame('Batch note', $trailStep['note']);
        $this->assertSame(1, AuditLog::where('action', 'activity.bulk_approved')->count());
        $this->assertSame($result['batch'], AuditLog::where('action', 'activity.bulk_approved')->first()->metadata['batch']);
    }

    public function test_a_repeated_bulk_approval_changes_nothing(): void
    {
        $chefe = $this->user('chefe.gdsc@gdsc.tl');
        $a = $this->setStatus($this->draft(), Activity::STATUS_VALIDATED);

        $first = $this->workflow->approveMany($chefe, [$a->id]);
        $second = $this->workflow->approveMany($chefe, [$a->id]);

        $this->assertSame([$a->id], $first['approved']);
        $this->assertSame([], $second['approved'], 'a stale selection cannot approve twice');
        $this->assertCount(1, $second['skipped']);
    }

    public function test_a_unit_director_cannot_bulk_approve_and_the_size_of_a_batch_is_limited(): void
    {
        $unitDirector = $this->user('director.udig@gdsc.tl');
        $a = $this->setStatus($this->draft(), Activity::STATUS_VALIDATED);

        $result = $this->workflow->approveMany($unitDirector, [$a->id]);
        $this->assertSame([], $result['approved']);
        $this->assertSame(Activity::STATUS_VALIDATED, $a->fresh()->status);

        $this->expectException(DomainException::class);
        $this->workflow->approveMany($this->user('chefe.gdsc@gdsc.tl'), range(1, ActivityWorkflow::BULK_LIMIT + 1));
    }

    public function test_what_is_awaited_depends_on_the_status_and_the_rules(): void
    {
        $a = $this->draft();
        $this->assertStringContainsString('head of the directorate', $this->workflow->awaiting($this->setStatus($a, Activity::STATUS_VALIDATED)));
        $this->assertStringContainsString('director of the unit', $this->workflow->awaiting($this->setStatus($a, Activity::STATUS_SUBMITTED)));
        $this->assertNull($this->workflow->awaiting($this->setStatus($a, Activity::STATUS_APPROVED)));
    }

    public function test_an_activity_without_audit_steps_falls_back_to_what_it_remembers(): void
    {
        $a = $this->draft();
        $chefe = $this->user('chefe.gdsc@gdsc.tl');
        DB::table('audit_logs')->where('auditable_type', $a->getMorphClass())->where('auditable_id', $a->id)->delete();
        DB::table('activities')->where('id', $a->id)->update(['status' => Activity::STATUS_APPROVED, 'submitted_at' => '2027-01-15 09:00:00', 'approved_at' => '2027-02-05 09:00:00', 'approved_by' => $chefe->id]);

        $trail = app(ApprovalTrail::class)->for($a->fresh());

        $this->assertSame(['Created', 'Prepared', 'Approved'], array_column($trail, 'label'));
        $this->assertSame('Chefe GDSC', end($trail)['by']);
    }

    public function test_audit_rows_of_a_deleted_activity_with_the_same_id_are_ignored(): void
    {
        $a = $this->draft();
        DB::table('audit_logs')->insert([
            'action' => 'activity.created', 'auditable_type' => $a->getMorphClass(), 'auditable_id' => $a->id,
            'description' => 'an older activity that had this id', 'created_at' => now()->subYear(), 'updated_at' => now()->subYear(),
        ]);

        $this->assertSame(['Created'], array_column(app(ApprovalTrail::class)->for($a->fresh()), 'label'));
    }
}
