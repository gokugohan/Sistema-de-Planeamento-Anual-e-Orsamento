<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\TwoFactor;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private Google2FA $totp;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->seed(DatabaseSeeder::class);
        $this->totp = new Google2FA;
    }

    private function user(string $role = 'Viewer'): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    /** Enrol $user the way the profile page does; returns [secret, recovery codes]. */
    private function enrol(User $user): array
    {
        $this->actingAs($user);
        $svc = app(TwoFactor::class);
        $secret = $svc->beginSetup();
        $codes = $svc->confirmSetup($user, $this->totp->getCurrentOtp($secret));
        $this->assertNotNull($codes, 'enrolment with a valid code failed');
        session()->forget(TwoFactor::SESSION_PASSED); // as if this were a later sign-in

        return [$secret, $codes];
    }

    private function nextCode(string $secret): string
    {
        // A code for the following 30 s step: still inside the accepted window, and newer than any used one.
        return $this->totp->oathTotp($secret, $this->totp->getTimestamp() + 1);
    }

    public function test_enrolment_needs_a_correct_code_and_stores_nothing_readable(): void
    {
        $user = $this->user();
        $this->actingAs($user);

        $page = Volt::test('profile.two-factor-authentication-form')->call('startSetup');
        $secret = app(TwoFactor::class)->pendingSecret();
        $this->assertNotEmpty($secret);

        $page->set('code', '000000')->call('confirmSetup')->assertHasErrors('code');
        $this->assertFalse(app(TwoFactor::class)->isEnabled($user->fresh()));

        $page->set('code', $this->totp->getCurrentOtp($secret))->call('confirmSetup')->assertHasNoErrors();
        $codes = $page->get('recoveryCodes');

        $user->refresh();
        $this->assertTrue(app(TwoFactor::class)->isEnabled($user));
        $this->assertCount(8, $codes);

        // At rest: encrypted secret, hashed recovery codes.
        $raw = DB::table('users')->where('id', $user->id)->first();
        $this->assertStringNotContainsString($secret, $raw->two_factor_secret);
        foreach ($codes as $plain) {
            $this->assertStringNotContainsString($plain, (string) $raw->two_factor_recovery_codes);
        }
        $this->assertArrayNotHasKey('two_factor_secret', $user->toArray());
    }

    public function test_a_user_with_two_factor_must_pass_the_challenge_before_anything_else(): void
    {
        [$secret] = $this->enrol($this->user());

        $this->get(route('dashboard'))->assertRedirect(route('two-factor.challenge'));
        $this->get(route('reports.paa'))->assertRedirect(route('two-factor.challenge'));
        $this->get(route('two-factor.challenge'))->assertOk();

        Volt::test('pages.auth.two-factor-challenge')->set('code', '000000')->call('verify')->assertHasErrors('code');
        $this->get(route('dashboard'))->assertRedirect(route('two-factor.challenge'));

        Volt::test('pages.auth.two-factor-challenge')->set('code', $this->totp->getCurrentOtp($secret))->call('verify')->assertHasNoErrors();
        $this->get(route('work.home'))->assertOk();
    }

    public function test_a_code_can_be_used_only_once(): void
    {
        [$secret] = $this->enrol($this->user());
        $svc = app(TwoFactor::class);
        $user = auth()->user();

        $code = $this->nextCode($secret);
        $this->assertTrue($svc->verify($user->fresh(), $code));
        $this->assertFalse($svc->verify($user->fresh(), $code), 'a replayed code was accepted');
        $this->assertFalse($svc->verify($user->fresh(), $this->totp->getCurrentOtp($secret)), 'an older step was accepted after a newer one');
    }

    public function test_recovery_codes_work_once_each(): void
    {
        [, $codes] = $this->enrol($this->user());
        $svc = app(TwoFactor::class);
        $user = auth()->user();

        $this->assertTrue($svc->useRecoveryCode($user->fresh(), $codes[0]));
        $this->assertFalse($svc->useRecoveryCode($user->fresh(), $codes[0]), 'a recovery code was reused');
        $this->assertSame(7, $svc->remainingRecoveryCodes($user->fresh()));
        $this->assertTrue($svc->useRecoveryCode($user->fresh(), strtoupper($codes[1])), 'codes should not be case sensitive');
        $this->assertDatabaseHas('audit_logs', ['action' => 'two_factor.recovery_code_used']);
    }

    public function test_the_challenge_accepts_a_recovery_code(): void
    {
        [, $codes] = $this->enrol($this->user());

        Volt::test('pages.auth.two-factor-challenge')->call('toggleRecovery')->set('code', $codes[0])->call('verify')->assertHasNoErrors();

        $this->get(route('work.home'))->assertOk();
    }

    public function test_repeated_wrong_codes_are_locked_out(): void
    {
        [$secret] = $this->enrol($this->user());
        $page = Volt::test('pages.auth.two-factor-challenge');

        foreach (range(1, 5) as $i) {
            $page->set('code', '000000')->call('verify');
        }
        // Even the right code is refused while locked out.
        $page->set('code', $this->totp->getCurrentOtp($secret))->call('verify')->assertHasErrors('code');

        $this->assertStringContainsString('Too many attempts', $page->errors()->first('code'));
        $this->get(route('dashboard'))->assertRedirect(route('two-factor.challenge'));
    }

    public function test_required_roles_must_set_it_up_and_cannot_turn_it_off(): void
    {
        config(['auth.two_factor.required_roles' => ['Admin']]);
        $admin = User::where('email', 'admin@example.org')->first();
        $this->actingAs($admin);

        $this->get(route('dashboard'))->assertRedirect(route('profile'));
        $this->get(route('profile'))->assertOk()->assertSee('Two-factor authentication');

        // A non-required role is not forced.
        $this->actingAs($this->user('Viewer'));
        $this->get(route('work.home'))->assertOk();

        // Once enrolled, a required role cannot disable it.
        $this->actingAs($admin);
        $this->enrol($admin);
        session([TwoFactor::SESSION_PASSED => true]);
        Volt::test('profile.two-factor-authentication-form')->set('password', 'password')->call('disable')->assertForbidden();
        $this->assertTrue(app(TwoFactor::class)->isEnabled($admin->fresh()));
    }

    public function test_turning_it_off_needs_the_password(): void
    {
        $user = $this->user();
        $this->enrol($user);
        session([TwoFactor::SESSION_PASSED => true]);

        Volt::test('profile.two-factor-authentication-form')->set('password', 'wrong')->call('disable')->assertHasErrors('password');
        $this->assertTrue(app(TwoFactor::class)->isEnabled($user->fresh()));

        Volt::test('profile.two-factor-authentication-form')->set('password', 'password')->call('disable')->assertHasNoErrors();
        $this->assertFalse(app(TwoFactor::class)->isEnabled($user->fresh()));
        $this->assertDatabaseHas('audit_logs', ['action' => 'two_factor.disabled']);
    }

    public function test_an_administrator_can_reset_a_colleagues_two_factor(): void
    {
        $colleague = $this->user('Planning');
        $this->enrol($colleague);

        $this->actingAs(User::where('email', 'admin@example.org')->first());
        Volt::test('pages.management.users.user-management')->call('resetTwoFactor', $colleague->id);

        $this->assertFalse(app(TwoFactor::class)->isEnabled($colleague->fresh()));
        $log = AuditLog::where('action', 'two_factor.reset_by_admin')->first();
        $this->assertNotNull($log);
        $this->assertSame(User::where('email', 'admin@example.org')->first()->id, $log->user_id, 'the audit entry must name the administrator');
    }

    public function test_non_administrators_cannot_reset_two_factor(): void
    {
        $victim = $this->user('Planning');
        $this->enrol($victim);

        $this->actingAs($this->user('Minister'));
        Volt::test('pages.management.users.user-management')->call('resetTwoFactor', $victim->id)->assertForbidden();

        $this->assertTrue(app(TwoFactor::class)->isEnabled($victim->fresh()));
    }

    public function test_secrets_never_reach_the_audit_trail(): void
    {
        [$secret, $codes] = $this->enrol($this->user());

        $everything = DB::table('audit_logs')->get()->map(fn ($r) => json_encode($r))->implode(' ');

        $this->assertStringNotContainsString($secret, $everything);
        foreach ($codes as $c) {
            $this->assertStringNotContainsString($c, $everything);
        }
        $this->assertStringNotContainsString('two_factor_secret', $everything);
        $this->assertDatabaseHas('audit_logs', ['action' => 'two_factor.enabled']);
    }

    public function test_the_password_page_login_still_works_for_users_without_it(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get(route('work.home'))->assertOk();
        $this->assertTrue(Hash::check('password', $user->password));
    }
}
