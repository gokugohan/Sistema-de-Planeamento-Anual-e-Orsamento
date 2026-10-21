<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityReport;
use App\Models\Directorate;
use App\Models\IndicatorProgress;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** Verify progress: a reviewer sees what was reported, the indicator and what the unit wrote before deciding; a return needs a reason. */
#[Group('office')]
class VerifyProgressDetailsTest extends TestCase
{
    use RefreshDatabase;

    private IndicatorProgress $progress;

    private User $reporter;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        app()->setLocale('en');
        session(['fiscal_year' => 2026]);

        $this->reporter = User::where('email', 'director.udig@gdsc.tl')->firstOrFail();
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');

        $activity = Activity::where('fiscal_year', 2026)->where('version', '>', 0)->whereHas('directorate', fn ($q) => $q->where('code', 'UDIG'))->firstOrFail();
        $indicator = $activity->indicators()->firstOrFail();
        $indicator->forceFill(['means_of_verification' => 'Signed attendance sheets', 'baseline' => '10 users', 'annual_target' => 200, 'measure' => 'cumulative'])->save();
        IndicatorProgress::where('indicator_id', $indicator->id)->delete();
        IndicatorProgress::create(['indicator_id' => $indicator->id, 'quarter' => 3, 'achieved_value' => 60, 'status' => 'Verified', 'submitted_by' => $this->reporter->id, 'verified_by' => $this->admin->id]);
        $this->progress = IndicatorProgress::create(['indicator_id' => $indicator->id, 'quarter' => 4, 'achieved_value' => 45, 'status' => 'Submitted', 'submitted_by' => $this->reporter->id]);
        ActivityReport::where('activity_id', $activity->id)->where('period_type', 'quarter')->delete();
        ActivityReport::create(['activity_id' => $activity->id, 'period_type' => 'quarter', 'period' => 4, 'results' => 'Three trainings held', 'constraints' => 'Late equipment', 'rating' => 'Partly', 'status' => 'Submitted']);
        // only this figure waits, so the page opens it
        IndicatorProgress::where('status', 'Submitted')->where('id', '!=', $this->progress->id)->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function page(?User $user = null)
    {
        return Volt::actingAs($user ?? $this->admin)->test('pages.monitoring.indicator-approval');
    }

    public function test_the_details_show_who_reported_what_how_it_is_checked_and_what_the_unit_wrote(): void
    {
        $page = $this->page();

        $page->assertSee('Reported by '.$this->reporter->name)
            ->assertSee('Signed attendance sheets')->assertSee('10 users')
            ->assertSee('Q3: 60.00')                 // verified in another quarter
            ->assertDontSee('Q4: 45.00')             // the quarter being verified is not one of the others
            ->assertSee('105.00')                    // 60 + 45 after verifying this one
            ->assertSee('(53%)')                     // of the 200 target
            ->assertSee('Three trainings held')->assertSee('Late equipment')->assertSee('Partly');
    }

    public function test_a_figure_above_the_target_is_flagged(): void
    {
        $this->progress->update(['achieved_value' => 400]);

        $this->page()->assertSee('above the annual target');
    }

    public function test_a_figure_is_verified_by_someone_else_and_not_by_its_reporter(): void
    {
        $this->page($this->admin)->call('approve', $this->progress->id);
        $this->assertSame('Verified', $this->progress->fresh()->status);
        $this->assertSame($this->admin->id, $this->progress->fresh()->verified_by);
    }

    public function test_returning_needs_a_reason_and_the_unit_can_read_it(): void
    {
        $page = $this->page()->set('returnId', $this->progress->id)->set('reason', ' ')->call('returnProgress');
        $this->assertSame('Submitted', $this->progress->fresh()->status, 'no reason, no change');

        $page->set('reason', 'The attendance sheets show only 30 people')->call('returnProgress');

        $fresh = $this->progress->fresh();
        $this->assertSame('Rejected', $fresh->status);
        $this->assertSame('The attendance sheets show only 30 people', $fresh->rejection_notes);
    }

    public function test_a_directors_own_report_cannot_be_verified_by_them(): void
    {
        $self = User::factory()->create(['directorate_id' => $this->reporter->directorate_id]);
        $self->assignRole('Admin');
        $this->progress->update(['submitted_by' => $self->id]);

        $this->page($self)->call('approve', $this->progress->id);

        $this->assertSame('Submitted', $this->progress->fresh()->status);
    }

    public function test_a_user_outside_the_unit_cannot_decide_it(): void
    {
        $other = Directorate::create(['code' => 'XOTHER', 'name' => 'Other', 'type' => 'National Directorate', 'active' => true]);
        $outsider = User::factory()->create(['directorate_id' => $other->id]);
        $outsider->assignRole('Planning');
        $outsider->givePermissionTo('approve_progress_report');

        $this->page($outsider)->call('approve', $this->progress->id)->assertForbidden();
        $this->assertSame('Submitted', $this->progress->fresh()->status);
    }
}
