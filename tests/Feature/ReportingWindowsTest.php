<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityReport;
use App\Models\BudgetExecution;
use App\Models\Directorate;
use App\Models\ReportingWindow;
use App\Models\User;
use App\Services\ReportingWindows;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** A month or quarter with a window can be reported only inside it; no window means open; administrators always can. */
class ReportingWindowsTest extends TestCase
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
        session(['fiscal_year' => 2026]);

        $this->director = User::where('email', 'director.udig@gdsc.tl')->firstOrFail();
        $this->activity = Activity::where('fiscal_year', 2026)->where('status', 'Approved')->whereHas('lineItems.adjustedBudget')
            ->whereIn('directorate_id', Directorate::find($this->director->directorate_id)->getSelfAndDescendantIds())->firstOrFail();
        BudgetExecution::whereIn('line_item_id', $this->activity->lineItems()->pluck('id'))->where('month', 10)->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function window(string $type, int $period, string $from, string $to): void
    {
        ReportingWindow::create(['fiscal_year' => 2026, 'period_type' => $type, 'period' => $period, 'opens_on' => $from, 'closes_on' => $to]);
    }

    private function spending(User $user)
    {
        return Volt::actingAs($user)->test('pages.monitoring.execution-spending', ['activityId' => $this->activity->id, 'month' => 10]);
    }

    public function test_without_a_window_the_period_is_open(): void
    {
        $line = $this->activity->lineItems()->whereHas('adjustedBudget')->firstOrFail();
        $this->spending($this->director)->set("form.{$line->id}.committed", 10)->set("form.{$line->id}.paid", 5)->call('save');

        $this->assertSame(1, BudgetExecution::where('line_item_id', $line->id)->where('month', 10)->count());
    }

    public function test_a_closed_window_stops_reporting_but_an_administrator_still_can(): void
    {
        $this->window('month', 10, '2026-10-01', '2026-10-10'); // closed four days ago
        $line = $this->activity->lineItems()->whereHas('adjustedBudget')->firstOrFail();

        $this->spending($this->director)->set("form.{$line->id}.committed", 10)->set("form.{$line->id}.paid", 5)->call('save')->assertForbidden();
        $this->assertSame(0, BudgetExecution::where('line_item_id', $line->id)->where('month', 10)->count());

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->assertTrue(app(ReportingWindows::class)->allows($admin, 2026, 'month', 10));
    }

    public function test_inside_the_window_reporting_works_and_the_page_explains_when_closed(): void
    {
        $this->window('month', 10, '2026-10-01', '2026-10-31');
        $this->window('quarter', 4, '2026-11-01', '2026-11-15'); // not yet open

        $service = app(ReportingWindows::class);
        $this->assertTrue($service->allows($this->director, 2026, 'month', 10));
        $this->assertFalse($service->allows($this->director, 2026, 'quarter', 4));
        $this->assertStringContainsString('opens on 01/11/2026', $service->closedReason($this->director, 2026, 'quarter', 4));

        Volt::actingAs($this->director)->test('pages.monitoring.report-execution')->set('month', 10)->set('activityId', $this->activity->id)
            ->assertSee('Reporting for this period opens on 01/11/2026.');
    }

    public function test_the_narrative_follows_its_own_windows(): void
    {
        $this->window('month', 10, '2026-10-01', '2026-10-31');
        $this->window('quarter', 4, '2026-11-01', '2026-11-15');

        Volt::actingAs($this->director)->test('pages.monitoring.execution-narrative', ['activityId' => $this->activity->id, 'month' => 10])
            ->set('form.month.results', 'Done')->call('save', 'month')
            ->set('form.quarter.results', 'Too early')->call('save', 'quarter')->assertForbidden();

        $this->assertSame(1, ActivityReport::where('activity_id', $this->activity->id)->count());
    }

    public function test_only_an_administrator_sets_windows(): void
    {
        $this->actingAs($this->director)->get(route('management.reporting-windows'))->assertForbidden();

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin)->get(route('management.reporting-windows'))->assertOk()->assertSee('Reporting windows');

        Volt::actingAs($admin)->test('pages.management.reporting-windows')
            ->set('periodType', 'month')->set('period', 10)->set('opensOn', '2026-10-05')->set('closesOn', '2026-10-01')->call('save')->assertHasErrors('closesOn')
            ->set('closesOn', '2026-10-20')->call('save')->assertHasNoErrors();
        $this->assertSame(1, ReportingWindow::where('period', 10)->count());
    }
}
