<?php

namespace Tests\Feature;

use App\Models\BudgetExecution;
use App\Models\IndicatorProgress;
use App\Services\ExecutionReport;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('office')]
class ExecutionReportTest extends TestCase
{
    use RefreshDatabase;

    private function load(string $today): void
    {
        Carbon::setTestNow($today);
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_in_october_the_running_year_has_three_finished_quarters_and_the_fourth_under_way(): void
    {
        $this->load('2026-10-14 10:00:00');
        $service = app(ExecutionReport::class);
        $s = $service->summary($service->load(2026));

        $this->assertSame(4, $s['quarter']);
        $this->assertTrue($s['has_actuals']);
        $this->assertGreaterThan(70, $s['elapsed']);
        $this->assertLessThan(80, $s['elapsed']);
        $this->assertGreaterThan(0, $s['executed_rate']);
        $this->assertLessThan(100, $s['executed_rate']);

        $executed = array_column($s['quarters'], 'executed');
        $this->assertNotContains(null, $executed, 'every quarter has started, so every quarter has figures');
        $this->assertSame($executed, collect($executed)->sort()->values()->all(), 'cumulative executed never goes down');
        $this->assertGreaterThan($s['quarters'][2]['executed'], $s['quarters'][3]['executed'], 'Q4 is under way');
    }

    public function test_a_finished_year_is_executed_to_the_end(): void
    {
        $this->load('2027-02-10 10:00:00'); // 2026 is over, 2027 has just begun
        $service = app(ExecutionReport::class);

        $done = $service->summary($service->load(2026));
        $this->assertEqualsWithDelta(100, $done['elapsed'], 0.01);
        $this->assertGreaterThan(80, $done['executed_rate']);
        $this->assertNotContains(null, array_column($done['quarters'], 'executed'));

        $new = $service->summary($service->load(2027));
        $this->assertSame(1, $new['quarter']);
    }

    public function test_a_year_that_has_not_started_has_nothing_executed(): void
    {
        $this->load('2026-10-14 10:00:00');
        $service = app(ExecutionReport::class);

        $future = $service->summary($service->load(2027));
        $this->assertFalse($future['has_actuals']);
        $this->assertSame(0, $future['quarter']);
        $this->assertSame([null, null, null, null], array_column($future['quarters'], 'executed'));
    }

    public function test_the_sample_follows_the_date_it_is_loaded_on(): void
    {
        $this->load('2026-05-10 10:00:00');
        $service = app(ExecutionReport::class);
        $s = $service->summary($service->load(2026));

        $this->assertSame(2, $s['quarter']);
        $this->assertSame(0, BudgetExecution::where('quarter', '>', 2)->count(), 'nothing is executed in quarters that have not started');
        $this->assertSame([null, null], array_slice(array_column($s['quarters'], 'executed'), 2));
    }

    public function test_executed_never_exceeds_approved(): void
    {
        $this->load('2026-10-14 10:00:00');
        $data = app(ExecutionReport::class)->load(2026);

        foreach ($data['activities'] as $a) {
            $this->assertLessThanOrEqual($a['approved'] + 0.01, array_sum($a['executed']));
        }
    }

    public function test_only_verified_entries_count(): void
    {
        $this->load('2026-10-14 10:00:00');
        $service = app(ExecutionReport::class);
        $before = $service->summary($service->load(2026))['executed'];

        $this->assertGreaterThan(0, BudgetExecution::where('status', BudgetExecution::SUBMITTED)->count(), 'the running quarter has entries waiting for verification');

        BudgetExecution::query()->update(['status' => BudgetExecution::SUBMITTED]);
        $this->assertSame(0.0, $service->summary($service->load(2026))['executed']);
        $this->assertGreaterThan(0, $before);
    }

    public function test_physical_progress_is_sampled_too_and_the_sample_can_be_loaded_twice(): void
    {
        $this->load('2026-10-14 10:00:00');
        $executions = BudgetExecution::count();
        $progress = IndicatorProgress::count();
        $this->assertGreaterThan(0, $progress);

        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();

        $this->assertSame($executions, BudgetExecution::count());
        $this->assertSame($progress, IndicatorProgress::count());
        $this->assertSame(0, DB::table('budget_executions')->selectRaw('line_item_id, month, COUNT(*) n')->groupBy('line_item_id', 'month')->having('n', '>', 1)->count());
    }

    public function test_the_current_quarter_is_a_fraction_of_the_way_through(): void
    {
        $service = app(ExecutionReport::class);

        $this->assertSame([0, 0.0], $service->position(2027, Carbon::parse('2026-10-14')));
        $this->assertSame([4, 1.0], $service->position(2025, Carbon::parse('2026-10-14')));
        [$q, $f] = $service->position(2026, Carbon::parse('2026-10-14'));
        $this->assertSame(4, $q);
        $this->assertEqualsWithDelta(14 / 92, $f, 0.01);
    }
}
