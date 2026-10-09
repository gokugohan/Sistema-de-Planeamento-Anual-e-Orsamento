<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_registration_is_off_by_default(): void
    {
        $this->assertFalse(config('auth.allow_registration'));

        $this->get('/register')->assertNotFound();
        $this->assertFalse(Route::has('register'));
    }

    public function test_the_login_screen_does_not_offer_registration_when_it_is_off(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('Create an account');
    }

    /** The component itself still works; it is only unreachable until an administrator opts in. */
    public function test_registration_component_creates_an_account_without_any_access(): void
    {
        $component = Volt::test('pages.auth.register')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password');

        $component->call('register');

        $component->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticated();
        $this->assertCount(0, auth()->user()->getRoleNames(), 'a self-registered user must start with no role');
    }
}
