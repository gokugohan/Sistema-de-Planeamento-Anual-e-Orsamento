<?php

namespace Tests\Feature;

use App\Models\Directorate;
use App\Models\ReportingWindow;
use App\Models\User;
use App\Services\ReportingWindows;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Report execution shows the reporting calendar of the year: each month and quarter with its dates and where it stands for the unit. */
class ReportingCalendarTest extends TestCase
{
    use RefreshDatabase;

    private Directorate $unit;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->unit = Directorate::create(['code' => 'UNI', 'name' => 'A Unit', 'type' => 'National Directorate', 'active' => true]);
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

    public function test_every_month_has_a_state_from_the_dates_of_the_calendar(): void
    {
        $this->window('month', 7, '2026-07-01', '2026-07-31');   // closed
        $this->window('month', 10, '2026-10-01', '2026-10-31');  // open, 17 days left
        $this->window('month', 8, '2026-11-01', '2026-11-30');   // not open yet
        $this->window('quarter', 3, '2026-10-01', '2026-10-16'); // closes in 2 days

        $cal = app(ReportingWindows::class)->calendar(2026, $this->unit->id, 10);

        $this->assertSame('free', $cal['months'][1]['state'], 'no dates: open');
        $this->assertSame('closed', $cal['months'][7]['state']);
        $this->assertSame('before', $cal['months'][8]['state']);
        $this->assertSame('open', $cal['months'][10]['state']);
        $this->assertSame('future', $cal['months'][11]['state'], 'a month that has not started');
        $this->assertSame('closing', $cal['quarters'][3]['state']);
        $this->assertSame(2, $cal['quarters'][3]['left']);
        $this->assertSame('free', $cal['quarters'][4]['state'], 'October starts Q4, so Q4 has started and has no dates');
    }

    public function test_a_closed_month_reopened_for_the_unit_says_so_and_only_for_that_unit(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $other = Directorate::create(['code' => 'OTH', 'name' => 'Another', 'type' => 'National Directorate', 'active' => true]);
        $this->window('month', 9, '2026-09-01', '2026-09-30');
        app(ReportingWindows::class)->reopen($admin, 2026, 'month', 9, $this->unit->id, '2026-10-20', 'Late data');

        $service = new ReportingWindows;
        $this->assertSame('reopened', $service->calendar(2026, $this->unit->id, 10)['months'][9]['state']);
        $this->assertSame('closed', $service->calendar(2026, $other->id, 10)['months'][9]['state']);
    }

    public function test_the_page_shows_the_calendar_and_choosing_a_month_changes_the_month(): void
    {
        $user = User::factory()->create(['directorate_id' => $this->unit->id]);
        $user->assignRole('Director');
        $this->window('month', 9, '2026-09-01', '2026-09-30');
        session(['fiscal_year' => 2026]);

        $page = Volt::actingAs($user)->test('pages.monitoring.report-execution');

        $page->assertSee('Reporting calendar')->assertSee('closed 30/09')->assertSet('month', 10);
        $page->call('selectMonth', 9)->assertSet('month', 9);
        $page->call('selectMonth', 12)->assertSet('month', 10, 'a month that has not started cannot be chosen');
    }

    public function test_the_dates_are_read_once_and_cached_until_the_calendar_changes(): void
    {
        $this->window('month', 9, '2026-09-01', '2026-09-30');
        $service = app(ReportingWindows::class);
        $service->calendar(2026, $this->unit->id, 10);

        \DB::enableQueryLog();
        $service->calendar(2026, $this->unit->id, 10);
        $windowQueries = collect(\DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'reporting_windows'))->count();
        \DB::disableQueryLog();
        $this->assertSame(0, $windowQueries, 'the dates of the year come from the cache');

        $this->window('month', 8, '2026-08-01', '2026-08-31');
        $this->assertSame('closed', $service->calendar(2026, $this->unit->id, 10)['months'][8]['state'], 'a new window renews the cache');
    }
}
