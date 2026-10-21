<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\User;
use App\Support\ApprovalRules;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** The Review button on the Plan Approval page obeys the same rules as the rest of the approval chain. */
#[Group('office')]
class ReviewActionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function submittedAt(string $unit): Activity
    {
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        $activity = Activity::where('fiscal_year', 2027)->whereHas('directorate', fn ($q) => $q->where('code', $unit))->firstOrFail();
        $activity->forceFill(['status' => Activity::STATUS_SUBMITTED, 'reviewed_at' => null, 'reviewed_by' => null, 'approved_at' => null, 'approved_by' => null])->save();

        return $activity->fresh();
    }

    private function review(User $user, Activity $activity)
    {
        $this->actingAs($user)->withSession(['locale' => 'en']);
        app()->setLocale('en');

        return Volt::test('pages.planning.paa-approval')->call('review', $activity->id);
    }

    public function test_a_director_of_another_directorate_cannot_review_an_activity_outside_their_scope(): void
    {
        $activity = $this->submittedAt('UDIG');
        $other = Directorate::create(['name' => 'Another Directorate', 'code' => 'XTST', 'type' => 'National Directorate']);
        $stranger = User::factory()->create(['directorate_id' => $other->id]);
        $stranger->assignRole('Director');

        $this->review($stranger, $activity);

        $this->assertSame(Activity::STATUS_SUBMITTED, $activity->fresh()->status);
        $this->assertNull($activity->fresh()->reviewed_by);
    }

    public function test_the_certifier_of_the_unit_can_review_it(): void
    {
        $activity = $this->submittedAt('UDIG');
        $director = User::where('email', 'director.udig@gdsc.tl')->firstOrFail();

        $this->review($director, $activity);

        $fresh = $activity->fresh();
        $this->assertContains($fresh->status, [Activity::STATUS_REVIEWED, Activity::STATUS_VALIDATED]);
        $this->assertSame($director->id, $fresh->reviewed_by);
    }

    public function test_review_waits_for_the_budget_confirmation_where_the_institution_asks_for_it(): void
    {
        (new ApprovalRules(true, true))->save();
        $activity = $this->submittedAt('UDIG');
        $director = User::where('email', 'director.udig@gdsc.tl')->firstOrFail();

        $this->review($director, $activity);

        $this->assertSame(Activity::STATUS_SUBMITTED, $activity->fresh()->status);
    }

    public function test_review_follows_the_parent_validation_setting(): void
    {
        (new ApprovalRules(false, false))->save();
        $activity = $this->submittedAt('UDIG');
        $director = User::where('email', 'director.udig@gdsc.tl')->firstOrFail();

        $this->review($director, $activity);

        $this->assertSame(Activity::STATUS_VALIDATED, $activity->fresh()->status);
    }
}
