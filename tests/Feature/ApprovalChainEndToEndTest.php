<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ApprovalDelegation;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\Program;
use App\Models\User;
use App\Services\ActivityWorkflow;
use App\Services\ApprovalInbox;
use App\Services\PlanStatus;
use App\Support\Institution;
use App\Support\PlanOptions;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Database\Seeders\Samples\MtcSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * One real activity walked through the whole approval chain with the accounts of each sample dataset:
 * the whole ministry (the Minister approves, the plan goes to the CRO) and a single office (its head
 * approves, the plan goes to the ministry).
 */
#[Group('office')]
class ApprovalChainEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private ActivityWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        (new PlanOptions(completeBeforeApproval: false))->save(); // these tests are about the chain, not the order of the year
        app()->setLocale('en');
        $this->workflow = app(ActivityWorkflow::class);
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    /** A complete draft activity (budget line and indicator) in the given unit. */
    private function draftIn(string $unitCode): Activity
    {
        $unit = Directorate::where('code', $unitCode)->firstOrFail();
        $activity = Activity::create([
            'fiscal_year' => 2027, 'program_id' => Program::firstOrFail()->id, 'directorate_id' => $unit->id,
            'description' => 'End-to-end test activity', 'quarter' => 1, 'priority' => 'Alta',
            'planned_start' => '2027-01-01', 'planned_end' => '2027-12-31', 'status' => Activity::STATUS_DRAFT,
        ]);
        $activity->lineItems()->create(['code' => 'E2E-1', 'economic_classification_id' => EconomicClassification::firstOrFail()->id, 'estimated_amount' => 1000]);
        $activity->indicators()->create(['name' => 'Outputs', 'type' => 'Output', 'unit' => 'units', 'annual_target' => 5]);

        return $activity->fresh();
    }

    private function step(User $user, Activity $activity, string $expected): Activity
    {
        $this->assertSame($expected, $this->workflow->advance($user, $activity), "{$user->email} should move it to {$expected}");

        return $activity->fresh();
    }

    public function test_in_the_whole_ministry_each_directorate_head_approves_its_own_plan(): void
    {
        $this->seed(MtcSampleDataSeeder::class);
        $this->assertSame(Institution::MINISTRY, Institution::level());

        $activity = $this->draftIn('DNTT'); // a national directorate under the Direção-Geral dos Transportes
        $activity = $this->step($this->user('tecnico.dntt@gdsc.tl'), $activity, Activity::STATUS_SUBMITTED);
        $activity = $this->step($this->user('director.dntt@gdsc.tl'), $activity, Activity::STATUS_REVIEWED);

        $head = $this->user('director.dgt@gdsc.tl');
        $this->assertSame(Activity::STATUS_APPROVED, $this->workflow->reach($head, $activity), 'the head of the directorate-general validates and approves');
        $this->assertNull($this->workflow->next($this->user('ministro@gdsc.tl'), $activity), 'the Minister does not approve plans');
        $this->assertNull($this->workflow->next($this->user('director.dgc@gdsc.tl'), $activity), 'nor does the head of another directorate-general');
        $this->assertNull($this->workflow->next($this->user('director.dntt@gdsc.tl'), $activity), 'a national directorate below only certifies');

        $activity = $this->step($head, $activity, Activity::STATUS_APPROVED); // one click: validated and approved
        $this->assertSame($head->id, $activity->approved_by);
    }

    public function test_each_level_certifies_its_own_and_a_head_further_up_cannot_skip_it(): void
    {
        $this->seed(MtcSampleDataSeeder::class);
        $head = $this->user('director.dgt@gdsc.tl');
        $national = $this->user('director.dntt@gdsc.tl');

        $activity = $this->step($this->user('tecnico.dntt@gdsc.tl'), $this->draftIn('DNTT'), Activity::STATUS_SUBMITTED);
        $this->assertNull($this->workflow->next($head, $activity), 'the head of the directorate-general does not certify in place of the national director');
        $this->assertSame([], app(ApprovalInbox::class)->details($head, 'review'), 'so it is not waiting for them');

        // standing in for the national director by an interim appointment is how someone else takes the step
        $interim = User::factory()->create(); // a head from elsewhere, appointed for the national directorate
        $interim->assignRole('Director');
        ApprovalDelegation::create(['user_id' => $interim->id, 'directorate_id' => $activity->directorate_id, 'starts_on' => now()->subDay(), 'ends_on' => now()->addDay(), 'reason' => 'Leave', 'created_by' => $head->id]);
        $this->assertSame(Activity::STATUS_REVIEWED, $this->workflow->next($interim, $activity));

        $activity = $this->step($national, $activity, Activity::STATUS_REVIEWED);
        $this->assertNull($this->workflow->next($national, $activity), 'the national director certifies once, not again at the level above');
        $this->assertSame(Activity::STATUS_APPROVED, $this->workflow->reach($head, $activity));
    }

    public function test_a_unit_with_no_head_is_certified_by_the_head_above_until_it_has_one(): void
    {
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        $chefe = $this->user('chefe.gdsc@gdsc.tl');
        $headOfUsc = $this->user('director.usc@gdsc.tl');
        $activity = $this->step($this->user('tecnico.usc@gdsc.tl'), $this->draftIn('USC'), Activity::STATUS_SUBMITTED);

        // USC has its head: the chefe of the gabinete waits for them
        $this->assertNull($this->workflow->next($chefe, $activity));

        // the post falls vacant: the unit is directed from the gabinete, which certifies in its place
        $headOfUsc->forceFill(['deactivated_at' => now()])->save();
        $workflow = app(ActivityWorkflow::class);
        $this->assertSame(Activity::STATUS_APPROVED, $workflow->reach($chefe, $activity), 'certifies for the unit, for the level above, and approves');
        $this->assertStringContainsString('(GDSC) to certify it', $workflow->awaiting($activity));
        $this->assertSame(['USC'], collect(app(ApprovalInbox::class)->details($chefe, 'review'))->map(fn ($d) => $d['unit']->code)->all());

        // an interim for the unit fills the post: the gabinete waits for them again
        $interim = User::factory()->create();
        $interim->assignRole('Director');
        ApprovalDelegation::create(['user_id' => $interim->id, 'directorate_id' => $activity->directorate_id, 'starts_on' => now()->subDay(), 'ends_on' => now()->addMonth(), 'reason' => 'Vacant post', 'created_by' => $chefe->id]);
        $this->assertNull(app(ActivityWorkflow::class)->next($chefe, $activity));
        $this->assertSame(Activity::STATUS_REVIEWED, app(ActivityWorkflow::class)->next($interim, $activity));
    }

    public function test_a_single_office_is_approved_by_its_head(): void
    {
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run(); // the Gabinete de Digitalização e Segurança Cibernética
        $this->assertSame(Institution::SUBORDINATE, Institution::level());
        $this->assertNull(User::where('email', 'ministro@gdsc.tl')->first(), 'an office has no Minister');

        $activity = $this->draftIn('UDIG'); // the Unidade para o Digital, under the Gabinete
        $activity = $this->step($this->user('tecnico.udig@gdsc.tl'), $activity, Activity::STATUS_SUBMITTED);
        $activity = $this->step($this->user('director.udig@gdsc.tl'), $activity, Activity::STATUS_REVIEWED);

        $chefe = $this->user('chefe.gdsc@gdsc.tl');
        $this->assertSame(Activity::STATUS_APPROVED, $this->workflow->reach($chefe, $activity));
        $this->assertNull($this->workflow->next($this->user('director.udig@gdsc.tl'), $activity), 'a unit director only certifies');

        $activity = $this->step($chefe, $activity, Activity::STATUS_APPROVED); // the head validates and approves in one click
        $this->assertSame($chefe->id, $activity->approved_by);
        $this->assertNotNull($activity->approved_at);
    }

    public function test_an_account_moves_an_activity_one_step_at_a_time(): void
    {
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        $activity = $this->draftIn('UDIG');
        $chefe = $this->user('chefe.gdsc@gdsc.tl');

        // A director does not submit (the one who prepares is not the one who checks), and once submitted even the head
        // of the office cannot jump to Approved: the unit's director certifies first.
        $this->assertNull($this->workflow->next($chefe, $activity), 'a director does not submit');
        $this->assertSame(Activity::STATUS_SUBMITTED, $this->workflow->advance($this->user('tecnico.udig@gdsc.tl'), $activity));
        $this->assertFalse($this->workflow->canApprove($chefe, $activity->fresh()), 'it must be certified before it can be approved');
    }

    public function test_a_unit_director_certifies_while_other_units_still_plan_and_only_the_final_approval_waits(): void
    {
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run(null, 'draft'); // every other activity of the office is a draft
        (new PlanOptions(completeBeforeApproval: true))->save(); // the rule this test is about (off in this class)
        $activity = $this->step($this->user('tecnico.udig@gdsc.tl'), $this->draftIn('UDIG'), Activity::STATUS_SUBMITTED);

        $activity = $this->step($this->user('director.udig@gdsc.tl'), $activity, Activity::STATUS_REVIEWED); // certified at once
        $chefe = $this->user('chefe.gdsc@gdsc.tl');
        $activity = $this->step($chefe, $activity, Activity::STATUS_VALIDATED); // validated; the approval waits for the plan
        $this->assertFalse($this->workflow->canApprove($chefe, $activity), 'the final approval waits until the whole plan is submitted');
        $this->assertNotNull(app(PlanStatus::class)->holdBack($activity));
    }
}
