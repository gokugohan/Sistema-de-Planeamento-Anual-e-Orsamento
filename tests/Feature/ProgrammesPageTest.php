<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\Program;
use App\Models\Subprogram;
use App\Models\User;
use App\Services\PlanningWindow;
use App\Services\ProgrammeOverview;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** Plan → Programmes: programmes and sub-programmes with activities and amounts, for the units the user works in. */
#[Group('office')]
class ProgrammesPageTest extends TestCase
{
    use RefreshDatabase;

    private Program $program;

    private Subprogram $sub;

    protected function setUp(): void
    {
        parent::setUp();
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        app()->setLocale('en');
        session(['fiscal_year' => 2027]);
        $this->program = Program::create(['oge_code' => 'ZZ1', 'name' => 'Programme of the other directorate', 'fiscal_year' => 2027]);
        $this->sub = Subprogram::create(['program_id' => $this->program->id, 'code' => 'ZZ1.1', 'name' => 'Sub of the other directorate']);
        $other = Directorate::create(['code' => 'XOTHER', 'name' => 'Other Directorate', 'type' => 'National Directorate', 'active' => true]);
        $a = Activity::create([
            'fiscal_year' => 2027, 'program_id' => $this->program->id, 'subprogram_id' => $this->sub->id, 'directorate_id' => $other->id, 'code' => 'XOTHER-27-001',
            'description' => 'Activity of another directorate', 'quarter' => 1, 'priority' => 'Alta', 'planned_start' => '2027-01-01', 'planned_end' => '2027-12-31', 'status' => Activity::STATUS_SUBMITTED,
        ]);
        $a->lineItems()->create(['code' => 'L1', 'economic_classification_id' => EconomicClassification::firstOrFail()->id, 'estimated_amount' => 12345]);
        PlanningWindow::forgetTree();
    }

    public function test_an_administrator_sees_every_programme_with_its_subprogrammes_and_amounts(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        Volt::actingAs($admin)->test('pages.planning.programmes')
            ->assertSee('Programme of the other directorate')->assertSee('ZZ1.1')->assertSee('12,345')->assertSee('Edit programmes');
    }

    public function test_a_directorate_user_sees_only_programmes_with_activities_in_their_units(): void
    {
        $director = User::where('email', 'director.udig@gdsc.tl')->firstOrFail();

        $page = Volt::actingAs($director)->test('pages.planning.programmes');

        $page->assertDontSee('Programme of the other directorate')->assertDontSee('12,345')->assertDontSee('Edit programmes');
        $data = app(ProgrammeOverview::class)->build($director, 2027);
        $this->assertTrue($data['scoped']);
        $this->assertNotEmpty($data['programmes'], 'their own programmes are listed');
        $this->assertSame([], array_values(array_filter($data['programmes'], fn ($p) => $p['code'] === 'ZZ1')));
    }

    public function test_the_menu_has_programmes_under_plan_and_the_administration_menu_is_grouped(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin)->get(route('planning.programmes'))->assertOk()
            ->assertSeeInOrder(['Plan', 'Programmes', 'Plan cycle'])->assertSeeInOrder(['Administration', 'System Users', 'Structure', 'System', 'Settings', 'Optional rules']);

        $officer = User::where('email', 'tecnico.udig@gdsc.tl')->firstOrFail();
        $this->actingAs($officer)->get(route('planning.programmes'))->assertOk();
    }
}
