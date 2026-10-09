<?php

namespace Tests\Feature\Auth;

use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class LoginScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    public function test_it_has_two_panes_the_system_and_the_form(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->get('/login')
            ->assertOk()
            ->assertSee('About this system')                       // pane 1: what and who
            ->assertSee('The annual cycle')
            ->assertSee('Every change is audited')
            ->assertSee('Use your institutional account to continue.') // pane 2: the task
            ->assertSee('Forgot your password?')
            ->assertSee('Contact your system administrator');
    }

    public function test_it_shows_the_configured_institution_not_a_hard_coded_one(): void
    {
        $this->seed(DatabaseSeeder::class);
        SystemSetting::set('org_name', ['en' => 'Ministry of Public Works'], 'json');
        SystemSetting::set('app_tagline', ['en' => 'Plan well, spend well'], 'json');

        $this->get('/login')->assertSee('Ministry of Public Works')->assertSee('Plan well, spend well');
    }

    public function test_the_form_is_accessible(): void
    {
        $html = $this->get('/login')->getContent();

        $this->assertStringContainsString('for="email"', $html);
        $this->assertStringContainsString('for="password"', $html);
        $this->assertStringContainsString('autocomplete="username"', $html);
        $this->assertStringContainsString('autocomplete="current-password"', $html);
        $this->assertStringContainsString('Skip to content', $html);
        $this->assertStringContainsString('<main id="auth-main"', $html);
        $this->assertStringContainsString('aria-label="Language"', $html);
    }

    public function test_a_wrong_password_shows_one_announced_error_and_does_not_sign_in(): void
    {
        $user = User::factory()->create();

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'wrong-password')
            ->call('login')
            ->assertHasErrors('form.email')
            ->assertSee('role="alert"', false);

        $this->assertGuest();
    }

    public function test_repeated_failures_are_throttled(): void
    {
        $user = User::factory()->create();
        $component = Volt::test('pages.auth.login')->set('form.email', $user->email)->set('form.password', 'wrong');

        foreach (range(1, 6) as $i) {
            $component->call('login');
        }

        $this->assertStringContainsString('Too many', implode(' ', $component->errors()->get('form.email')));
    }

    public function test_the_other_sign_in_screens_use_the_same_layout_and_heading(): void
    {
        $this->get('/forgot-password')->assertOk()->assertSee('Reset your password')->assertSee('About this system');
    }
}
