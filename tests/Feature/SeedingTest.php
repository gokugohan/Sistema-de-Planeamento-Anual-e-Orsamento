<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\AdjustedBudget;
use App\Models\BudgetCycle;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\FiscalEnvelope;
use App\Models\LineItem;
use App\Models\Program;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\Samples\MtcSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedingTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_seed_is_institution_neutral(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(5, EconomicClassification::count());
        $this->assertSame(1, User::role('Admin')->count());
        $this->assertSame(0, Directorate::count());
        $this->assertSame(0, Program::count());
        $this->assertSame(0, Activity::count());
        $this->assertSame('Institution Name', SystemSetting::getLocalized('org_name', '', 'en'));
    }

    public function test_default_seed_can_be_repeated_without_overwriting_anything(): void
    {
        $this->seed(DatabaseSeeder::class);
        SystemSetting::set('org_name', ['en' => 'My Ministry'], 'json');

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::role('Admin')->count(), 'a second administrator was created');
        $this->assertSame('My Ministry', SystemSetting::getLocalized('org_name', '', 'en'), 'a customised setting was overwritten');
    }

    public function test_admin_seeder_refuses_to_run_in_production_without_a_password(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->app['env'] = 'production';
        putenv('ADMIN_PASSWORD');
        unset($_ENV['ADMIN_PASSWORD'], $_SERVER['ADMIN_PASSWORD']);

        $this->expectException(\RuntimeException::class);
        $this->app->make(AdminSeeder::class)->run(); // direct call: db:seed would ask for confirmation first
    }

    public function test_mtc_sample_data_loads_and_can_be_purged(): void
    {
        $this->seed(MtcSampleDataSeeder::class);

        $this->assertSame(49, Directorate::count());
        $this->assertSame(18, Program::count());
        $this->assertSame(274, Activity::count());
        $this->assertSame('MTC', SystemSetting::getLocalized('org_abbr', '', 'en'));
        $this->assertTrue(User::where('email', 'ministro@goku.tl')->first()->isMinister());
        // Real activities carry their official budget codes.
        $this->assertDatabaseHas('activities', ['fiscal_year' => 2025, 'code' => '5020201']);

        $this->artisan('paa:purge-samples', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, Activity::count());
        $this->assertSame(0, Directorate::count());
        $this->assertSame(0, Program::count());
    }

    /** DL 75/2023 art. 7: five public bodies with administrative and financial autonomy; everything else is a ministry service. */
    public function test_mtc_autonomous_bodies_are_flagged_as_indirect_administration(): void
    {
        $this->seed(MtcSampleDataSeeder::class);

        $autonomous = Directorate::where('administration', Directorate::INDIRECT)->whereNull('parent_id')->orderBy('code')->pluck('legal_form', 'code')->all();
        $this->assertSame(['AACTL' => 'I.P.', 'ANATL' => 'E.P.', 'ANC' => 'I.P.', 'APORTIL' => 'I.P.', 'TICTIMOR' => 'I.P.'], $autonomous);
        $this->assertSame(35, Directorate::where('administration', Directorate::DIRECT)->count());
        $this->assertStringContainsString('DL 75/2023', Directorate::where('code', 'ANC')->value('legal_basis'));

        // The only divisions below an autonomous body are the ones the State Budget itself prints.
        $this->assertEqualsCanonicalizing(
            ['APORTIL-BN', 'AACTL-GCA', 'AACTL-DNA', 'AACTL-DIA', 'AACTL-DSO', 'AACTL-DFS', 'AACTL-DLAM', 'AACTL-DAF', 'AACTL-SA'],
            Directorate::whereIn('parent_id', Directorate::whereNull('parent_id')->where('administration', Directorate::INDIRECT)->pluck('id'))->pluck('code')->all()
        );

        // Four of them have a budget of their own in the OGE; ANATL is a public enterprise and has none.
        $planned = fn (string $code) => Activity::whereIn('directorate_id', Directorate::where('code', $code)->orWhere('parent_id', Directorate::where('code', $code)->value('id'))->pluck('id'))->count();
        foreach (['APORTIL', 'TICTIMOR', 'AACTL', 'ANC'] as $code) {
            $this->assertGreaterThan(0, $planned($code), "$code has no planned activity");
        }
        $this->assertSame(0, $planned('ANATL'));
    }

    /** The totals printed in the budget laws (Lei 8/2024, Lei 8/2025 and 8/2026) and in the 2027 bill. */
    public function test_mtc_real_plans_match_the_budget_tables(): void
    {
        $this->seed(MtcSampleDataSeeder::class);

        $plan = function (int $year, string $unit): float {
            $ids = Directorate::where('code', $unit)->orWhere('parent_id', Directorate::where('code', $unit)->value('id'))->pluck('id');
            $total = 0.0;
            foreach (Activity::where('fiscal_year', $year)->whereIn('directorate_id', $ids)->with('lineItems')->get() as $a) {
                $total += (float) $a->lineItems->sum('estimated_amount');
            }

            return $total;
        };
        $ministry = fn (int $year) => array_sum(array_map(fn ($u) => $plan($year, $u), ['GM', 'GIGTC', 'GJ', 'GPPC', 'GPIAIAMT', 'GDSC', 'DGT', 'DGC', 'DGAF']));

        // Ministry (entity 025): 13 353 528 / 12 800 471 / 14 620 942 USD.
        $this->assertEqualsWithDelta(13353528, $ministry(2025), 0.01);
        $this->assertEqualsWithDelta(12800471, $ministry(2026), 0.01);
        $this->assertEqualsWithDelta(14620942, $ministry(2027), 0.01);

        // Autonomous bodies, as printed in the OGE: APORTIL, TIC Timor, AACTL, ANC.
        $this->assertEqualsWithDelta(4370747, $plan(2025, 'APORTIL'), 0.01);
        $this->assertEqualsWithDelta(2331274, $plan(2025, 'TICTIMOR'), 0.01);
        $this->assertEqualsWithDelta(998901, $plan(2025, 'AACTL'), 0.01);
        $this->assertEqualsWithDelta(1285196, $plan(2025, 'ANC'), 0.01);
        $this->assertEqualsWithDelta(4482508, $plan(2026, 'APORTIL'), 0.01);
        $this->assertEqualsWithDelta(1980023, $plan(2026, 'TICTIMOR'), 0.01);
        $this->assertEqualsWithDelta(956213, $plan(2026, 'AACTL'), 0.01);
        $this->assertEqualsWithDelta(1152956, $plan(2026, 'ANC'), 0.01);
    }

    public function test_mtc_budget_states_follow_the_real_calendar(): void
    {
        $this->seed(MtcSampleDataSeeder::class);

        // 2025: law in force and the year is over. 2026: law in force, then amended (-126 209 USD on the ministry).
        $e25 = FiscalEnvelope::where('fiscal_year', 2025)->firstOrFail();
        $e26 = FiscalEnvelope::where('fiscal_year', 2026)->firstOrFail();
        $this->assertSame(FiscalEnvelope::STATUS_CLOSED, $e25->status);
        $this->assertSame(FiscalEnvelope::STATUS_APPROVED, $e26->status);
        $this->assertEqualsWithDelta(22339646, (float) $e25->total_amount, 0.01);
        $this->assertEqualsWithDelta(21245962, (float) $e26->total_amount, 0.01);
        $this->assertStringContainsString('Lei n.º 8/2026', $e26->budget_law_reference);

        $distributed = fn (int $y) => (float) AdjustedBudget::whereHas('lineItem.activity', fn ($q) => $q->where('fiscal_year', $y))->sum('approved_amount');
        $this->assertEqualsWithDelta(22339646, $distributed(2025), 0.01, 'the 2025 envelope covers the whole plan');
        $this->assertEqualsWithDelta(21245962, $distributed(2026), 0.01, 'the amended 2026 envelope is fully distributed, pro rata');

        // 2027: the budget is not decided yet, so there is no envelope and the plan is simply open.
        $this->assertNull(FiscalEnvelope::where('fiscal_year', 2027)->first());
        $this->assertSame(Activity::STATUS_APPROVED, Activity::where('fiscal_year', 2027)->value('status'));
    }

    public function test_the_real_dataset_can_be_purged_even_with_a_closed_year(): void
    {
        $this->seed(MtcSampleDataSeeder::class);

        $this->artisan('paa:purge-samples', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, Activity::count());
        $this->assertSame(0, FiscalEnvelope::count());
        $this->assertSame(0, BudgetCycle::count());
        $this->assertSame(0, LineItem::count());
    }

    public function test_mtc_sample_data_is_refused_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(\RuntimeException::class);
        $this->app->make(MtcSampleDataSeeder::class)->run();
    }
}
