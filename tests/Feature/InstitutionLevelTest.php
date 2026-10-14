<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\ActivityWorkflow;
use App\Support\Institution;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A ministry sends its plan to the CRO and the Minister approves. A directorate or gabinete inside a
 * ministry sends its plan to the ministry (or its budget team) and its own head approves.
 */
class InstitutionLevelTest extends TestCase
{
    use RefreshDatabase;

    private function validatedActivity(): Activity
    {
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run(); // an office: level "subordinate"
        $activity = Activity::where('fiscal_year', 2027)->whereHas('directorate', fn ($q) => $q->where('code', 'UDIG'))->firstOrFail();
        $activity->forceFill(['status' => Activity::STATUS_VALIDATED, 'approved_at' => null, 'approved_by' => null])->save();

        return $activity->fresh();
    }

    public function test_an_office_sample_is_a_subordinate_institution(): void
    {
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();

        $this->assertSame(Institution::SUBORDINATE, Institution::level());
        $this->assertSame(MtcOfficeSampleDataSeeder::SAMPLE_RECIPIENT, Institution::recipient());
    }

    public function test_wording_that_names_the_cro_follows_the_level(): void
    {
        app()->setLocale('en');
        SystemSetting::set('institution_level', Institution::MINISTRY, 'string');
        $this->assertSame('CRO decision', Institution::label('CRO decision'));

        SystemSetting::set('institution_level', Institution::SUBORDINATE, 'string');
        $this->assertSame('Ministry decision', Institution::label('CRO decision'));
        $this->assertSame('Submitted to Ministry', Institution::label('Submitted to CRO'));

        app()->setLocale('pt');
        $this->assertSame('Decisão do Ministério', Institution::label('CRO decision'));
    }

    public function test_the_report_is_addressed_to_the_cro_or_to_the_ministry(): void
    {
        app()->setLocale('en');
        SystemSetting::set('institution_level', Institution::MINISTRY, 'string');
        $this->assertStringContainsString('(CRO)', Institution::coverLine());

        SystemSetting::set('institution_level', Institution::SUBORDINATE, 'string');
        SystemSetting::set('report_recipient', 'Ministry of X — Budget Directorate', 'string');
        $this->assertSame('Submitted to: Ministry of X — Budget Directorate', Institution::coverLine());

        SystemSetting::set('report_recipient', '', 'string');
        $this->assertStringContainsString("Ministry's budget preparation team", Institution::coverLine());
    }

    public function test_in_a_ministry_the_top_director_sends_up_and_cannot_approve(): void
    {
        $activity = $this->validatedActivity();
        SystemSetting::set('institution_level', Institution::MINISTRY, 'string');
        $head = User::where('email', 'chefe.gdsc@goku.tl')->firstOrFail();
        $workflow = app(ActivityWorkflow::class);

        $this->assertSame(Activity::STATUS_WAITING_APPROVAL, $workflow->next($head, $activity));
        $this->assertFalse($workflow->canApprove($head, $activity));
    }

    public function test_in_an_office_the_head_gives_the_final_approval(): void
    {
        $activity = $this->validatedActivity();
        $head = User::where('email', 'chefe.gdsc@goku.tl')->firstOrFail();
        $workflow = app(ActivityWorkflow::class);

        $this->assertTrue($head->isDirector());
        $this->assertSame(Activity::STATUS_APPROVED, $workflow->next($head, $activity));
        $this->assertTrue($workflow->canApprove($head, $activity));

        $this->assertSame(Activity::STATUS_APPROVED, $workflow->advance($head, $activity));
        $activity->refresh();
        $this->assertSame(Activity::STATUS_APPROVED, $activity->status);
        $this->assertSame($head->id, $activity->approved_by);
        $this->assertNotNull($activity->approved_at);
    }

    public function test_only_the_head_of_the_top_unit_approves_not_a_sub_unit_director(): void
    {
        $activity = $this->validatedActivity();
        $unitDirector = User::where('email', 'director.udig@goku.tl')->firstOrFail();
        $planner = User::where('email', 'planner.udig@goku.tl')->firstOrFail();
        $workflow = app(ActivityWorkflow::class);

        $this->assertNotSame(Activity::STATUS_APPROVED, $workflow->next($unitDirector, $activity));
        $this->assertFalse($workflow->canApprove($unitDirector, $activity));
        $this->assertFalse($workflow->canApprove($planner, $activity));
    }

    public function test_the_head_cannot_approve_another_offices_plan(): void
    {
        $activity = $this->validatedActivity();
        $head = User::where('email', 'chefe.gdsc@goku.tl')->firstOrFail();
        $other = \App\Models\Directorate::create(['code' => 'ZZ', 'name' => 'Another office', 'type' => 'Independent', 'administration' => 'direct', 'active' => true]);
        $activity->forceFill(['directorate_id' => $other->id])->save();

        $this->assertFalse(app(ActivityWorkflow::class)->canApprove($head, $activity->fresh()));
    }

    public function test_purging_the_office_sample_restores_the_ministry_defaults(): void
    {
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        app(\App\Services\SampleRegistry::class)->purge('mtc');

        $this->assertSame(Institution::MINISTRY, Institution::level());
        $this->assertNull(Institution::recipient());
    }
}
