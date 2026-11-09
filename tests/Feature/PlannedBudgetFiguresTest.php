<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\LineItem;
use App\Models\Program;
use App\Models\User;
use App\Support\FiscalYear;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** The planned budget is shown where people look for it, and it follows the filters like the counts beside it. */
class PlannedBudgetFiguresTest extends TestCase
{
    use RefreshDatabase;

    private int $year;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->seed(DatabaseSeeder::class);
        $this->year = FiscalYear::current();

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $a = Directorate::create(['name' => 'Land Transport', 'code' => 'DNTT', 'type' => 'Independent']);
        $b = Directorate::create(['name' => 'Postal Services', 'code' => 'DNSP', 'type' => 'Independent']);
        $program = Program::create(['name' => 'Transport', 'oge_code' => '502']);
        $this->activity($a, $program, 'Road safety campaign', 700000, Activity::STATUS_DRAFT);
        $this->activity($b, $program, 'Post office upkeep', 300000, Activity::STATUS_DRAFT);
    }

    private function activity(Directorate $unit, Program $program, string $description, float $amount, string $status): Activity
    {
        $activity = Activity::create([
            'fiscal_year' => $this->year, 'program_id' => $program->id, 'directorate_id' => $unit->id, 'description' => $description,
            'priority' => 'Média', 'planned_start' => "{$this->year}-02-01", 'planned_end' => "{$this->year}-06-30", 'quarter' => 1,
            'status' => $status, 'is_test_data' => false,
        ]);
        LineItem::create(['activity_id' => $activity->id, 'economic_classification_id' => EconomicClassification::first()->id, 'estimated_amount' => $amount, 'is_test_data' => false]);

        return $activity;
    }

    public function test_the_planned_budget_of_the_list_follows_the_filters(): void
    {
        Volt::test('pages.activities.list-activities')
            ->assertViewHas('totalPlanned', 1000000.0)->assertViewHas('totalPlan', null)
            ->set('search', 'Road safety')
            ->assertViewHas('totalPlanned', 700000.0)->assertViewHas('totalPlan', 1000000.0)
            ->assertSee('of $ 1,000,000 in the whole plan')
            ->set('search', '')->set('unit', (string) Directorate::where('code', 'DNSP')->value('id'))
            ->assertViewHas('totalPlanned', 300000.0);
    }

    public function test_the_list_of_one_unit_is_headed_by_the_unit_with_its_own_figures(): void
    {
        $unit = Directorate::where('code', 'DNSP')->first();
        $unit->update(['director_name' => 'Ana Soares']);
        $sub = Directorate::create(['name' => 'Counter services', 'code' => 'DNSP-B', 'type' => 'Department', 'parent_id' => $unit->id]);

        $page = Volt::test('pages.activities.list-activities')
            ->assertSee('PAA Activities')->assertDontSee('Share of the plan')
            ->set('unit', (string) $unit->id)
            ->assertSeeInOrder(['DNSP', 'Postal Services', 'Annual Activity Plan', 'Ana Soares'])
            ->assertSee('All units')
            ->assertSeeInOrder(['Planned budget', '$ 300,000', 'Share of the plan', '30.0%', 'of $ 1,000,000 in the whole plan', 'Plan status', 'Draft'])
            ->assertSee(EconomicClassification::first()->name);

        // a unit inside it says where it sits
        $inner = Volt::test('pages.activities.list-activities')->set('unit', (string) $sub->id)
            ->assertSeeInOrder(['DNSP-B', 'Counter services'])->assertSee('Nothing planned yet');
        $this->assertSame(['DNSP'], $inner->viewData('unitInfo')['ancestors']);

        // the other filters show as chips that remove themselves; "All units" brings back the whole plan
        $page->set('search', 'Post office')->assertSee('“Post office”')
            ->call('clearFilter', 'search')->assertSet('search', '')
            ->call('clearFilter', 'unit')->assertSet('unit', '')->assertSee('PAA Activities')
            ->call('clearFilter', 'tab')->assertSet('tab', 'all'); // not a filter: ignored
    }

    public function test_the_dashboard_leads_with_the_planned_budget(): void
    {
        $this->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder(['Planned budget', '$ 1,000,000', 'Approved: $ 0 (0%) · 2 activities']);
    }

    public function test_the_dashboard_sums_the_own_services_and_lists_each_autonomous_body(): void
    {
        $program = Program::first();
        $port = Directorate::create(['name' => 'Port Authority', 'code' => 'PORT', 'type' => 'Independent', 'administration' => Directorate::INDIRECT]);
        $office = Directorate::create(['name' => 'Port office', 'code' => 'PORT-O', 'type' => 'Unit', 'parent_id' => $port->id]);
        $this->activity($office, $program, 'Quay repair', 250000, Activity::STATUS_DRAFT);
        Directorate::create(['name' => 'Airports', 'code' => 'AIR', 'type' => 'Independent', 'administration' => Directorate::INDIRECT]); // nothing planned: left out

        $entities = Volt::test('pages.dashboard.summary')
            ->assertSee('Budget by entity')->assertSee(EconomicClassification::first()->name)
            ->viewData('entities');

        $this->assertCount(2, $entities);
        $this->assertSame(1000000.0, $entities[0]['plan']);              // the two own units together
        $this->assertSame(['DNTT', 'DNSP'], array_map(fn ($m) => strtok($m['label'], ' '), $entities[0]['members']));
        $this->assertSame('PORT — Port Authority', $entities[1]['label']); // a body, with its own unit counted in it
        $this->assertSame(250000.0, $entities[1]['plan']);

        // filtered to the body, only its row is left
        $this->assertCount(1, Volt::test('pages.dashboard.summary')->set('unit', (string) $port->id)->viewData('entities'));
    }
}
