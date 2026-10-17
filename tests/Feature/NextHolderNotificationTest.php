<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ApprovalDelegation;
use App\Models\ApproverAssignment;
use App\Models\Directorate;
use App\Models\User;
use App\Services\ActivityWorkflow;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** When an activity is submitted, those who hold the next step are told: the director, people named in a list, and an interim appointee. */
class NextHolderNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Activity $activity;

    private User $officer;

    private User $director;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        app()->setLocale('en');

        $this->officer = User::where('email', 'tecnico.udig@gdsc.tl')->firstOrFail();
        $this->director = User::where('email', 'director.udig@gdsc.tl')->firstOrFail();
        $this->activity = Activity::where('fiscal_year', 2027)->whereHas('directorate', fn ($q) => $q->where('code', 'UDIG'))->firstOrFail();
        $this->activity->forceFill(['status' => Activity::STATUS_DRAFT])->saveQuietly();
        $this->outsider = User::factory()->create(['directorate_id' => Directorate::where('id', '!=', $this->activity->directorate_id)->firstOrFail()->id]);
        $this->outsider->assignRole('Planning');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function submit(): void
    {
        app(ActivityWorkflow::class)->advance($this->officer, $this->activity->fresh());
    }

    public function test_the_director_who_certifies_is_told(): void
    {
        $this->submit();

        $this->assertSame(1, $this->director->notifications()->count());
    }

    public function test_an_interim_appointee_is_told_and_a_stranger_is_not(): void
    {
        ApprovalDelegation::create(['user_id' => $this->outsider->id, 'directorate_id' => $this->director->directorate_id, 'starts_on' => '2026-10-10', 'ends_on' => '2026-10-20', 'created_by' => $this->director->id]);
        $bystander = User::factory()->create(['directorate_id' => $this->outsider->directorate_id]);
        $bystander->assignRole('Planning');

        $this->submit();

        $this->assertSame(1, $this->outsider->notifications()->count(), 'the interim holds the next step');
        $this->assertSame(0, $bystander->notifications()->count());
    }

    public function test_a_person_named_in_the_certify_list_is_told(): void
    {
        ApproverAssignment::create(['directorate_id' => $this->activity->directorate_id, 'step' => 'certify', 'user_id' => $this->outsider->id, 'position' => 1]);

        $this->submit();

        $this->assertSame(1, $this->outsider->notifications()->count());
    }

    public function test_nobody_is_told_twice(): void
    {
        ApproverAssignment::create(['directorate_id' => $this->activity->directorate_id, 'step' => 'certify', 'user_id' => $this->director->id, 'position' => 1]);

        $this->submit();

        $this->assertSame(1, $this->director->notifications()->count());
    }
}
