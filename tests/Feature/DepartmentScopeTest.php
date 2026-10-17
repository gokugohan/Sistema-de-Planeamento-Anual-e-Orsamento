<?php

namespace Tests\Feature;

use App\Models\Directorate;
use App\Models\User;
use App\Support\Navigation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Department users work on their own unit's data; the dashboard is the only ministry-wide page they open. */
class DepartmentScopeTest extends TestCase
{
    use RefreshDatabase;

    private const MINISTRY_WIDE = ['reports.paa'];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->seed(DatabaseSeeder::class);
    }

    private function as(string $role): User
    {
        $unit = Directorate::create(['name' => 'Unit '.uniqid(), 'code' => strtoupper(substr(uniqid(), -6))]);
        $u = User::factory()->create(['directorate_id' => $unit->id]);
        $u->assignRole($role);
        $this->actingAs($u);

        return $u;
    }

    public function test_department_roles_can_open_the_dashboard_and_their_own_pages_only(): void
    {
        foreach (['Director', 'Planning', 'Viewer'] as $role) {
            $user = $this->as($role);

            $this->get(route('dashboard'))->assertOk();
            $this->get(route('activities.index'))->assertOk();
            $this->get(route('monitoring.index'))->assertOk();
            foreach (self::MINISTRY_WIDE as $route) {
                $this->get(route($route))->assertForbidden();
            }
            $this->get(route('reports.approved-plan'))->assertForbidden();

            $routes = collect(Navigation::quickLinks($user))->pluck('url')->implode(' ');
            foreach (self::MINISTRY_WIDE as $route) {
                $this->assertStringNotContainsString(route($route, absolute: false), $routes, "$role is offered $route in the menu");
            }
        }
    }

    public function test_the_ministry_level_still_sees_everything(): void
    {
        foreach (['Admin', 'Minister'] as $role) {
            $this->as($role);
            foreach (self::MINISTRY_WIDE as $route) {
                $this->get(route($route))->assertOk();
            }
        }
    }
}
