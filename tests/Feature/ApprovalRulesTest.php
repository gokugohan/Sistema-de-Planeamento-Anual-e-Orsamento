<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\ActivityWorkflow;
use App\Support\ApprovalRules;
use App\Support\Institution;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ApprovalRulesTest extends TestCase
{
    use RefreshDatabase;

    private function office(): void
    {
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
    }

    private function activityAt(string $unit, string $status): Activity
    {
        $activity = Activity::where('fiscal_year', 2027)->whereHas('directorate', fn ($q) => $q->where('code', $unit))->firstOrFail();
        $activity->forceFill(['status' => $status, 'approved_at' => null, 'approved_by' => null])->save();

        return $activity->fresh();
    }

    public function test_each_level_starts_from_its_preset(): void
    {
        $this->assertSame(ApprovalRules::MINISTER, ApprovalRules::preset(Institution::MINISTRY)->finalApprover);
        $this->assertSame(ApprovalRules::HEAD, ApprovalRules::preset(Institution::SUBORDINATE)->finalApprover);
        $this->assertTrue(ApprovalRules::preset(Institution::MINISTRY)->parentValidation);

        SystemSetting::set('institution_level', Institution::SUBORDINATE, 'string');
        $this->assertTrue(ApprovalRules::current()->headApproves(), 'with nothing stored the level decides');
    }

    public function test_a_stored_rule_overrides_the_preset_and_only_differences_are_stored(): void
    {
        SystemSetting::set('institution_level', Institution::SUBORDINATE, 'string');

        (new ApprovalRules(ApprovalRules::MINISTER, true))->save(Institution::SUBORDINATE);
        $this->assertFalse(ApprovalRules::current()->headApproves());
        $this->assertNotNull(SystemSetting::where('key', 'approval_rules')->first());

        (new ApprovalRules(ApprovalRules::HEAD, true))->save(Institution::SUBORDINATE); // back to the preset
        $this->assertNull(SystemSetting::where('key', 'approval_rules')->first());
        $this->assertTrue(ApprovalRules::current()->headApproves());
    }

    public function test_an_office_can_choose_to_send_its_plan_to_a_minister(): void
    {
        $this->office();
        (new ApprovalRules(ApprovalRules::MINISTER, true))->save(Institution::SUBORDINATE);
        $head = User::where('email', 'chefe.gdsc@goku.tl')->firstOrFail();
        $activity = $this->activityAt('UDIG', Activity::STATUS_VALIDATED);
        $workflow = app(ActivityWorkflow::class);

        $this->assertSame(Activity::STATUS_WAITING_APPROVAL, $workflow->next($head, $activity));
        $this->assertFalse($workflow->canApprove($head, $activity));
    }

    public function test_a_ministry_can_choose_head_approval(): void
    {
        $this->office();
        SystemSetting::set('institution_level', Institution::MINISTRY, 'string');
        (new ApprovalRules(ApprovalRules::HEAD, true))->save(Institution::MINISTRY);
        $head = User::where('email', 'chefe.gdsc@goku.tl')->firstOrFail();
        $activity = $this->activityAt('UDIG', Activity::STATUS_VALIDATED);

        $this->assertSame(Activity::STATUS_APPROVED, app(ActivityWorkflow::class)->next($head, $activity));
    }

    public function test_the_parent_review_step_can_be_skipped(): void
    {
        $this->office();
        $unitDirector = User::where('email', 'director.udig@goku.tl')->firstOrFail();
        $workflow = app(ActivityWorkflow::class);

        $activity = $this->activityAt('UDIG', Activity::STATUS_SUBMITTED);
        $this->assertSame(Activity::STATUS_REVIEWED, $workflow->next($unitDirector, $activity), 'by default the parent validates afterwards');

        (new ApprovalRules(ApprovalRules::HEAD, false))->save(Institution::SUBORDINATE);
        $this->assertSame(Activity::STATUS_VALIDATED, $workflow->next($unitDirector, $activity));
    }

    public function test_settings_apply_the_preset_when_the_level_changes_and_save_the_rules(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        Volt::test('pages.management.settings')
            ->assertSet('finalApprover', 'minister')
            ->set('institutionLevel', 'subordinate')
            ->assertSet('finalApprover', 'head')
            ->set('parentValidation', false)
            ->call('saveSettings')
            ->assertHasNoErrors();

        $this->assertSame(Institution::SUBORDINATE, Institution::level());
        $this->assertTrue(ApprovalRules::current()->headApproves());
        $this->assertFalse(ApprovalRules::current()->parentValidation);

        $this->assertTrue(
            AuditLog::where('action', 'systemsetting.created')->get()->contains(fn ($log) => ($log->new_values['key'] ?? null) === 'approval_rules'),
            'a change to the approval rules is in the audit trail'
        );
    }

    public function test_returning_to_the_preset_is_audited_too(): void
    {
        (new ApprovalRules(ApprovalRules::MINISTER, false))->save(Institution::MINISTRY);
        (new ApprovalRules(ApprovalRules::MINISTER, true))->save(Institution::MINISTRY);

        $this->assertTrue(AuditLog::where('action', 'systemsetting.deleted')->get()->contains(fn ($log) => ($log->old_values['key'] ?? null) === 'approval_rules'));
    }
}
