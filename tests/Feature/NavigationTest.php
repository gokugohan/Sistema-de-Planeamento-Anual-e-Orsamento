<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\Program;
use App\Models\User;
use App\Support\Navigation;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Samples\MtcSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class NavigationTest extends TestCase
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

    private function labels(User $u): array
    {
        return collect(Navigation::groups($u))->flatMap(fn ($g) => collect($g['items'])->flatMap(fn ($i) => $i['children'] ?? [$i]))->pluck('label')->all();
    }

    public function test_menu_shows_each_role_only_what_it_can_open(): void
    {
        $admin = $this->as('Admin');
        $minister = $this->as('Minister');
        $planner = $this->as('Planning');
        $viewer = $this->as('Viewer');

        $this->assertContains('Audit Log', $this->labels($admin));
        $this->assertContains('PAA Tech Review', $this->labels($admin));

        $this->assertContains('PAA Tech Review', $this->labels($minister));
        $this->assertNotContains('System Users', $this->labels($minister));
        $this->assertNotContains('Entities', $this->labels($minister));

        foreach ([$planner, $viewer] as $u) {
            $this->assertNotContains('PAA Tech Review', $this->labels($u));
            $this->assertNotContains('Audit Log', $this->labels($u));
            $this->assertNotContains('Settings', $this->labels($u));
        }
        foreach (['Budget Cycle', 'Budget Allocation', 'Reporting', 'Current Envelope'] as $wide) {
            $this->assertNotContains($wide, $this->labels($planner), "department users do not get $wide");
            $this->assertNotContains($wide, $this->labels($viewer), "department users do not get $wide");
            $this->assertContains($wide, $this->labels($minister));
        }
    }

    public function test_every_menu_entry_opens_for_the_user_who_sees_it(): void
    {
        foreach (['Admin', 'Minister', 'Planning', 'Viewer'] as $role) {
            $user = $this->as($role);

            foreach (Navigation::quickLinks($user) as $link) {
                $this->get($link['url'])->assertOk("{$role} sees “{$link['label']}” in the menu but {$link['url']} does not open");
            }
        }
    }

    public function test_pages_outside_the_menu_still_open(): void
    {
        $this->as('Admin');

        foreach (['notifications.index', 'profile', 'activities.create', 'planning.fiscal', 'reports.approved-plan'] as $route) {
            $this->get(route($route))->assertOk("{$route} does not open");
        }
    }

    public function test_breadcrumbs_say_where_you_are(): void
    {
        $this->as('Admin');

        $this->get(route('management.audit'))
            ->assertSee('aria-label="Breadcrumb"', false)
            ->assertSeeInOrder(['Dashboard', 'Administration', 'Audit Log']);

        $this->get(route('activities.create'))->assertSeeInOrder(['Planning', 'PAA Activities', 'New activity']);
        $this->get(route('budget.envelope.list'))->assertSeeInOrder(['Budget', 'Fiscal Envelope', 'Envelope List']);
    }

    public function test_sidebar_marks_the_current_page_for_assistive_technology(): void
    {
        $this->as('Admin');

        $html = $this->get(route('management.audit'))->getContent();

        $this->assertSame(1, preg_match_all('/<a [^>]*aria-current="page"[^>]*nav-item-active|<a [^>]*nav-item-active[^>]*aria-current="page"/', $html), 'exactly one sidebar link must be the current page');
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringContainsString('id="main-content"', $html);
        $this->assertStringContainsString('Skip to content', $html);
    }

    public function test_quick_jump_lists_only_pages_the_user_may_open(): void
    {
        $viewer = $this->as('Viewer');
        $html = $this->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('Go to a page', $html);
        $this->assertStringNotContainsString(route('management.users'), $html);
        $this->assertStringNotContainsString(route('reports.paa'), $html, 'department users are not offered ministry-wide reports');
        $this->assertStringContainsString(route('activities.index'), $html);
    }

    public function test_dashboard_tells_users_what_needs_their_attention(): void
    {
        $unit = Directorate::create(['code' => 'DNTT', 'name' => 'DNTT', 'type' => 'National Directorate', 'active' => true]);
        $program = Program::create(['oge_code' => '900', 'name' => 'P']);
        $this->as('Planning', $unit);

        $this->get(route('dashboard'))->assertSee('You are all caught up');

        foreach ([Activity::STATUS_DRAFT, Activity::STATUS_DRAFT] as $i => $status) {
            Activity::create(['fiscal_year' => 2027, 'program_id' => $program->id, 'directorate_id' => $unit->id, 'description' => "Draft {$i}",
                'quarter' => 1, 'priority' => 'Alta', 'planned_start' => '2027-01-01', 'planned_end' => '2027-03-31', 'status' => $status]);
        }

        $this->get(route('dashboard'))->assertSee('Needs your attention')->assertSee('2 draft activities not yet submitted');
    }

    public function test_approvers_see_the_work_waiting_for_them(): void
    {
        $unit = Directorate::create(['code' => 'DNTT', 'name' => 'DNTT', 'type' => 'National Directorate', 'active' => true]);
        $program = Program::create(['oge_code' => '900', 'name' => 'P']);
        Activity::create(['fiscal_year' => 2027, 'program_id' => $program->id, 'directorate_id' => $unit->id, 'description' => 'Waiting',
            'quarter' => 1, 'priority' => 'Alta', 'planned_start' => '2027-01-01', 'planned_end' => '2027-03-31', 'status' => Activity::STATUS_SUBMITTED]);
        $this->as('Minister');

        $this->get(route('dashboard'))->assertSee('1 activity waiting for technical review');
    }

    public function test_every_menu_entry_also_opens_with_data_in_the_system(): void
    {
        $this->seed(MtcSampleDataSeeder::class);
        $user = $this->as('Admin');

        foreach (Navigation::quickLinks($user) as $link) {
            $this->get($link['url'])->assertOk("{$link['url']} does not open with sample data loaded");
        }
        $this->get(route('activities.index', ['year' => 2027]))->assertOk();
    }

    public function test_the_old_registry_address_still_works_and_lands_on_the_merged_page(): void
    {
        $this->as('Admin');

        $this->get('/admin/entities')->assertRedirect('/management/entities');
        $this->assertContains('Entities', $this->labels(auth()->user()));
        $this->assertNotContains('Entities Registry', $this->labels(auth()->user()));
    }

    public function test_the_entities_page_shows_funding_for_units_and_programmes(): void
    {
        $this->seed(MtcSampleDataSeeder::class);
        $this->as('Admin');
        Activity::query()->update(['status' => 'Approved']);

        $this->get(route('management.entities'))->assertOk()->assertSee('Approved plan')->assertSee('Funded')->assertSee('DGT');
        $this->get(route('management.entities', ['search' => 'Transportes']))->assertOk();
        Volt::test('pages.management.entities')->set('activeTab', 'programs')->set('search', 'MTC-002')->assertSee('Transportes Terrestres');
    }
}
