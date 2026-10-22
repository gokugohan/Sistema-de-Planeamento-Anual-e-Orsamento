<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Notifications\SystemNotificationEmail;
use App\Support\EmailNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Notifications always reach the bell; where an administrator switches e-mail on they are also mailed (queued) to those who have not opted out. */
class EmailNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function send(User $user): void
    {
        $user->notify(new SystemNotification('Your signature is next', 'Activity "X" waits for your signature.', '/activities/5'));
    }

    private function mailed(): int
    {
        return Queue::pushed(SendQueuedNotifications::class, fn ($job) => $job->notification instanceof SystemNotificationEmail)->count();
    }

    public function test_it_is_off_by_default_and_the_bell_still_gets_the_notification(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->send($user);

        $this->assertFalse(EmailNotifications::enabled());
        $this->assertSame(1, $user->unreadNotifications()->count());
        $this->assertSame(0, $this->mailed());
    }

    public function test_when_switched_on_the_notification_is_also_mailed_through_the_queue(): void
    {
        Queue::fake();
        SystemSetting::set(EmailNotifications::KEY, true, 'boolean');
        $user = User::factory()->create();

        $this->send($user);

        $this->assertSame(1, $user->unreadNotifications()->count());
        $this->assertSame(1, $this->mailed());
    }

    public function test_someone_who_opted_out_is_not_mailed(): void
    {
        Queue::fake();
        SystemSetting::set(EmailNotifications::KEY, true, 'boolean');
        $user = User::factory()->create();
        $user->forceFill(['email_notifications' => false])->save();

        $this->send($user);

        $this->assertSame(1, $user->unreadNotifications()->count(), 'the bell is not optional');
        $this->assertSame(0, $this->mailed());
    }

    public function test_the_mail_says_what_happened_and_links_to_it(): void
    {
        $user = User::factory()->create();
        $mail = (new SystemNotificationEmail('Your signature is next', 'Activity "X" waits for your signature.', '/activities/5'))->toMail($user);

        $this->assertSame('Your signature is next', $mail->subject);
        $this->assertSame(url('/activities/5'), $mail->actionUrl);
        $this->assertContains('Activity "X" waits for your signature.', $mail->introLines);
    }

    public function test_a_person_chooses_in_their_profile(): void
    {
        $user = User::factory()->create();

        Volt::actingAs($user)->test('profile.update-profile-information-form')->assertSet('emailNotifications', true)
            ->set('emailNotifications', false)->call('updateProfileInformation')->assertHasNoErrors();

        $this->assertFalse((bool) $user->fresh()->email_notifications);
    }

    public function test_the_bell_notices_a_new_notification_without_a_page_reload(): void
    {
        $user = User::factory()->create();
        $bell = Volt::actingAs($user)->test('layout.notification-bell');

        $this->send($user);
        $bell->call('refresh')->assertDispatched('toast')->assertSet('known', 1);

        $bell->call('refresh');
        $this->assertSame(1, $user->unreadNotifications()->count());
    }
}
