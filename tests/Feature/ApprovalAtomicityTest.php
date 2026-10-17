<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityVersion;
use App\Models\User;
use App\Services\ActivityVersions;
use App\Services\ActivityWorkflow;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Approving an activity and writing its version is one piece of work: both happen or neither does. */
class ApprovalAtomicityTest extends TestCase
{
    use RefreshDatabase;

    private function validated(): array
    {
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        app()->setLocale('en');
        $activity = Activity::where('fiscal_year', 2027)->whereHas('directorate', fn ($q) => $q->where('code', 'UDIG'))->firstOrFail();
        $activity->forceFill(['status' => Activity::STATUS_VALIDATED, 'approved_at' => null, 'approved_by' => null])->save();
        ActivityVersion::where('activity_id', $activity->id)->delete();

        return [$activity->fresh(), User::where('email', 'chefe.gdsc@gdsc.tl')->firstOrFail()];
    }

    public function test_a_failing_version_leaves_the_activity_unapproved_when_approving(): void
    {
        [$activity, $head] = $this->validated();
        $this->mock(ActivityVersions::class)->shouldReceive('approved')->andThrow(new RuntimeException('version failed'));

        try {
            app(ActivityWorkflow::class)->approve($head, $activity);
            $this->fail('the failure should reach the caller');
        } catch (RuntimeException) {
        }

        $fresh = Activity::find($activity->id);
        $this->assertSame(Activity::STATUS_VALIDATED, $fresh->status);
        $this->assertNull($fresh->approved_by);
        $this->assertSame(0, ActivityVersion::where('activity_id', $activity->id)->count());
    }

    public function test_a_failing_version_leaves_the_activity_unapproved_when_advancing(): void
    {
        [$activity, $head] = $this->validated();
        $this->mock(ActivityVersions::class)->shouldReceive('approved')->andThrow(new RuntimeException('version failed'));

        try {
            app(ActivityWorkflow::class)->advance($head, $activity);
            $this->fail('the failure should reach the caller');
        } catch (RuntimeException) {
        }

        $this->assertSame(Activity::STATUS_VALIDATED, Activity::find($activity->id)->status);
        $this->assertSame(0, ActivityVersion::where('activity_id', $activity->id)->count());
    }

    public function test_a_second_approver_with_a_stale_copy_does_not_approve_again(): void
    {
        [$activity, $head] = $this->validated();
        $stale = Activity::find($activity->id); // the second approver opened the page before the first one acted

        app(ActivityWorkflow::class)->approve($head, $activity);
        $this->assertSame(1, ActivityVersion::where('activity_id', $activity->id)->count());

        try {
            app(ActivityWorkflow::class)->approve($head, $stale);
            $this->fail('the second approval must be refused');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(1, ActivityVersion::where('activity_id', $activity->id)->count());
        $this->assertSame(Activity::STATUS_APPROVED, Activity::find($activity->id)->status);
    }
}
