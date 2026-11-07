<?php

namespace Tests\Feature;

use App\Models\Directorate;
use App\Models\ReportingWindow;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WindowExtension;
use App\Services\CalendarAlerts;
use App\Services\ReportingWindows;
use App\Support\FiscalYear;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** A month or a quarter with no dates stays closed: nobody reports a period before the administrator has set its dates. */
class ReportingNeedsDatesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $officer;

    private Directorate $unit;

    private ReportingWindows $windows;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        app()->setLocale('en');
        config(['paa.reporting_needs_dates' => true]);
        $this->seed(DatabaseSeeder::class);
        $this->unit = Directorate::create(['code' => 'DNTT', 'name' => 'Terrestres', 'type' => 'National Directorate', 'active' => true]);
        $this->admin = User::where('email', 'admin@example.org')->firstOrFail();
        $this->officer = User::factory()->create(['directorate_id' => $this->unit->id]);
        $this->officer->assignRole('Planning');
        $this->windows = app(ReportingWindows::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_period_with_no_dates_is_closed_and_says_why(): void
    {
        foreach ([['month', 9], ['quarter', 3]] as [$type, $period]) {
            $this->assertFalse($this->windows->allows($this->officer, 2026, $type, $period));
            $this->assertStringContainsString('has no dates yet', $this->windows->closedReason($this->officer, 2026, $type, $period));
        }
        $this->assertSame('undated', $this->windows->calendar(2026, $this->unit->id, 10)['months'][9]['state']);
    }

    public function test_dates_open_it_and_the_other_steps_stay_open_without_dates(): void
    {
        ReportingWindow::create(['fiscal_year' => 2026, 'period_type' => 'month', 'period' => 10, 'opens_on' => '2026-10-01', 'closes_on' => '2026-11-10']);

        $this->assertTrue($this->windows->allows($this->officer, 2026, 'month', 10));
        $this->assertFalse($this->windows->allows($this->officer, 2026, 'month', 9), 'one period dated opens only that period');
        $this->assertTrue($this->windows->allows($this->officer, 2026, ReportingWindows::APPROVAL, 1));
        $this->assertTrue($this->windows->allows($this->officer, 2026, ReportingWindows::REVISION, 1));
    }

    public function test_a_unit_reopened_can_report_a_period_with_no_dates(): void
    {
        WindowExtension::create(['fiscal_year' => 2026, 'period_type' => 'month', 'period' => 9, 'directorate_id' => $this->unit->id, 'closes_on' => '2026-10-20', 'created_by' => $this->admin->id]);

        $this->assertTrue($this->windows->allows($this->officer, 2026, 'month', 9));
        $this->assertSame('reopened', $this->windows->calendar(2026, $this->unit->id, 10)['months'][9]['state']);
    }

    public function test_testing_mode_keeps_undated_periods_open(): void
    {
        SystemSetting::set('enable_paa_testing_mode', true, 'boolean', 'Testing');

        $this->assertTrue($this->windows->allows($this->officer, 2026, 'month', 9));
        $this->assertSame('free', $this->windows->calendar(2026, $this->unit->id, 10)['months'][9]['state']);
    }

    public function test_the_administrator_is_told_and_the_calendar_shows_closed(): void
    {
        $year = FiscalYear::execution();
        $this->assertContains("Reporting for $year has no dates yet, so the units cannot report. Fill in the dates in the Calendar.",
            collect(app(CalendarAlerts::class)->for($this->admin, $year))->pluck('text')->all());

        Volt::actingAs($this->admin)->test('pages.management.reporting-windows')
            ->assertSee('without dates it stays closed')->assertSee('Closed');

        foreach (range(1, 12) as $m) {
            ReportingWindow::create(['fiscal_year' => $year, 'period_type' => 'month', 'period' => $m, 'opens_on' => "$year-01-01", 'closes_on' => "$year-12-31"]);
        }
        $this->assertEmpty(collect(app(CalendarAlerts::class)->for($this->admin, $year))->filter(fn ($a) => str_contains($a['text'], 'no dates yet, so the units')));
    }
}
