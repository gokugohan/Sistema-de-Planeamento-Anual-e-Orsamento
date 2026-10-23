<?php

namespace Tests\Feature;

use App\Models\ApproverAssignment;
use App\Models\Directorate;
use App\Models\User;
use App\Services\UnitPeople;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** A unit shows its people; System Users can be filtered by unit and role, and says who has no unit. */
class UnitPeopleTest extends TestCase
{
    use RefreshDatabase;

    private Directorate $root;

    private Directorate $child;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->root = Directorate::create(['code' => 'ROOT', 'name' => 'The Directorate', 'type' => 'National Directorate', 'active' => true]);
        $this->child = Directorate::create(['code' => 'KID', 'name' => 'The Unit Below', 'type' => 'Department', 'parent_id' => $this->root->id, 'active' => true]);
    }

    private function person(string $name, string $role, ?Directorate $unit): User
    {
        $u = User::factory()->create(['name' => $name, 'directorate_id' => $unit?->id]);
        $u->assignRole($role);

        return $u;
    }

    private function admin(): User
    {
        return $this->person('Admin Boss', 'Admin', null);
    }

    public function test_the_unit_page_lists_its_people_with_their_role_and_the_steps_they_are_named_for(): void
    {
        $director = $this->person('Dina Director', 'Director', $this->root);
        $officer = $this->person('Tomas Technician', 'Planning', $this->root);
        $this->person('Someone Elsewhere', 'Planning', $this->child);
        ApproverAssignment::create(['directorate_id' => $this->root->id, 'user_id' => $officer->id, 'step' => 'prepare', 'position' => 1]);

        Volt::actingAs($director)->test('pages.work.unit', ['directorate' => $this->root])
            ->assertSee('People of this unit')->assertSee('Dina Director')->assertSee('Tomas Technician')->assertSee('Prepares')
            ->assertDontSee('Someone Elsewhere');
    }

    public function test_emails_are_for_the_director_of_the_unit_and_administrators_only(): void
    {
        $director = $this->person('Dina Director', 'Director', $this->root);
        $officer = $this->person('Tomas Technician', 'Planning', $this->root);
        $officer->update(['email' => 'tomas@example.test']);

        Volt::actingAs($director)->test('pages.work.unit', ['directorate' => $this->root])->assertSee('tomas@example.test');
        Volt::actingAs($this->admin())->test('pages.work.unit', ['directorate' => $this->root])->assertSee('tomas@example.test')->assertSee('Add a person to this unit');
        Volt::actingAs($officer)->test('pages.work.unit', ['directorate' => $this->root])->assertDontSee('tomas@example.test')->assertDontSee('Add a person to this unit');
    }

    public function test_a_unit_without_a_director_says_who_directs_it_from_above(): void
    {
        $this->person('Dina Director', 'Director', $this->root);
        $this->person('Kid Officer', 'Planning', $this->child);

        Volt::actingAs($this->admin())->test('pages.work.unit', ['directorate' => $this->child])
            ->assertSee('This unit has no Director of its own.')->assertSee('directed from ROOT')->assertSee('Dina Director');
    }

    public function test_the_users_list_filters_by_unit_with_the_units_below_and_by_role(): void
    {
        $this->person('Dina Director', 'Director', $this->root);
        $this->person('Kid Officer', 'Planning', $this->child);
        $this->person('Lost Soul', 'Planning', null);
        $admin = $this->admin();

        $page = fn () => Volt::actingAs($admin)->test('pages.management.users.user-management');

        $page()->set('unitFilter', (string) $this->root->id)->assertSee('Dina Director')->assertSee('Kid Officer')->assertDontSee('Lost Soul');
        $page()->set('unitFilter', (string) $this->child->id)->assertSee('Kid Officer')->assertDontSee('Dina Director');
        $page()->set('unitFilter', 'none')->assertSee('Lost Soul')->assertDontSee('Kid Officer');
        $page()->set('unitFilter', (string) $this->root->id)->assertSeeHtml(route('units.show', $this->root->id));
        $page()->set('roleFilter', 'Director')->assertSee('Dina Director')->assertDontSee('Kid Officer');
    }

    public function test_it_warns_about_directors_and_officers_without_a_unit(): void
    {
        $this->person('Lost Soul', 'Planning', null);

        Volt::actingAs($this->admin())->test('pages.management.users.user-management')->assertSee('has no unit and cannot see any activity');
    }

    public function test_the_unit_page_link_opens_the_form_for_a_new_user_in_that_unit(): void
    {
        Livewire::withQueryParams(['unit' => $this->root->id, 'new' => 1]);
        Volt::actingAs($this->admin())->test('pages.management.users.user-management')
            ->assertSet('modalOpen', true)->assertSet('directorate_id', $this->root->id);
    }

    public function test_roles_and_permissions_is_one_tab_and_the_users_tab_does_not_load_the_matrix(): void
    {
        $page = Volt::actingAs($this->admin())->test('pages.management.users.user-management');

        $page->assertSee('Roles and permissions')->assertDontSee('Policy Matrix')->assertDontSee('Capability Matrix');
        $this->assertCount(0, $page->viewData('roles'), 'the matrix data is not loaded on the users tab');

        $page->call('setTab', 'roles')->assertSee('Switch a permission on or off');
        $this->assertGreaterThan(0, $page->viewData('roles')->count());
    }

    public function test_the_people_of_a_unit_are_cached_and_renewed_when_someone_changes(): void
    {
        $this->person('First Officer', 'Planning', $this->child);
        $viewer = $this->admin();
        $service = app(UnitPeople::class);

        $this->assertCount(1, $service->for($this->child, $viewer)['members']);

        DB::enableQueryLog();
        $service->for($this->child, $viewer);
        $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'directorate_id'))->count();
        DB::disableQueryLog();
        $this->assertSame(0, $queries, 'the second look comes from the cache');

        $this->person('Second Officer', 'Planning', $this->child);
        $this->assertCount(2, $service->for($this->child, $viewer)['members'], 'a new person renews it');
    }
}
