<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Events\Failed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Each person sees their own activity in their profile; sign-ins and failed attempts are recorded; an administrator reaches anyone's log. */
class UserActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function log(User $user, string $text): void
    {
        AuditLog::create(['action' => 'activity.updated', 'user_id' => $user->id, 'description' => $text, 'ip_address' => '10.0.0.5']);
    }

    public function test_the_profile_lists_the_persons_own_activity_and_nobody_elses(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $this->log($me, 'I changed the budget of activity 12');
        $this->log($other, 'Someone else approved activity 99');

        $this->actingAs($me)->get(route('profile'))->assertOk()->assertSee('Your recent activity')
            ->assertSee('I changed the budget of activity 12')->assertSee('10.0.0.5')->assertDontSee('Someone else approved activity 99');
    }

    public function test_the_activity_is_shown_ten_at_a_time_newest_first(): void
    {
        $me = User::factory()->create();
        foreach (range(1, 12) as $i) {
            $this->log($me, "Step number {$i}");
        }

        $component = Volt::actingAs($me)->test('profile.my-activity');

        $component->assertSee('Step number 12')->assertDontSee('Step number 1<')->assertSee('Older');
        $this->assertCount(10, $component->viewData('logs'));
    }

    public function test_signing_in_signing_out_and_failing_to_sign_in_are_recorded(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.test']);

        Auth::login($user);
        Auth::logout();
        Event::dispatch(new Failed('web', $user, ['email' => 'ana@example.test', 'password' => 'secret-never-stored']));

        $actions = AuditLog::where('user_id', $user->id)->orderBy('id')->pluck('action')->all();
        $this->assertSame(['auth.login', 'auth.logout', 'auth.failed'], $actions);
        $this->assertStringNotContainsString('secret-never-stored', json_encode(AuditLog::all()->toArray()), 'a password is never written to the log');
    }

    public function test_an_administrator_reaches_the_log_of_a_person_from_the_users_list(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $person = User::factory()->create();

        Volt::actingAs($admin)->test('pages.management.users.user-management')->assertSeeHtml(route('management.audit', ['userId' => $person->id]));
    }
}
