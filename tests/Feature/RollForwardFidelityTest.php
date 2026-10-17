<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\Program;
use App\Services\ActivityRollForward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Rolling an activity into the next year keeps what describes it: indicators in full, their actions, and which lines pay for which indicator. */
class RollForwardFidelityTest extends TestCase
{
    use RefreshDatabase;

    private Directorate $unit;

    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();
        $this->unit = Directorate::create(['code' => 'UNX', 'name' => 'Unit X', 'type' => 'National Directorate', 'active' => true]);
        $this->program = Program::create(['oge_code' => '001', 'name' => 'Programme']);
    }

    private function source(string $status): Activity
    {
        $a = Activity::create([
            'fiscal_year' => 2026, 'program_id' => $this->program->id, 'directorate_id' => $this->unit->id, 'code' => 'UNX-26-'.str_pad((string) (Activity::count() + 1), 3, '0', STR_PAD_LEFT),
            'description' => 'Source '.$status, 'quarter' => 1, 'priority' => 'Alta', 'planned_start' => '2026-01-01', 'planned_end' => '2026-12-31', 'status' => $status,
        ]);
        $i = $a->indicators()->create([
            'name' => 'Km built', 'type' => 'Output', 'unit' => 'km', 'measure' => 'cumulative', 'annual_target' => 12, 'baseline' => '3 km',
            'target_text' => 'Twelve kilometres', 'means_of_verification' => 'Site report', 'due_quarters' => [2, 4],
        ]);
        $i->actions()->create(['position' => 1, 'text' => 'Survey the road', 'months' => [4, 5]]);
        $i->actions()->create(['position' => 2, 'text' => 'Lay the surface', 'months' => [10]]);
        $a->lineItems()->create(['code' => 'L1', 'indicator_id' => $i->id, 'economic_classification_id' => EconomicClassification::firstOrCreate(['code' => '50'], ['name' => 'Capital'])->id, 'estimated_amount' => 500]);

        return $a;
    }

    public function test_indicators_with_their_actions_and_the_line_to_indicator_link_are_copied(): void
    {
        $this->source(Activity::STATUS_APPROVED);

        app(ActivityRollForward::class)->copy(2026, 2027);

        $copy = Activity::where('fiscal_year', 2027)->firstOrFail();
        $i = $copy->indicators()->with('actions')->firstOrFail();
        $this->assertSame('3 km', $i->baseline);
        $this->assertSame('Twelve kilometres', $i->target_text);
        $this->assertSame('Site report', $i->means_of_verification);
        $this->assertSame([2, 4], $i->due_quarters);
        $this->assertSame(['Survey the road', 'Lay the surface'], $i->actions->pluck('text')->all());
        $this->assertSame([4, 5], $i->actions->first()->months);
        $line = $copy->lineItems()->firstOrFail();
        $this->assertSame($i->id, $line->indicator_id, 'the line still pays for the copied indicator, not the old one');
        $this->assertSame('L1', $line->code);
    }

    public function test_only_the_chosen_statuses_are_copied(): void
    {
        $this->source(Activity::STATUS_APPROVED);
        $this->source(Activity::STATUS_DRAFT);

        $result = app(ActivityRollForward::class)->copy(2026, 2027, null, [Activity::STATUS_APPROVED]);

        $this->assertSame(1, $result['copied']);
        $this->assertSame('Source '.Activity::STATUS_APPROVED, Activity::where('fiscal_year', 2027)->firstOrFail()->description);
    }
}
