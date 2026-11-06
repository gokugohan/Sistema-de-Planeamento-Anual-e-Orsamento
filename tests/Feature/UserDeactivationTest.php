<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * An administrator deactivates an account instead of deleting it; a lost administrator password is recovered from
 * the server with paa:reset-password.
 */
class UserDeactivationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        app()->setLocale('en');
        $this->admin = User::factory()->create(['email' => 'admin@example.test']);
        $this->admin->assignRole('Admin');
    }

    private function officer(): User
    {
        $user = User::factory()->create(['email' => 'officer@example.test', 'password' => Hash::make('secret-pass-1')]);
        $user->assignRole('Planning');

        return $user;
    }

    public function test_an_administrator_deactivates_and_reactivates_an_account(): void
    {
        $user = $this->officer();

        Volt::actingAs($this->admin)->test('pages.management.users.user-management')->call('toggleActive', $user->id);
        $this->assertNotNull($user->fresh()->deactivated_at);
        $this->assertDatabaseHas('users', ['id' => $user->id], ); // the history stays: the account is not deleted

        Volt::actingAs($this->admin)->test('pages.management.users.user-management')->call('toggleActive', $user->id);
        $this->assertNull($user->fresh()->deactivated_at);
    }

    public function test_only_an_administrator_may_deactivate(): void
    {
        $user = $this->officer();
        $other = User::factory()->create();
        $other->assignRole('Planning');

        Volt::actingAs($other)->test('pages.management.users.user-management')->call('toggleActive', $user->id)->assertForbidden();
        $this->assertNull($user->fresh()->deactivated_at);
    }

    public function test_ones_own_account_stays_active_so_an_administrator_always_remains(): void
    {
        $second = User::factory()->create();
        $second->assignRole('Admin');

        Volt::actingAs($this->admin)->test('pages.management.users.user-management')->call('toggleActive', $this->admin->id);
        $this->assertNull($this->admin->fresh()->deactivated_at, 'not ones own account');

        Volt::actingAs($this->admin)->test('pages.management.users.user-management')->call('toggleActive', $second->id);
        $this->assertNotNull($second->fresh()->deactivated_at, 'another administrator can be deactivated while one stays');
    }

    public function test_a_deactivated_account_cannot_sign_in(): void
    {
        $user = $this->officer();
        $user->forceFill(['deactivated_at' => now()])->save();

        Volt::test('pages.auth.login')
            ->set('form.email', 'officer@example.test')->set('form.password', 'secret-pass-1')
            ->call('login')
            ->assertHasErrors('form.email')
            ->assertSee('Your account is deactivated');
        $this->assertGuest();
    }

    public function test_an_open_session_is_signed_out_once_deactivated(): void
    {
        $user = $this->officer();
        $this->actingAs($user)->get(route('profile'))->assertOk();

        $user->forceFill(['deactivated_at' => now()])->save();
        $this->get(route('profile'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_list_can_show_only_the_deactivated_accounts(): void
    {
        $user = $this->officer();
        $user->forceFill(['deactivated_at' => now()])->save();

        Volt::actingAs($this->admin)->test('pages.management.users.user-management')
            ->set('statusFilter', 'deactivated')
            ->assertSee('officer@example.test')->assertDontSee('admin@example.test')->assertSee('Deactivated');
    }

    public function test_a_lost_administrator_password_is_recovered_from_the_server(): void
    {
        $this->admin->forceFill(['deactivated_at' => now()])->save();

        $this->artisan('paa:reset-password', ['email' => 'admin@example.test', '--reactivate' => true])
            ->expectsOutputToContain('Temporary password for admin@example.test')
            ->assertSuccessful();

        $admin = $this->admin->fresh();
        $this->assertTrue($admin->must_change_password, 'the temporary password must be changed at the first sign-in');
        $this->assertTrue($admin->isActive());

        $this->artisan('paa:reset-password', ['email' => 'nobody@example.test'])
            ->expectsOutputToContain('admin@example.test')
            ->assertFailed();
    }
}
