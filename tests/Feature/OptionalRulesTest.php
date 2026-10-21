<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\ReportingWindow;
use App\Models\SystemSetting;
use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Services\ActivityWorkflow;
use App\Support\PlanOptions;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** The optional rules are off by default, change nothing until switched on, and do what they say when on. */
class OptionalRulesTest extends TestCase
{
    use RefreshDatabase;

    private Activity $activity;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        app()->setLocale('en');
        $this->activity = Activity::where('fiscal_year', 2027)->whereHas('directorate', fn ($q) => $q->where('code', 'UDIG'))->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('Admin');

        return $u;
    }

    public function test_every_option_is_off_by_default_and_only_differences_are_stored(): void
    {
        $o = PlanOptions::current();
        $this->assertFalse($o->obligations || $o->financeInstitutionWide || $o->externalReference || $o->correctiveActions || $o->baselines || $o->structureAtApproval);
        $this->assertSame([], $o->requiredFields);

        (new PlanOptions(obligations: true))->save();
        $this->assertTrue(PlanOptions::current()->obligations);
        (new PlanOptions)->save();
        $this->assertNull(SystemSetting::where('key', 'plan_options')->first(), 'all off: nothing stored');
    }

    public function test_only_an_administrator_opens_and_saves_the_page(): void
    {
        $this->actingAs(User::where('email', 'director.udig@gdsc.tl')->firstOrFail());
        $this->get(route('management.optional-rules'))->assertForbidden();

        Volt::actingAs($this->admin())->test('pages.management.optional-rules')
            ->set('correctiveActions', true)->set('requiredFields', ['objective'])->set('guidance', 'Submit by 30 June.')->call('save')->assertHasNoErrors();

        $this->assertTrue(PlanOptions::current()->correctiveActions);
        $this->assertSame(['objective'], PlanOptions::current()->requiredFields);
        $this->assertSame('Submit by 30 June.', PlanOptions::guidance(2026));
    }

    public function test_extra_mandatory_fields_hold_back_submission_only_when_chosen(): void
    {
        $this->activity->forceFill(['objective' => null, 'status' => Activity::STATUS_DRAFT])->save();
        $workflow = app(ActivityWorkflow::class);
        $this->assertNotContains('Fill in: Objective of the activity.', $workflow->problems($this->activity->fresh()));

        (new PlanOptions(requiredFields: ['objective']))->save();

        $this->assertContains('Fill in: Objective of the activity.', $workflow->problems($this->activity->fresh()));
    }

    public function test_finance_officers_work_on_every_unit_only_when_the_rule_is_on(): void
    {
        $other = Directorate::create(['code' => 'XFN', 'name' => 'Another directorate', 'type' => 'National Directorate', 'active' => true]);
        $finance = User::factory()->create(['directorate_id' => $other->id]);
        $finance->assignRole('Finance');
        $policy = app(ActivityPolicy::class);

        $this->assertFalse($policy->inScope($finance, $this->activity->directorate_id));
        (new PlanOptions(financeInstitutionWide: true))->save();
        $this->assertTrue($policy->inScope($finance, $this->activity->directorate_id));
    }

    public function test_a_revision_window_holds_back_revisions_but_not_new_plans(): void
    {
        $officer = User::where('email', 'tecnico.udig@gdsc.tl')->firstOrFail();
        $this->activity->forceFill(['status' => Activity::STATUS_DRAFT, 'version' => 1])->save();
        $workflow = app(ActivityWorkflow::class);
        $this->assertSame(Activity::STATUS_SUBMITTED, $workflow->next($officer, $this->activity->fresh()), 'no revision window: open');

        ReportingWindow::create(['fiscal_year' => 2027, 'period_type' => 'revision', 'period' => 1, 'opens_on' => '2026-08-01', 'closes_on' => '2026-08-31']);

        $this->assertNull($workflow->next($officer, $this->activity->fresh()));
        $this->assertSame(Activity::STATUS_SUBMITTED, $workflow->next($this->admin(), $this->activity->fresh()), 'an administrator still can');
    }

    public function test_the_guidance_of_the_year_is_shown_where_activities_are_prepared(): void
    {
        PlanOptions::setGuidance(2027, 'Use the codes of the 2027 budget book.');
        $this->activity->forceFill(['status' => Activity::STATUS_DRAFT])->save();

        $this->actingAs(User::where('email', 'tecnico.udig@gdsc.tl')->firstOrFail());
        Volt::test('pages.activities.activity-form', ['activity' => $this->activity->fresh()])->assertSee('Use the codes of the 2027 budget book.');
    }
}
