<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\ActivityWorkflow;
use App\Support\ApprovalRules;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The institution may keep the person who prepared an activity from also certifying or approving it. */
class SegregationOfDutiesTest extends TestCase
{
    use RefreshDatabase;

    private Activity $activity;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        app()->setLocale('en');
        $this->director = User::where('email', 'director.udig@gdsc.tl')->firstOrFail();
        $this->activity = Activity::where('fiscal_year', 2027)->whereHas('directorate', fn ($q) => $q->where('code', 'UDIG'))->firstOrFail();
        $this->activity->forceFill(['status' => Activity::STATUS_DRAFT, 'submitted_by' => null, 'approved_at' => null, 'approved_by' => null])->save();
    }

    private function mode(string $mode): void
    {
        (new ApprovalRules(true, false, $mode))->save();
    }

    /** The director prepares (submits) the activity, so they are its preparer. */
    private function submitted(): Activity
    {
        app(ActivityWorkflow::class)->advance($this->director, $this->activity->fresh());

        return $this->activity->fresh();
    }

    public function test_submitting_records_who_prepared_the_activity(): void
    {
        $this->assertSame($this->director->id, $this->submitted()->submitted_by);
    }

    public function test_by_default_the_preparer_may_also_certify(): void
    {
        $activity = $this->submitted();

        $this->assertNotNull(app(ActivityWorkflow::class)->next($this->director, $activity));
    }

    public function test_in_block_mode_the_preparer_cannot_certify_but_someone_else_can(): void
    {
        $this->mode('block');
        $activity = $this->submitted();
        $workflow = app(ActivityWorkflow::class);

        $this->assertNull($workflow->next($this->director, $activity));
        $this->expectException(DomainException::class);
        try {
            $workflow->advance($this->director, $activity);
        } finally {
            $this->assertSame(Activity::STATUS_SUBMITTED, $activity->fresh()->status);
        }
    }

    public function test_in_block_mode_another_person_certifies_normally(): void
    {
        $this->mode('block');
        $activity = $this->submitted();
        $other = User::where('email', 'chefe.gdsc@gdsc.tl')->firstOrFail();

        $this->assertNotNull(app(ActivityWorkflow::class)->next($other, $activity));
    }

    public function test_in_flag_mode_the_preparer_may_certify_and_the_record_says_so(): void
    {
        $this->mode('flag');
        $activity = $this->submitted();

        app(ActivityWorkflow::class)->advance($this->director, $activity);

        $this->assertNotSame(Activity::STATUS_SUBMITTED, $activity->fresh()->status);
        $this->assertTrue(AuditLog::where('auditable_id', $activity->id)->where('metadata', 'like', '%segregation%')->exists());
    }

    public function test_an_administrator_overrides_in_block_mode_and_it_is_marked(): void
    {
        $this->mode('block');
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        app(ActivityWorkflow::class)->advance($admin, $this->activity->fresh()); // the administrator prepares it
        $activity = $this->activity->fresh();
        $this->assertSame($admin->id, $activity->submitted_by);

        app(ActivityWorkflow::class)->advance($admin, $activity);

        $this->assertNotSame(Activity::STATUS_SUBMITTED, $activity->fresh()->status);
        $this->assertTrue(AuditLog::where('auditable_id', $activity->id)->where('metadata', 'like', '%segregation%')->exists());
    }

    public function test_activities_without_a_recorded_preparer_are_not_affected(): void
    {
        $this->mode('block');
        $activity = $this->submitted();
        $activity->forceFill(['submitted_by' => null])->save();

        $this->assertNotNull(app(ActivityWorkflow::class)->next($this->director, $activity->fresh()));
    }

    public function test_the_rule_is_stored_only_when_it_differs_from_the_preset(): void
    {
        $this->mode('block');
        $this->assertSame('block', ApprovalRules::current()->segregation);

        $this->mode('off');
        $this->assertSame('off', ApprovalRules::current()->segregation);
        $this->assertNull(\App\Models\SystemSetting::where('key', 'approval_rules')->first());
    }
}
