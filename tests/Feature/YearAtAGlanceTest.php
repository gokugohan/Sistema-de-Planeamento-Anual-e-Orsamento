<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\BudgetExecution;
use App\Models\ReportingWindow;
use App\Models\User;
use App\Services\YearAtAGlance;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** The year in four plain sentences, the guide page, and the explanations of where the figures come from. */
#[Group('office')]
class YearAtAGlanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        app()->setLocale('en');
        session(['fiscal_year' => 2026, 'locale' => 'en']);
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

    private function glance(User $user, int $year = 2026): array
    {
        $this->actingAs($user);

        return app(YearAtAGlance::class)->for($user, $year);
    }

    private function phase(array $glance, string $key): array
    {
        return collect($glance['phases'])->firstWhere('key', $key);
    }

    public function test_it_has_the_four_phases_with_a_sentence_each(): void
    {
        $g = $this->glance($this->user('director.udig@gdsc.tl'));

        $this->assertSame(['planning', 'approval', 'finalize', 'reporting'], array_column($g['phases'], 'key'));
        foreach ($g['phases'] as $p) {
            $this->assertNotSame('', $p['text']);
        }
    }

    public function test_planning_says_when_it_closes_and_the_right_person_is_offered_to_add_an_activity(): void
    {
        ReportingWindow::create(['fiscal_year' => 2026, 'period_type' => 'plan', 'period' => 1, 'opens_on' => '2026-09-01', 'closes_on' => '2026-11-30']);

        $officer = $this->phase($this->glance($this->user('tecnico.udig@gdsc.tl')), 'planning');

        $this->assertStringContainsString('Open until 30/11/2026', $officer['text']);
        $this->assertSame('Add an activity', $officer['cta'][0]);

        ReportingWindow::query()->update(['closes_on' => '2026-09-30']);
        $closed = $this->phase($this->glance($this->user('tecnico.udig@gdsc.tl')), 'planning');
        $this->assertStringContainsString('Closed on 30/09/2026', $closed['text']);
        $this->assertNull($closed['cta'], 'nothing to add while the window is closed');
        $this->assertSame('done', $closed['state']);
    }

    public function test_approval_counts_what_waits_and_points_the_approver_to_it(): void
    {
        $activity = Activity::where('fiscal_year', 2026)->whereHas('directorate', fn ($q) => $q->where('code', 'UDIG'))->firstOrFail();
        $activity->forceFill(['status' => Activity::STATUS_SUBMITTED])->saveQuietly();

        $director = $this->phase($this->glance($this->user('director.udig@gdsc.tl')), 'approval');

        $this->assertStringContainsString('waiting for a decision', $director['text']);
        $this->assertSame(route('approvals.index'), $director['cta'][1]);
        $this->assertSame('now', $director['state']);
    }

    public function test_the_finalize_button_is_offered_only_to_the_head_of_a_directorate_that_is_ready(): void
    {
        $head = $this->phase($this->glance($this->user('chefe.gdsc@gdsc.tl')), 'finalize');
        $this->assertSame('Finalize the plan', $head['cta'][0]);
        $this->assertStringContainsString('Everything is decided', $head['text']);

        $unitDirector = $this->phase($this->glance($this->user('director.udig@gdsc.tl')), 'finalize');
        $this->assertNull($unitDirector['cta'], 'a unit director does not finalize');
    }

    public function test_reporting_names_last_month_and_only_those_who_report_get_the_button(): void
    {
        $director = $this->phase($this->glance($this->user('director.udig@gdsc.tl')), 'reporting');
        $this->assertStringContainsString('September', $director['text']);
        $this->assertSame(route('monitoring.report'), $director['cta'][1]);

        $officer = $this->phase($this->glance($this->user('tecnico.udig@gdsc.tl')), 'reporting');
        $this->assertNull($officer['cta'], 'the technical officer does not report execution');
    }

    public function test_a_past_year_has_finished_reporting(): void
    {
        $g = $this->glance($this->user('director.udig@gdsc.tl'), 2025);

        $this->assertSame('done', $this->phase($g, 'reporting')['state']);
    }

    public function test_the_dashboard_and_my_work_show_the_glance_and_the_dashboard_is_shorter(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $dashboard = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertSee('The year 2026 at a glance')->assertSee('How the year works')->getContent();

        $this->assertStringContainsString('The figures of each unit and each programme are on their own pages', $dashboard);
        $this->assertStringNotContainsString('Programs', explode('The figures of each unit', $dashboard)[1] ?? '', 'the programme table is no longer on the dashboard');
        $this->assertLessThan(strpos($dashboard, 'Approved plan'), strpos($dashboard, 'Needs your attention'), 'what needs you comes before the figures');

        $this->actingAs($this->user('director.udig@gdsc.tl'))->followingRedirects()->get(route('work.home'))->assertOk()->assertSee('The year 2026 at a glance')->assertSee('counts once someone else has verified it');
    }

    public function test_the_technical_officer_is_told_who_reports_and_where_to_follow_it(): void
    {
        $this->actingAs($this->user('tecnico.udig@gdsc.tl'))->followingRedirects()->get(route('work.home'))->assertOk()
            ->assertSee('The Director of the unit reports it');

        $this->actingAs($this->user('director.udig@gdsc.tl'))->followingRedirects()->get(route('work.home'))->assertOk()->assertDontSee('The Director of the unit reports it');
    }

    public function test_the_guide_opens_for_everyone_and_marks_the_persons_own_steps(): void
    {
        $officer = $this->actingAs($this->user('tecnico.udig@gdsc.tl'))->get(route('guide'))->assertOk()
            ->assertSee('How the year works')->assertSee('Where does &quot;Executed&quot; come from?', false)->assertSee('Report the executed amount')->getContent();
        $this->assertStringContainsString('Yours', $officer);

        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $html = $this->actingAs($viewer)->get(route('guide'))->assertOk()->assertSee('You read plans and reports')->getContent();
        $this->assertStringNotContainsString('>Yours<', $html, 'a viewer has no steps of their own');
    }

    public function test_the_activity_page_says_where_the_execution_figures_come_from(): void
    {
        $activity = Activity::where('fiscal_year', 2026)->where('version', '>', 0)->whereHas('directorate', fn ($q) => $q->where('code', 'UDIG'))->firstOrFail();

        $this->actingAs($this->user('director.udig@gdsc.tl'))->get(route('activities.show', $activity))->assertOk()
            ->assertSee('Where do these figures come from?')->assertSee(route('monitoring.report', ['activity' => $activity->id]), false);
    }

    public function test_report_execution_opens_on_the_activity_asked_for_and_lists_those_still_to_report(): void
    {
        $director = $this->user('director.udig@gdsc.tl');
        $activity = Activity::where('fiscal_year', 2026)->where('version', '>', 0)->whereHas('directorate', fn ($q) => $q->where('code', 'UDIG'))->firstOrFail();
        BudgetExecution::whereIn('line_item_id', $activity->lineItems()->select('id'))->where('month', 10)->delete();
        $this->actingAs($director);

        Volt::test('pages.monitoring.report-execution')->assertSee('To report')->assertSee('indicators for the quarter it belongs to (October – December)');

        $this->get(route('monitoring.report', ['activity' => $activity->id]))->assertOk()->assertSee('What the activity achieved in Q4 (October to December)');
    }
}
