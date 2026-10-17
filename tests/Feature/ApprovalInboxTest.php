<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\BudgetExecution;
use App\Models\IndicatorProgress;
use App\Models\User;
use App\Services\ApprovalInbox;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The Approvals page: everything waiting for one person, in one place. */
class ApprovalInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        app()->setLocale('en');
        session(['fiscal_year' => 2027, 'locale' => 'en']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function counts(User $user, int $year = 2027): array
    {
        return collect(app(ApprovalInbox::class)->items($user, $year))->groupBy('group')->map(fn ($g) => $g->sum('count'))->all();
    }

    public function test_the_head_of_the_office_sees_what_waits_in_their_units(): void
    {
        $g = $this->counts($this->user('chefe.gdsc@gdsc.tl'));

        $this->assertGreaterThan(0, $g['decide'], 'the 2027 sample has activities at every step of the workflow');
    }

    public function test_execution_figures_to_verify_never_include_your_own_reports(): void
    {
        $head = $this->user('chefe.gdsc@gdsc.tl');
        $before = $this->counts($head, 2026)['verify'] ?? 0;
        $this->assertGreaterThan(0, $before, 'the running quarter has figures waiting for verification');

        // everything waiting was reported by the head: nothing is left for them to verify
        BudgetExecution::where('status', BudgetExecution::SUBMITTED)->update(['submitted_by' => $head->id]);
        IndicatorProgress::where('status', 'Submitted')->update(['submitted_by' => $head->id]);

        $this->assertSame(0, $this->counts($head, 2026)['verify'] ?? 0);
    }

    public function test_the_plan_is_offered_for_finalizing_only_when_nothing_waits_and_only_to_the_final_approver(): void
    {
        $head = $this->user('chefe.gdsc@gdsc.tl');
        $unitDirector = $this->user('director.udig@gdsc.tl');

        $this->assertArrayNotHasKey('plan', $this->counts($head), 'activities are still waiting for a decision');

        DB::table('activities')->where('fiscal_year', 2027)->update(['status' => Activity::STATUS_APPROVED]);

        $this->assertArrayHasKey('plan', $this->counts($head));
        $this->assertArrayNotHasKey('plan', $this->counts($unitDirector), 'a unit director does not give the final approval');
    }

    public function test_a_planner_sees_their_drafts_and_what_was_returned(): void
    {
        $planner = $this->user('planner.udig@gdsc.tl');
        $unit = \App\Models\Directorate::find($planner->directorate_id)->getSelfAndDescendantIds();
        DB::table('activities')->where('fiscal_year', 2027)->whereIn('directorate_id', $unit)->limit(1)->update(['status' => Activity::STATUS_REJECTED]);

        $items = collect(app(ApprovalInbox::class)->items($planner, 2027))->where('group', 'mine');

        $this->assertTrue($items->contains(fn ($i) => str_contains($i['text'], 'returned for correction')));
    }

    public function test_the_page_opens_for_those_with_work_to_do_and_not_for_viewers(): void
    {
        $this->actingAs($this->user('chefe.gdsc@gdsc.tl'))->get(route('approvals.index'))
            ->assertOk()->assertSee('Approvals')->assertSee('Activities to decide')->assertSee('Places');

        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $this->actingAs($viewer)->get(route('approvals.index'))->assertForbidden();
    }

    public function test_the_dashboards_attention_list_is_the_same_inbox(): void
    {
        $head = $this->user('chefe.gdsc@gdsc.tl');
        $this->actingAs($head);

        $this->assertSame(
            collect(app(ApprovalInbox::class)->items($head))->pluck('text')->all(),
            collect((new \App\View\Components\NextActions)->items)->pluck('text')->all(),
        );
    }
}
