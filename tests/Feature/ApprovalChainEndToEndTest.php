<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\Program;
use App\Models\User;
use App\Services\ActivityWorkflow;
use App\Support\ApprovalRules;
use App\Support\Institution;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Database\Seeders\Samples\MtcSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One real activity walked through the whole approval chain with the accounts of each sample dataset:
 * the whole ministry (the Minister approves, the plan goes to the CRO) and a single office (its head
 * approves, the plan goes to the ministry).
 */
class ApprovalChainEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private ActivityWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
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
        $activity = $this->step($this->user('planner.dntt@goku.tl'), $activity, Activity::STATUS_SUBMITTED);
        $activity = $this->step($this->user('director.dntt@goku.tl'), $activity, Activity::STATUS_REVIEWED);
        $activity = $this->step($this->user('director.dgt@goku.tl'), $activity, Activity::STATUS_VALIDATED);

        $head = $this->user('director.dgt@goku.tl');
        $this->assertTrue($this->workflow->canApprove($head, $activity), 'the head of the directorate-general approves');
        $this->assertFalse($this->workflow->canApprove($this->user('ministro@goku.tl'), $activity), 'the Minister does not approve plans');
        $this->assertFalse($this->workflow->canApprove($this->user('director.dgc@goku.tl'), $activity), 'nor does the head of another directorate-general');
        $this->assertFalse($this->workflow->canApprove($this->user('director.dntt@goku.tl'), $activity), 'a national directorate below only validates');

        $this->workflow->approve($head, $activity);
        $activity->refresh();
        $this->assertSame(Activity::STATUS_APPROVED, $activity->status);
        $this->assertSame($head->id, $activity->approved_by);
    }

    public function test_a_single_office_is_approved_by_its_head(): void
    {
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run(); // the Gabinete de Digitalização e Segurança Cibernética
        $this->assertSame(Institution::SUBORDINATE, Institution::level());
        $this->assertNull(User::where('email', 'ministro@goku.tl')->first(), 'an office has no Minister');

        $activity = $this->draftIn('UDIG'); // the Unidade para o Digital, under the Gabinete
        $activity = $this->step($this->user('planner.udig@goku.tl'), $activity, Activity::STATUS_SUBMITTED);
        $activity = $this->step($this->user('director.udig@goku.tl'), $activity, Activity::STATUS_REVIEWED);
        $activity = $this->step($this->user('chefe.gdsc@goku.tl'), $activity, Activity::STATUS_VALIDATED);

        $chefe = $this->user('chefe.gdsc@goku.tl');
        $this->assertTrue($this->workflow->canApprove($chefe, $activity));
        $this->assertFalse($this->workflow->canApprove($this->user('director.udig@goku.tl'), $activity), 'a unit director only validates');

        $activity = $this->step($chefe, $activity, Activity::STATUS_APPROVED);
        $this->assertSame($chefe->id, $activity->approved_by);
        $this->assertNotNull($activity->approved_at);
    }

    public function test_an_account_moves_an_activity_one_step_at_a_time(): void
    {
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        $activity = $this->draftIn('UDIG');
        $chefe = $this->user('chefe.gdsc@goku.tl');

        // Even the head of the office cannot jump from Draft to Approved: one advance is one step.
        $this->assertSame(Activity::STATUS_SUBMITTED, $this->workflow->advance($chefe, $activity));
        $this->assertFalse($this->workflow->canApprove($chefe, $activity->fresh()), 'it must be validated before it can be approved');
    }
}
