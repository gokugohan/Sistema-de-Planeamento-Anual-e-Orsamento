<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\BudgetExecution;
use App\Models\Directorate;
use App\Models\User;
use App\Support\Modules;
use App\Support\Navigation;
use App\Support\PlanOptions;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Samples\MtcSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The add-on modules (execution reporting, performance, the budget envelope): off on a new installation, switched on
 * in Settings > Modules, and when off hidden everywhere and refused by address, while their data stays.
 */
class ModulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app()->setLocale('en');
    }

    private function as(string $role, ?Directorate $unit = null): User
    {
        $u = User::factory()->create(['directorate_id' => $unit?->id]);
        $u->assignRole($role);
        $this->actingAs($u);

        return $u;
    }

    private function routes(User $u): array
    {
        return collect(Navigation::groups($u))->flatMap(fn ($g) => $g['items'])->pluck('route')->all();
    }

    private function off(): void
    {
        Modules::fake([Modules::EXECUTION => false, Modules::PERFORMANCE => false]);
    }

    public function test_a_new_installation_has_every_module_off(): void
    {
        Modules::fake(null);

        $this->assertSame([Modules::EXECUTION => false, Modules::PERFORMANCE => false, Modules::BUDGET => false], Modules::states());
    }

    public function test_the_administrator_switches_modules_on_and_performance_needs_execution(): void
    {
        Modules::fake(null);
        $this->as('Admin');

        Volt::test('pages.management.modules')->set('performance', true)->call('save');
        $this->assertFalse(Modules::on(Modules::PERFORMANCE), 'performance without execution has nothing to show');

        Volt::test('pages.management.modules')->set('execution', true)->set('performance', true)->set('budget', true)->call('save');
        $this->assertSame([Modules::EXECUTION => true, Modules::PERFORMANCE => true, Modules::BUDGET => true], Modules::states());
        $this->assertSame('record', PlanOptions::current()->envelopeUse(), 'the budget envelope switch is the envelope rule');

        // switching execution off takes performance with it, in the form and in what is saved
        Volt::test('pages.management.modules')->set('execution', false)->assertSet('performance', false)->call('save');
        $this->assertSame([Modules::EXECUTION => false, Modules::PERFORMANCE => false, Modules::BUDGET => true], Modules::states());
    }

    public function test_switching_the_budget_envelope_on_keeps_a_distribution_already_chosen(): void
    {
        Modules::fake(null);
        PlanOptions::current()->withEnvelope('share')->save();

        Modules::save(true, false, true);

        $this->assertSame('share', PlanOptions::current()->envelopeUse());
        Modules::save(true, false, false);
        $this->assertSame('off', PlanOptions::current()->envelopeUse());
    }

    public function test_only_the_administrator_opens_the_modules_page(): void
    {
        $this->as('Director');
        $this->get(route('management.modules'))->assertForbidden();

        $this->as('Admin');
        $this->get(route('management.modules'))->assertOk()->assertSee('Execution reporting')->assertSee('Performance insights')->assertSee('Budget envelope');
    }

    public function test_a_module_that_is_off_leaves_the_menu_and_its_pages_are_not_found(): void
    {
        $admin = $this->as('Admin');
        $this->assertContains('monitoring.report', $this->routes($admin));
        $this->assertContains('monitoring.index', $this->routes($admin));

        $this->off();
        $routes = $this->routes($admin);
        foreach (['monitoring.report', 'reports.compile', 'monitoring.index'] as $route) {
            $this->assertNotContains($route, $routes, "$route is still in the menu");
        }
        // the steps are numbered as shown: Plan 1, Approvals 2, Insights 3
        $this->assertSame([1, 2, 3], collect(Navigation::groups($admin))->pluck('step')->filter()->values()->all());

        foreach (['monitoring.report', 'monitoring.approval', 'reports.compile', 'monitoring.index', 'reports.performance'] as $route) {
            $this->get(route($route))->assertNotFound();
        }
    }

    public function test_performance_alone_can_be_switched_off(): void
    {
        $this->as('Admin');
        Modules::fake([Modules::EXECUTION => true, Modules::PERFORMANCE => false]);

        $this->get(route('monitoring.report'))->assertOk();
        $this->get(route('monitoring.index'))->assertNotFound();
        $this->get(route('reports.performance'))->assertNotFound();
    }

    public function test_the_components_of_a_module_refuse_to_mount_and_to_act_once_it_is_off(): void
    {
        $this->as('Admin');
        $component = Volt::test('pages.monitoring.report-execution');

        $this->off();
        // a later action is a request of its own: Livewire answers it with the status
        $component->call('$refresh')->assertStatus(404);
        foreach (['pages.monitoring.report-execution', 'pages.reports.compile-quarter', 'pages.monitoring.execution-dashboard'] as $name) {
            Volt::test($name)->assertStatus(404);
        }
    }

    public function test_with_the_modules_off_the_shared_pages_show_no_reporting_and_still_open(): void
    {
        $this->seed(MtcSampleDataSeeder::class);
        $this->off();
        $activity = Activity::where('status', Activity::STATUS_APPROVED)->where('version', '>', 0)->firstOrFail();
        $director = User::role('Director')->whereNotNull('directorate_id')->firstOrFail();

        $admin = $this->as('Admin');
        foreach (Navigation::quickLinks($admin) as $link) {
            $this->get($link['url'])->assertOk("{$link['url']} does not open with the modules off");
        }
        foreach ([route('planning.cycle'), route('guide'), route('management.reporting-windows'), route('activities.show', $activity), route('approvals.index')] as $url) {
            $this->get($url)->assertOk()->assertDontSee('Report execution');
        }
        $this->followingRedirects()->get(route('dashboard'))->assertOk()->assertDontSee('Delivery against targets')->assertDontSee('Budget execution');

        $this->actingAs($director);
        $this->followingRedirects()->get(route('work.home'))->assertOk()->assertDontSee('Report execution');
    }

    public function test_the_guide_follows_the_modules(): void
    {
        $this->as('Admin');
        $this->get(route('guide'))->assertOk()->assertSee('Choose the modules')
            ->assertSee('for reporting each month and quarter')->assertSee('Report the executed amount');

        $this->off();
        $this->get(route('guide'))->assertOk()->assertSee('Choose the modules')
            ->assertDontSee('for reporting each month and quarter')->assertDontSee('Report the executed amount')
            ->assertDontSee('the executed amount is reported against it');
    }

    public function test_switching_a_module_off_keeps_what_was_recorded(): void
    {
        $this->seed(MtcSampleDataSeeder::class);
        $before = BudgetExecution::count();

        $this->off();
        $this->assertSame($before, BudgetExecution::count());
    }

    public function test_loading_the_sample_data_switches_the_modules_on(): void
    {
        Modules::fake(null);
        $this->seed(MtcSampleDataSeeder::class);

        $this->assertSame([Modules::EXECUTION => true, Modules::PERFORMANCE => true, Modules::BUDGET => true], Modules::states());
    }
}
