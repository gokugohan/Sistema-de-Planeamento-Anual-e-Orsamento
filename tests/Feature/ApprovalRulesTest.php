<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\ActivityWorkflow;
use App\Support\ApprovalRules;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** The approval chain is the same everywhere: the head of the directorate approves; the institution chooses only the two options. */
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

    public function test_the_preset_is_the_same_for_everyone(): void
    {
        $preset = ApprovalRules::preset();
        $this->assertTrue($preset->parentValidation);
        $this->assertFalse($preset->budgetConfirmation);
        $this->assertTrue(ApprovalRules::current()->equals($preset), 'with nothing stored the preset decides');
    }

    public function test_a_stored_rule_overrides_the_preset_and_only_differences_are_stored(): void
    {
        (new ApprovalRules(false, true))->save();
        $this->assertFalse(ApprovalRules::current()->parentValidation);
        $this->assertTrue(ApprovalRules::current()->budgetConfirmation);
        $this->assertNotNull(SystemSetting::where('key', 'approval_rules')->first());

        (new ApprovalRules(true, false))->save(); // back to the preset
        $this->assertNull(SystemSetting::where('key', 'approval_rules')->first());
        $this->assertTrue(ApprovalRules::current()->parentValidation);
    }

    public function test_the_head_of_the_directorate_approves_and_nobody_sends_it_up_to_a_minister(): void
    {
        $this->office();
        app()->setLocale('en');
        $head = User::where('email', 'chefe.gdsc@gdsc.tl')->firstOrFail();
        $activity = $this->activityAt('UDIG', Activity::STATUS_VALIDATED);
        $workflow = app(ActivityWorkflow::class);

        $this->assertSame(Activity::STATUS_APPROVED, $workflow->next($head, $activity));
        $this->assertTrue($workflow->canApprove($head, $activity));
        $this->assertSame('The head of the directorate to approve it', $workflow->awaiting($activity));
    }

    public function test_a_unit_director_below_the_head_cannot_give_the_final_approval(): void
    {
        $this->office();
        $unitDirector = User::where('email', 'director.udig@gdsc.tl')->firstOrFail();
        $activity = $this->activityAt('UDIG', Activity::STATUS_VALIDATED);

        $this->assertNull(app(ActivityWorkflow::class)->next($unitDirector, $activity));
        $this->assertFalse(app(ActivityWorkflow::class)->canApprove($unitDirector, $activity));
    }

    public function test_the_minister_sees_the_plans_but_does_not_approve_them(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $minister = User::factory()->create();
        $minister->assignRole('Minister');

        $this->assertTrue($minister->can('view_all_paa'));
        $this->assertFalse($minister->can('approve_paa'));
    }

    public function test_the_parent_review_step_can_be_skipped(): void
    {
        $this->office();
        $unitDirector = User::where('email', 'director.udig@gdsc.tl')->firstOrFail();
        $workflow = app(ActivityWorkflow::class);

        $activity = $this->activityAt('UDIG', Activity::STATUS_SUBMITTED);
        $this->assertSame(Activity::STATUS_REVIEWED, $workflow->next($unitDirector, $activity), 'by default the parent validates afterwards');

        (new ApprovalRules(false))->save();
        $this->assertSame(Activity::STATUS_VALIDATED, $workflow->next($unitDirector, $activity));
    }

    public function test_settings_save_the_two_options_and_show_no_choice_of_final_approver(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        app()->setLocale('en');
        Volt::test('pages.management.settings')
            ->assertSee('The final approval is given by the head of the directorate')
            ->set('parentValidation', false)
            ->call('saveSettings')
            ->assertHasNoErrors();

        $this->assertFalse(ApprovalRules::current()->parentValidation);
        $this->assertTrue(
            AuditLog::where('action', 'systemsetting.created')->get()->contains(fn ($log) => ($log->new_values['key'] ?? null) === 'approval_rules'),
            'a change to the approval rules is in the audit trail'
        );
    }

    public function test_returning_to_the_preset_is_audited_too(): void
    {
        (new ApprovalRules(false, false))->save();
        (new ApprovalRules(true, false))->save();

        $this->assertTrue(AuditLog::where('action', 'systemsetting.deleted')->get()->contains(fn ($log) => ($log->old_values['key'] ?? null) === 'approval_rules'));
    }
}
