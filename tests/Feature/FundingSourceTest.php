<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\FundingSource;
use App\Models\LineItem;
use App\Models\Program;
use App\Models\User;
use App\Services\PerformanceReport;
use Carbon\Carbon;
use Database\Seeders\EconomicClassificationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Funding source is a property of the budget line, chosen from a list the administration keeps. */
class FundingSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        app()->setLocale('en');
    }

    private function activity(): Activity
    {
        $unit = Directorate::create(['name' => 'Unit', 'code' => 'UNT', 'type' => 'Independent']);
        $program = Program::create(['name' => 'Programme', 'oge_code' => 'P-001']);

        return Activity::create([
            'fiscal_year' => 2026, 'program_id' => $program->id, 'directorate_id' => $unit->id, 'description' => 'An activity', 'priority' => 'Alta',
            'planned_start' => '2026-02-01', 'planned_end' => '2026-06-30', 'quarter' => 1, 'status' => Activity::STATUS_DRAFT, 'is_test_data' => false,
        ]);
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('Admin');

        return $u;
    }

    public function test_the_list_starts_with_the_state_budget_as_default(): void
    {
        $this->assertSame('OGE', FundingSource::where('is_default', true)->value('code'));
        $this->assertSame(FundingSource::where('code', 'OGE')->value('id'), FundingSource::defaultId());
    }

    public function test_a_line_without_a_source_takes_the_default(): void
    {
        $this->seed(EconomicClassificationSeeder::class);
        $activity = $this->activity();

        $line = LineItem::create(['activity_id' => $activity->id, 'economic_classification_id' => EconomicClassification::first()->id, 'estimated_amount' => 10]);

        $this->assertSame(FundingSource::defaultId(), $line->fresh()->funding_source_id);
    }

    public function test_an_administrator_keeps_the_list_and_the_default_is_protected(): void
    {
        $admin = $this->admin();
        $page = Volt::actingAs($admin)->test('pages.management.funding-sources')
            ->set('code', 'WB')->set('name', 'World Bank grant')->set('kind', 'Partner')->call('save')->assertHasNoErrors();
        $wb = FundingSource::where('code', 'WB')->firstOrFail();
        $this->assertFalse($wb->is_default);

        $page->call('makeDefault', $wb->id);
        $this->assertSame($wb->id, FundingSource::defaultId());

        $oge = FundingSource::where('code', 'OGE')->firstOrFail();
        $page->call('makeDefault', $oge->id)->call('toggle', $oge->id); // the default cannot be switched off
        $this->assertTrue($oge->fresh()->active);

        $page->set('code', 'WB')->set('name', 'Again')->call('save')->assertHasErrors('code'); // codes are unique
    }

    public function test_only_an_administrator_opens_the_page(): void
    {
        $this->actingAs(User::factory()->create())->get(route('management.funding-sources'))->assertForbidden();
        $this->actingAs($this->admin())->get(route('management.funding-sources'))->assertOk()->assertSee('Funding sources');
    }

    public function test_a_source_with_budget_lines_is_never_deleted(): void
    {
        $this->seed(EconomicClassificationSeeder::class);
        $partner = FundingSource::where('code', 'PARTNER')->firstOrFail();
        $activity = $this->activity();
        LineItem::create(['activity_id' => $activity->id, 'economic_classification_id' => EconomicClassification::first()->id, 'funding_source_id' => $partner->id, 'estimated_amount' => 5]);

        Volt::actingAs($this->admin())->test('pages.management.funding-sources')->call('remove', $partner->id);

        $this->assertNotNull($partner->fresh());
    }

    public function test_the_report_column_comes_from_the_budget_lines(): void
    {
        Carbon::setTestNow('2026-10-14 10:00:00');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        $partner = FundingSource::where('code', 'PARTNER')->firstOrFail();
        $activity = Activity::where('fiscal_year', 2026)->where('status', 'Approved')->whereHas('lineItems.adjustedBudget')->firstOrFail();
        $activity->lineItems()->first()->forceFill(['funding_source_id' => $partner->id])->saveQuietly();

        $chief = User::where('email', 'chefe.gdsc@gdsc.tl')->firstOrFail();
        $rows = collect(app(PerformanceReport::class)->build(2026, 'quarter', 3, $chief)['tree'])->flatMap(fn ($p) => $p['subprograms']->flatMap(fn ($s) => $s['rows'])->merge($p['loose']));
        $row = $rows->first(fn ($r) => $r['activity']->id === $activity->id);

        $this->assertContains('Development partner', $row['funding']);
        Carbon::setTestNow();
    }
}
