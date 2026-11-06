<?php

namespace Tests\Feature;

use App\Models\Directorate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every head holds one role (Director, shown as "Head"); what they are called comes from the level of their unit.
 */
class HeadTitleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        app()->setLocale('en');
    }

    private function headOf(string $type, string $code): User
    {
        $unit = Directorate::create(['code' => $code, 'name' => $code, 'type' => $type, 'active' => true]);
        $user = User::factory()->create(['directorate_id' => $unit->id]);
        $user->assignRole('Director');

        return $user->fresh();
    }

    public function test_a_head_is_called_by_the_level_of_their_unit(): void
    {
        $this->assertSame('Head of Department', $this->headOf('Department', 'DEP')->roleTitle());
        $this->assertSame('National Director', $this->headOf('National Directorate', 'DN')->roleTitle());
        $this->assertSame('Director-General', $this->headOf('General Directorate', 'DG')->roleTitle());
        $this->assertSame('Head of Office', $this->headOf('Cabinet', 'GAB')->roleTitle());
        $this->assertSame('Head of Unit', $this->headOf('Unit', 'UN')->roleTitle());
    }

    public function test_an_autonomous_body_is_led_by_its_president(): void
    {
        $head = $this->headOf('Independent', 'IP');
        $head->directorate->forceFill(['administration' => Directorate::INDIRECT])->save();
        $this->assertSame('President', $head->fresh()->roleTitle());
        $this->assertSame('Head of Office', $this->headOf('Independent', 'GX')->roleTitle());
    }

    public function test_the_users_list_shows_the_title(): void
    {
        $this->headOf('Unit', 'UDX');
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $this->actingAs($admin)->get(route('management.users'))->assertOk()->assertSee('Head of Unit');
    }

    public function test_other_roles_keep_their_own_name(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');
        $this->assertSame(__(\Spatie\Permission\Models\Role::findByName('Admin')->display_name ?: 'Admin'), $user->fresh()->roleTitle());
        $this->assertSame('System User', User::factory()->create()->roleTitle());
    }

    public function test_the_top_bar_shows_the_title(): void
    {
        $head = $this->headOf('General Directorate', 'DGT');

        $this->actingAs($head)->get(route('profile'))->assertOk()->assertSee('Director-General');
    }
}
