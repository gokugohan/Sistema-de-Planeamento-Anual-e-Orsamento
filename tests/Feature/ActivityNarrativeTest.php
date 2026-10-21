<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityReport;
use App\Models\Directorate;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** The words that go with the numbers: monthly and quarterly narrative, written by the unit and verified by someone else. */
#[Group('office')]
class ActivityNarrativeTest extends TestCase
{
    use RefreshDatabase;

    private Activity $activity;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        app()->setLocale('en');

        $this->director = User::where('email', 'director.udig@gdsc.tl')->firstOrFail();
        $this->activity = Activity::where('fiscal_year', 2026)->where('status', 'Approved')
            ->whereIn('directorate_id', Directorate::find($this->director->directorate_id)->getSelfAndDescendantIds())->firstOrFail();
        ActivityReport::query()->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function panel(User $user)
    {
        return Volt::actingAs($user)->test('pages.monitoring.execution-narrative', ['activityId' => $this->activity->id, 'month' => 10]);
    }

    public function test_the_unit_writes_both_reports_and_submits_them(): void
    {
        $this->panel($this->director)
            ->set('form.month.results', 'Opened the tender')->set('form.month.constraints', 'Late funds')->call('save', 'month')
            ->set('form.quarter.reasons_not_met', 'Rains')->set('form.quarter.corrective_measures', 'Extra crew')->set('form.quarter.rating', 'Partly')->call('save', 'quarter')
            ->call('submit');

        $month = ActivityReport::where(['activity_id' => $this->activity->id, 'period_type' => 'month', 'period' => 10])->firstOrFail();
        $quarter = ActivityReport::where(['activity_id' => $this->activity->id, 'period_type' => 'quarter', 'period' => 4])->firstOrFail();
        $this->assertSame('Submitted', $month->status);
        $this->assertSame('Submitted', $quarter->status);
        $this->assertSame('Partly', $quarter->rating);
        $this->assertSame($this->director->id, $month->submitted_by);
    }

    public function test_a_submitted_report_is_not_overwritten_and_the_author_cannot_verify_it(): void
    {
        $panel = $this->panel($this->director)->set('form.month.results', 'Done')->call('save', 'month')->call('submit');
        $panel->set('form.month.results', 'Changed')->call('save', 'month');
        $this->assertSame('Done', ActivityReport::where('period_type', 'month')->value('results'));

        $panel->call('decide', 'month', true);
        $this->assertSame('Submitted', ActivityReport::where('period_type', 'month')->value('status'));
    }

    public function test_someone_else_verifies_or_returns_it(): void
    {
        $this->panel($this->director)->set('form.month.results', 'Done')->call('save', 'month')->call('submit');
        $verifier = User::where('email', 'chefe.gdsc@gdsc.tl')->firstOrFail();

        $this->panel($verifier)->call('decide', 'month', true);
        $report = ActivityReport::where('period_type', 'month')->firstOrFail();
        $this->assertSame('Verified', $report->status);
        $this->assertSame($verifier->id, $report->verified_by);
    }

    public function test_a_rating_must_be_one_of_the_list_and_outsiders_are_refused(): void
    {
        $this->panel($this->director)->set('form.quarter.rating', 'Excellent')->call('save', 'quarter')->assertHasErrors('form.quarter.rating');

        $outsider = User::factory()->create();
        $outsider->assignRole('Director');
        $this->panel($outsider)->call('save', 'month')->assertForbidden();
    }
}
