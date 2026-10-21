<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Program;
use App\Models\User;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** The Simulator follows the real chain on who approves: directors and administrators, never the Minister or the technical officer. */
#[Group('office')]
class SimulatorApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Activity $simulation;

    protected function setUp(): void
    {
        parent::setUp();
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        app()->setLocale('en');
        $source = Activity::where('fiscal_year', 2027)->firstOrFail();
        $this->simulation = Activity::create([
            'fiscal_year' => 2027, 'program_id' => Program::firstOrFail()->id, 'directorate_id' => $source->directorate_id,
            'description' => 'Practice activity', 'quarter' => 1, 'priority' => 'Alta', 'planned_start' => '2027-01-01', 'planned_end' => '2027-12-31',
            'status' => Activity::STATUS_SUBMITTED, 'is_test_data' => true,
        ]);
    }

    private function approveAs(User $user): string
    {
        Volt::actingAs($user)->test('testing.simulation-list')->call('approve', $this->simulation->id);

        return Activity::testData()->findOrFail($this->simulation->id)->status;
    }

    public function test_the_minister_and_the_technical_officer_do_not_approve(): void
    {
        $minister = User::factory()->create();
        $minister->assignRole('Minister');
        $this->assertSame(Activity::STATUS_SUBMITTED, $this->approveAs($minister));

        $this->assertSame(Activity::STATUS_SUBMITTED, $this->approveAs(User::where('email', 'tecnico.udig@gdsc.tl')->firstOrFail()));
    }

    public function test_a_director_approves(): void
    {
        $this->assertSame(Activity::STATUS_APPROVED, $this->approveAs(User::where('email', 'director.udig@gdsc.tl')->firstOrFail()));
    }
}
