<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Manuals;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The Manual page: everyone reads the user manual; only administrators see and open the administrator manual. */
class ManualTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        app()->setLocale('en');
    }

    private function userWith(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_both_manuals_are_built(): void
    {
        foreach (array_keys(Manuals::all()) as $key) {
            $this->assertFileExists(Manuals::path($key));
            $this->assertStringStartsWith('%PDF', file_get_contents(Manuals::path($key), length: 4));
        }
    }

    public function test_a_user_reads_the_user_manual_only(): void
    {
        $viewer = $this->userWith('Viewer');

        $this->actingAs($viewer)->get(route('manual'))->assertOk()
            ->assertSee('User manual')->assertDontSee('Administrator manual')
            ->assertSee(route('manual.file', 'user'), false);
        $this->actingAs($viewer)->get(route('manual.file', 'user'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($viewer)->get(route('manual.file', 'admin'))->assertForbidden();
        $this->actingAs($viewer)->get(route('manual.file', 'other'))->assertNotFound();
    }

    public function test_an_administrator_reads_both_and_can_download(): void
    {
        $admin = $this->userWith('Admin');

        $this->actingAs($admin)->get(route('manual', ['m' => 'admin']))->assertOk()
            ->assertSee('User manual')->assertSee('Administrator manual')
            ->assertSee('src="'.route('manual.file', 'admin').'"', false);
        $this->actingAs($admin)->get(route('manual.file', ['manual' => 'admin', 'download' => 1]))->assertOk()
            ->assertDownload('administrator-manual.pdf');
    }

    public function test_the_manuals_need_a_signed_in_person(): void
    {
        $this->get(route('manual'))->assertRedirect(route('login'));
        $this->get(route('manual.file', 'user'))->assertRedirect(route('login'));
    }
}
