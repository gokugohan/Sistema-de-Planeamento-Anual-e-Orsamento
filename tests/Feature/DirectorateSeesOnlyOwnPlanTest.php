<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\Program;
use App\Models\User;
use App\Services\PlanningWindow;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** A directorate user sees the plan of their own directorate only; an administrator sees every directorate. */
class DirectorateSeesOnlyOwnPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        app()->setLocale('en');
        // a second directorate with a plan of its own
        $other = Directorate::create(['code' => 'XOTHER', 'name' => 'Other Directorate Secret', 'type' => 'National Directorate', 'active' => true]);
        Activity::create([
            'fiscal_year' => 2027, 'program_id' => Program::firstOrFail()->id, 'directorate_id' => $other->id, 'code' => 'XOTHER-27-001',
            'description' => 'Activity of another directorate', 'quarter' => 1, 'priority' => 'Alta', 'planned_start' => '2027-01-01', 'planned_end' => '2027-12-31', 'status' => Activity::STATUS_SUBMITTED,
        ]);
        PlanningWindow::forgetTree();
    }

    public function test_the_planning_card_of_a_directorate_head_lists_only_their_directorate(): void
    {
        $head = User::where('email', 'chefe.gdsc@gdsc.tl')->firstOrFail();

        Volt::actingAs($head)->test('pages.planning.planning-window', ['year' => 2027])
            ->assertSee('GDSC')->assertDontSee('XOTHER')->assertDontSee('Other Directorate Secret');
    }

    public function test_the_planning_card_of_an_administrator_lists_every_directorate(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        Volt::actingAs($admin)->test('pages.planning.planning-window', ['year' => 2027])
            ->assertSee('GDSC')->assertSee('XOTHER');
    }

    public function test_the_activity_list_of_a_directorate_user_holds_only_their_activities(): void
    {
        $officer = User::where('email', 'tecnico.udig@gdsc.tl')->firstOrFail();

        $this->actingAs($officer)->withSession(['fiscal_year' => 2027]);
        Volt::test('pages.activities.list-activities')->assertDontSee('Activity of another directorate');
    }
}
