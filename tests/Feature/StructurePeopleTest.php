<?php

namespace Tests\Feature;

use App\Models\Directorate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** The Structure page says how many people each unit has, who directs it, and which units nobody directs. */
class StructurePeopleTest extends TestCase
{
    use RefreshDatabase;

    private Directorate $root;

    private Directorate $child;

    private Directorate $orphan;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->root = Directorate::create(['code' => 'ROOT', 'name' => 'The Directorate', 'type' => 'General Directorate', 'active' => true]);
        $this->child = Directorate::create(['code' => 'KID', 'name' => 'The Department', 'type' => 'Department', 'parent_id' => $this->root->id, 'active' => true]);
        $this->orphan = Directorate::create(['code' => 'ALONE', 'name' => 'A Lonely Cabinet', 'type' => 'Cabinet', 'active' => true]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        $director = User::factory()->create(['name' => 'Dina Director', 'directorate_id' => $this->root->id]);
        $director->assignRole('Director');
        User::factory()->create(['directorate_id' => $this->child->id])->assignRole('Planning');
    }

    private function page()
    {
        return Volt::actingAs($this->admin)->test('pages.management.entities');
    }

    public function test_a_unit_shows_its_director_and_how_many_people_it_has(): void
    {
        $page = $this->page();

        $page->assertSee('Dina Director')->assertSeeHtml(route('management.users', ['unit' => $this->root->id]));
        $this->assertSame(1, $page->viewData('peopleByUnit')[$this->root->id]);
        $this->assertSame(1, $page->viewData('peopleByUnit')[$this->child->id]);
    }

    public function test_a_unit_without_a_director_says_where_it_is_directed_from(): void
    {
        $page = $this->page();

        $page->assertSee('Directed from ROOT');
        $this->assertSame('ROOT', $page->viewData('directedFrom')[$this->child->id]);
    }

    public function test_units_that_nobody_directs_are_flagged_with_a_warning(): void
    {
        $page = $this->page();

        $page->assertSee('No Director')->assertSee('nobody can give the final approval')->assertSee('ALONE');
        $this->assertSame(['ALONE'], $page->viewData('nobodyDirects'));
    }

    public function test_the_lists_come_from_two_grouped_queries_and_the_cache(): void
    {
        $this->page();

        DB::enableQueryLog();
        $this->page();
        $users = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'model_has_roles') || str_contains($q['query'], 'COUNT(*) AS n'))->count();
        DB::disableQueryLog();

        $this->assertSame(0, $users, 'the people of the units come from the cache on the second visit');

        User::factory()->create(['directorate_id' => $this->orphan->id])->assignRole('Director');
        $this->assertSame([], $this->page()->viewData('nobodyDirects'), 'a new Director renews it');
    }
}
