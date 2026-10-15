<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
use App\Models\Program;
use App\Models\User;
use App\Services\DeliveryReport;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * A count adds up over the quarters; a rate (a percentage reached) is a level, where the latest quarter
 * is where it stands. Adding rates up would overstate them.
 */
class DeliveryMeasureTest extends TestCase
{
    use RefreshDatabase;

    private const YEAR = 2028;

    private Activity $activity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app()->setLocale('en');
        $unit = Directorate::create(['name' => 'Unit', 'code' => 'UNT', 'type' => 'General Directorate', 'active' => true]);
        $program = Program::create(['fiscal_year' => self::YEAR, 'oge_code' => 'P1', 'name' => 'P']);
        $this->activity = Activity::create([
            'fiscal_year' => self::YEAR, 'program_id' => $program->id, 'directorate_id' => $unit->id, 'description' => 'Activity',
            'priority' => 'Alta', 'planned_start' => self::YEAR.'-01-01', 'planned_end' => self::YEAR.'-12-31', 'quarter' => 1,
            'status' => Activity::STATUS_APPROVED, 'is_test_data' => false,
        ]);
    }

    private function indicator(string $measure, float $target, array $verifiedByQuarter, string $status = 'Verified'): Indicator
    {
        $i = Indicator::create(['activity_id' => $this->activity->id, 'name' => 'I', 'type' => 'Output', 'unit' => $measure === 'level' ? '%' : 'reports', 'measure' => $measure, 'annual_target' => $target]);
        foreach ($verifiedByQuarter as $q => $value) {
            IndicatorProgress::create(['indicator_id' => $i->id, 'quarter' => $q, 'achieved_value' => $value, 'status' => $status]);
        }

        return $i;
    }

    private function summary(): array
    {
        return app(DeliveryReport::class)->summary(DB::table('activities')->where('fiscal_year', self::YEAR)->select('id'));
    }

    public function test_a_level_stands_at_its_latest_value_and_a_count_adds_up(): void
    {
        $this->indicator('level', 100, [1 => 40, 2 => 60, 3 => 85]);       // 85% reached, not 185%
        $this->indicator('cumulative', 4, [1 => 1, 2 => 1, 3 => 1]);       // 3 of 4 reports = 75%

        $s = $this->summary();

        $this->assertSame(2, $s['n']);
        $this->assertEqualsWithDelta((85 + 75) / 2, $s['achievement'], 0.1);
        // after each quarter: Q1 (40 + 25) / 2, Q2 (60 + 50) / 2, Q3 (85 + 75) / 2, Q4 unchanged
        $this->assertEqualsWithDelta([32.5, 55.0, 80.0, 80.0], $s['quarters'], 0.1);
    }

    public function test_an_indicator_is_capped_at_its_target_and_unverified_values_do_not_count(): void
    {
        $this->indicator('cumulative', 4, [1 => 5]);                      // over target: capped at 100%
        $this->indicator('level', 100, [1 => 90], 'Submitted');           // not verified: counts as nothing

        $this->assertEqualsWithDelta(50.0, $this->summary()['achievement'], 0.1);
    }

    public function test_the_average_is_by_indicator_so_big_counts_do_not_drown_small_ones(): void
    {
        $this->indicator('cumulative', 15000, [1 => 7500]);   // 50%
        $this->indicator('cumulative', 4, [1 => 4]);          // 100%

        $this->assertEqualsWithDelta(75.0, $this->summary()['achievement'], 0.1);
    }

    public function test_the_programme_view_uses_the_same_rule(): void
    {
        $this->indicator('level', 100, [1 => 40, 2 => 60, 3 => 85]);

        $byProgramme = app(DeliveryReport::class)->byProgramme(self::YEAR);

        $this->assertEqualsWithDelta(85.0, array_values($byProgramme)[0], 0.1);
    }

    public function test_a_percentage_is_a_level_by_default_and_the_rule_is_on_the_model(): void
    {
        $this->assertSame(Indicator::LEVEL, Indicator::defaultMeasure('%'));
        $this->assertSame(Indicator::CUMULATIVE, Indicator::defaultMeasure('reports'));

        $level = new Indicator(['measure' => 'level']);
        $this->assertSame(85.0, $level->standing(collect([1 => 40, 3 => 85, 2 => 60])));
        $this->assertSame(185.0, (new Indicator(['measure' => 'cumulative']))->standing(collect([1 => 40, 2 => 60, 3 => 85])));
    }

    public function test_the_form_marks_a_percentage_as_a_level_and_saves_how_it_is_measured(): void
    {
        $unit = Directorate::where('code', 'UNT')->first();
        $planner = User::factory()->create(['directorate_id' => $unit->id]);
        $planner->assignRole('Planning');
        $this->actingAs($planner);

        $page = Volt::test('pages.activities.activity-form')
            ->set('indicators.0.name', 'Public service available')->set('indicators.0.unit', '%')->set('indicators.0.target', '100');

        $page->assertSet('indicators.0.measure', 'level');
    }
}
