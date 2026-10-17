<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\Program;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** An activity code is never given twice in a unit and year, even after an activity in the middle of the series was deleted. */
class ActivityCodeUniquenessTest extends TestCase
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

    private function make(string $code): Activity
    {
        return Activity::create([
            'fiscal_year' => 2027, 'program_id' => $this->program->id, 'directorate_id' => $this->unit->id, 'code' => $code,
            'description' => 'Activity '.$code, 'quarter' => 1, 'priority' => 'Alta', 'planned_start' => '2027-01-01', 'planned_end' => '2027-12-31', 'status' => Activity::STATUS_DRAFT,
        ]);
    }

    public function test_the_next_code_is_one_more_than_the_highest_in_use_not_the_count(): void
    {
        $this->assertSame('UNX-27-001', Activity::nextCode(2027, 'UNX'));

        $this->make('UNX-27-001');
        $middle = $this->make('UNX-27-002');
        $this->make('UNX-27-003');
        $middle->delete();

        $this->assertSame('UNX-27-004', Activity::nextCode(2027, 'UNX'));
    }

    public function test_codes_of_another_year_or_unit_do_not_count(): void
    {
        $this->make('UNX-27-005');

        $this->assertSame('UNX-28-001', Activity::nextCode(2028, 'UNX'));
        $this->assertSame('OTH-27-001', Activity::nextCode(2027, 'OTH'));
    }

    public function test_the_database_refuses_the_same_code_twice_in_a_unit_and_year(): void
    {
        $this->make('UNX-27-001');

        $this->expectException(UniqueConstraintViolationException::class);
        $this->make('UNX-27-001');
    }

    public function test_the_same_official_code_may_appear_in_another_unit(): void
    {
        $this->make('5100111');
        $other = Directorate::create(['code' => 'UNY', 'name' => 'Unit Y', 'type' => 'National Directorate', 'active' => true]);

        Activity::create([
            'fiscal_year' => 2027, 'program_id' => $this->program->id, 'directorate_id' => $other->id, 'code' => '5100111',
            'description' => 'Same budget-book code, other unit', 'quarter' => 1, 'priority' => 'Alta', 'planned_start' => '2027-01-01', 'planned_end' => '2027-12-31', 'status' => Activity::STATUS_DRAFT,
        ]);

        $this->assertSame(2, Activity::where('code', '5100111')->count());
    }

    public function test_activities_without_a_code_do_not_clash(): void
    {
        $a = $this->make('UNX-27-001');
        $b = $this->make('UNX-27-002');
        Activity::whereIn('id', [$a->id, $b->id])->update(['code' => null]);

        $this->assertSame(2, Activity::whereNull('code')->count());
    }
}
