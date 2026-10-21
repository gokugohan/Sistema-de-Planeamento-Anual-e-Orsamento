<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ApproverAssignment;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\Program;
use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Services\ActivityWorkflow;
use App\Services\ApprovalInbox;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** Named people for the steps of the chain: they decide where a list exists, and the default rule stays where none does. */
#[Group('office')]
class ApproverListsTest extends TestCase
{
    use RefreshDatabase;

    private ActivityWorkflow $workflow;

    private Directorate $unit;

    private Directorate $root;

    private Activity $activity;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        $this->workflow = app(ActivityWorkflow::class);

        $this->unit = Directorate::where('code', 'UDIG')->firstOrFail();
        $this->root = Directorate::findOrFail($this->unit->parent_id);
        $this->activity = Activity::create([
            'fiscal_year' => 2027, 'program_id' => Program::firstOrFail()->id, 'directorate_id' => $this->unit->id,
            'description' => 'List test activity', 'quarter' => 1, 'priority' => 'Alta',
            'planned_start' => '2027-01-01', 'planned_end' => '2027-12-31', 'status' => Activity::STATUS_DRAFT,
        ]);
        $this->activity->lineItems()->create(['code' => 'L-1', 'economic_classification_id' => EconomicClassification::firstOrFail()->id, 'estimated_amount' => 1000]);
        $this->activity->indicators()->create(['name' => 'Outputs', 'type' => 'Output', 'unit' => 'units', 'annual_target' => 5]);
    }

    private function person(string $role = 'Viewer'): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    private function appoint(Directorate $unit, User $user, string $step): void
    {
        ApproverAssignment::create(['directorate_id' => $unit->id, 'user_id' => $user->id, 'step' => $step, 'position' => 1]);
    }

    private function next(User $user, ?string $status = null): ?string
    {
        if ($status) {
            $this->activity->forceFill(['status' => $status])->saveQuietly();
        }

        return $this->workflow->next($user->fresh(), $this->activity->fresh());
    }

    private function admin(): User
    {
        return $this->person('Admin');
    }

    public function test_a_list_of_preparers_decides_who_submits(): void
    {
        $planner = User::factory()->create(['directorate_id' => $this->unit->id]);
        $planner->assignRole('Planning');
        $other = User::factory()->create(['directorate_id' => $this->unit->id]);
        $other->assignRole('Planning');

        $this->assertSame(Activity::STATUS_SUBMITTED, $this->next($other), 'without a list, anyone who may submit does');

        $this->appoint($this->unit, $planner, ApproverAssignment::PREPARE);

        $this->assertSame(Activity::STATUS_SUBMITTED, $this->next($planner));
        $this->assertNull($this->next($other), 'the one not named cannot');
    }

    public function test_a_named_certifier_certifies_and_the_unit_director_not_named_does_not(): void
    {
        $director = User::where('email', 'director.udig@gdsc.tl')->firstOrFail();
        $certifier = $this->person();
        $this->assertNotNull($this->next($director, Activity::STATUS_SUBMITTED), 'without a list the director certifies');

        $this->appoint($this->unit, $certifier, ApproverAssignment::CERTIFY);

        $this->assertNotNull($this->next($certifier));
        $this->assertNull($this->next($director), 'not named, so not their turn');
        $this->assertNotNull($this->next($this->admin()), 'an administrator is never locked out');
    }

    public function test_the_unit_above_validates_from_its_own_list(): void
    {
        $head = User::where('email', 'chefe.gdsc@gdsc.tl')->firstOrFail();
        $validator = $this->person();

        $this->assertNotNull($this->next($head, Activity::STATUS_REVIEWED), 'without a list the director above validates');

        $this->appoint($this->root, $validator, ApproverAssignment::CERTIFY);

        $this->assertSame(Activity::STATUS_VALIDATED, $this->next($validator));
        $this->assertNull($this->next($head));
    }

    public function test_a_named_approver_gives_the_final_approval_and_a_list_reaches_the_units_below(): void
    {
        $head = User::where('email', 'chefe.gdsc@gdsc.tl')->firstOrFail();
        $approver = $this->person();
        $this->activity->forceFill(['status' => Activity::STATUS_VALIDATED])->saveQuietly();
        $this->assertTrue($this->workflow->canApprove($head->fresh(), $this->activity->fresh()), 'without a list the head approves');

        $this->appoint($this->root, $approver, ApproverAssignment::APPROVE); // the list of the office applies to its unit

        $this->assertTrue($this->workflow->canApprove($approver->fresh(), $this->activity->fresh()));
        $this->assertFalse($this->workflow->canApprove($head->fresh(), $this->activity->fresh()));
        $this->assertTrue($this->workflow->canApprove($this->admin(), $this->activity->fresh()));

        $this->workflow->approve($approver->fresh(), $this->activity->fresh(), 'Approved from the list');
        $this->assertSame(Activity::STATUS_APPROVED, $this->activity->fresh()->status);
    }

    public function test_the_unit_with_its_own_list_overrides_the_list_above(): void
    {
        $above = $this->person();
        $own = $this->person();
        $this->appoint($this->root, $above, ApproverAssignment::CERTIFY);
        $this->appoint($this->unit, $own, ApproverAssignment::CERTIFY);

        $this->assertNotNull($this->next($own, Activity::STATUS_SUBMITTED));
        $this->assertNull($this->next($above), 'the nearest list wins');
    }

    public function test_those_named_see_the_unit_and_find_their_work_in_the_inbox(): void
    {
        $certifier = $this->person();
        $this->activity->forceFill(['status' => Activity::STATUS_SUBMITTED, 'fiscal_year' => 2026])->saveQuietly();
        $this->assertFalse(app(ActivityPolicy::class)->inScope($certifier->fresh(), $this->unit->id));

        $this->appoint($this->unit, $certifier, ApproverAssignment::CERTIFY);

        $this->assertTrue(app(ActivityPolicy::class)->inScope($certifier->fresh(), $this->unit->id));
        $items = collect(app(ApprovalInbox::class)->items($certifier->fresh(), 2026));
        $this->assertTrue($items->contains(fn ($i) => str_contains($i['text'], 'waiting for your decision')));
    }

    public function test_the_activity_says_who_is_named(): void
    {
        $certifier = $this->person();
        $certifier->update(['name' => 'Maria Named']);
        $this->appoint($this->unit, $certifier, ApproverAssignment::CERTIFY);
        $this->activity->forceFill(['status' => Activity::STATUS_SUBMITTED])->saveQuietly();

        $this->assertStringContainsString('Maria Named', $this->workflow->awaiting($this->activity->fresh()));
    }

    public function test_a_director_keeps_the_lists_of_their_own_units_only_and_the_order_is_kept(): void
    {
        $director = User::where('email', 'director.udig@gdsc.tl')->firstOrFail();
        $a = $this->person();
        $b = $this->person();

        $page = Volt::actingAs($director)->test('pages.approvals.approver-lists')->set('unitId', (string) $this->unit->id)
            ->set('pick.certify', (string) $a->id)->call('add', 'certify')
            ->set('pick.certify', (string) $b->id)->call('add', 'certify');
        $rows = ApproverAssignment::where(['directorate_id' => $this->unit->id, 'step' => 'certify'])->orderBy('position')->get();
        $this->assertSame([$a->id, $b->id], $rows->pluck('user_id')->all());

        $page->call('move', $rows->last()->id, 'up');
        $this->assertSame([$b->id, $a->id], ApproverAssignment::where(['directorate_id' => $this->unit->id, 'step' => 'certify'])->orderBy('position')->pluck('user_id')->all());

        $page->call('remove', $rows->first()->id);
        $this->assertSame(1, ApproverAssignment::where('step', 'certify')->count());

        $foreign = Directorate::where('id', '!=', $this->unit->id)->where('id', '!=', $this->root->id)->firstOrFail();
        $page->set('unitId', (string) $foreign->id)->set('pick.approve', (string) $a->id)->call('add', 'approve')->assertForbidden();
    }

    public function test_only_directors_and_administrators_open_the_page(): void
    {
        $planner = User::factory()->create(['directorate_id' => $this->unit->id]);
        $planner->assignRole('Planning');

        $this->actingAs($planner)->get(route('approvals.lists'))->assertForbidden();
        $this->actingAs($this->admin())->get(route('approvals.lists'))->assertOk()->assertSee('Approval lists');
    }
}
