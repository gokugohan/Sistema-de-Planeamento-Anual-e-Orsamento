<?php

namespace Tests\Feature;

use App\Models\AdjustedBudget;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\LineItem;
use App\Models\Program;
use App\Models\User;
use App\Services\PlanBreakdown;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('office')]
class PlanBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private function plannedIn(int $year): float
    {
        return (float) LineItem::whereHas('activity', fn ($q) => $q->where('fiscal_year', $year))->sum('estimated_amount');
    }

    private function approvedIn(int $year): float
    {
        return (float) AdjustedBudget::whereHas('lineItem.activity', fn ($q) => $q->where('fiscal_year', $year))->sum('approved_amount');
    }

    public function test_the_totals_match_the_raw_budget_lines(): void
    {
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        $breakdown = app(PlanBreakdown::class);

        foreach ([2026, 2027] as $year) {
            $root = Directorate::where('code', 'GDSC')->firstOrFail();
            $totals = $breakdown->unitTotals($year);

            $this->assertEqualsWithDelta($this->plannedIn($year), $totals[$root->id]['planned'], 0.005, "planned {$year}");
            $this->assertEqualsWithDelta($this->approvedIn($year), $totals[$root->id]['approved'], 0.005, "approved {$year}");
            $this->assertEqualsWithDelta($this->approvedIn($year), $breakdown->approvedTotal($year), 0.005);

            $programmes = $breakdown->programmes($year);
            $this->assertEqualsWithDelta($this->plannedIn($year), $programmes->sum('planned'), 0.005);
            $this->assertEqualsWithDelta($this->approvedIn($year), $programmes->sum('approved'), 0.005);
        }
    }

    public function test_a_unit_includes_the_units_below_it(): void
    {
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        $totals = app(PlanBreakdown::class)->unitTotals(2026);

        $gdsc = Directorate::where('code', 'GDSC')->value('id');
        $parts = Directorate::whereIn('code', ['UDIG', 'USC'])->pluck('id')->sum(fn ($id) => $totals[$id]['planned']);

        $this->assertGreaterThan($parts, $totals[$gdsc]['planned'], 'the Gabinete also has its own activities');
        $this->assertGreaterThan(0, $totals[Directorate::where('code', 'UDIG')->value('id')]['planned']);
    }

    public function test_a_year_without_a_plan_is_empty(): void
    {
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();

        $this->assertCount(0, app(PlanBreakdown::class)->programmes(2019));
        $this->assertSame(0.0, app(PlanBreakdown::class)->approvedTotal(2019));
    }

    private function adminWithSample(): User
    {
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        $admin = User::where('email', 'chefe.gdsc@gdsc.tl')->firstOrFail();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        return $admin;
    }

    public function test_the_report_only_prints_activities_when_asked_to(): void
    {
        $this->adminWithSample();
        $name = 'Plataforma única de serviços digitais do Governo';

        $this->get('/reports/paa?year=2026')->assertOk()->assertDontSee($name)->assertSee('data-activities-url', false);
        $this->get('/reports/paa?year=2026&full=1')->assertOk()->assertSee($name);
    }

    public function test_the_activities_of_a_category_load_on_demand(): void
    {
        $this->adminWithSample();
        $program = Program::forYear(2026)->where('oge_code', 'A08')->firstOrFail();
        $class = EconomicClassification::where('code', '20')->firstOrFail();

        $this->get('/reports/paa/activities?'.http_build_query(['year' => 2026, 'program' => $program->id, 'class' => $class->id, 'parent' => 'prog-1-class-1']))
            ->assertOk()
            ->assertSee('Plataforma única de serviços digitais do Governo')
            ->assertSee('data-parent="prog-1-class-1"', false);

        $this->get('/reports/paa/activities?year=2026&program=1&class=1&parent='.urlencode('"><script>'))->assertSessionHasErrors('parent');
    }
}
