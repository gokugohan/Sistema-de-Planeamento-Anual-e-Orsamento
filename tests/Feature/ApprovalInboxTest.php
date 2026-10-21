<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\BudgetExecution;
use App\Models\Directorate;
use App\Models\IndicatorProgress;
use App\Models\User;
use App\Services\ApprovalInbox;
use App\Services\PlanningWindow;
use App\View\Components\NextActions;
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
        $planner = $this->user('tecnico.udig@gdsc.tl');
        $unit = Directorate::find($planner->directorate_id)->getSelfAndDescendantIds();
        DB::table('activities')->where('fiscal_year', 2027)->whereIn('directorate_id', $unit)->limit(1)->update(['status' => Activity::STATUS_REJECTED]);

        $items = collect(app(ApprovalInbox::class)->items($planner, 2027))->where('group', 'mine');

        $this->assertTrue($items->contains(fn ($i) => str_contains($i['text'], 'returned for correction')));
    }

    public function test_the_page_holds_only_decisions_and_checks_and_each_line_opens_its_own_queue(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $html = $this->actingAs($admin)->get(route('approvals.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Reporting due', $html, 'reporting due is in My work');
        $this->assertStringNotContainsString('Your own work', $html);
        $this->assertStringContainsString(route('planning.paa-approval', ['queue' => 'review'], false), $html);
        $this->assertStringContainsString(route('planning.paa-approval', ['queue' => 'approval'], false), $html);
    }

    public function test_directorates_ready_to_finalize_are_one_line_not_one_per_directorate(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        // every activity of 2026 is approved in the sample; a second directorate with an approved activity is ready too
        $other = Directorate::create(['code' => 'XSECOND', 'name' => 'Second directorate', 'type' => 'National Directorate', 'active' => true]);
        $source = Activity::where('fiscal_year', 2026)->where('status', Activity::STATUS_APPROVED)->firstOrFail();
        Activity::create([
            'fiscal_year' => 2026, 'program_id' => $source->program_id, 'directorate_id' => $other->id, 'code' => 'XSECOND-26-001', 'description' => 'Approved elsewhere',
            'quarter' => 1, 'priority' => 'Alta', 'planned_start' => '2026-01-01', 'planned_end' => '2026-12-31', 'status' => Activity::STATUS_APPROVED, 'version' => 1,
        ]);
        PlanningWindow::forgetTree();

        $items = collect(app(ApprovalInbox::class)->items($admin, 2026))->where('group', 'plan')->values();

        $this->assertCount(1, $items, 'one line for all of them');
        $this->assertSame(2, $items[0]['count']);
        $this->assertStringContainsString('XSECOND', $items[0]['text']);
    }

    public function test_the_activities_to_decide_page_opens_on_the_queue_asked_for(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $html = $this->get(route('planning.paa-approval', ['queue' => 'approval']))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/aria-selected="true"[^>]*>\s*Ready for approval/', $html);
    }

    public function test_the_page_opens_for_those_with_work_to_do_and_not_for_viewers(): void
    {
        $this->actingAs($this->user('chefe.gdsc@gdsc.tl'))->get(route('approvals.index'))
            ->assertOk()->assertSee('Waiting for you')->assertSee('To decide')->assertDontSee('Places')->assertDontSee('Budget steps');

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
            collect((new NextActions)->items)->pluck('text')->all(),
        );
    }
}
