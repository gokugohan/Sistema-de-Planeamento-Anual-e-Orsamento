<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Notifications\SystemNotificationEmail;
use App\Support\EmailNotifications;
use App\Support\MailSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** The mail server set in Settings instead of the .env file: stored with an encrypted password, applied to every e-mail. */
class MailSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER = [
        'host' => 'smtp.example.gov.tl', 'port' => 587, 'security' => 'starttls', 'username' => 'paa@example.gov.tl',
        'from_address' => 'paa@example.gov.tl', 'from_name' => 'PAA', 'delivery' => 'queue',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        app()->setLocale('en');
    }

    public function test_the_password_is_stored_encrypted_kept_when_empty_and_can_be_removed(): void
    {
        MailSettings::save(self::SERVER, 'secret-pass');
        $raw = SystemSetting::where('key', MailSettings::KEY)->value('value');
        $this->assertStringNotContainsString('secret-pass', $raw, 'never stored in clear');
        $this->assertTrue(MailSettings::current()['has_password']);
        $this->assertSame('secret-pass', MailSettings::savedPassword());

        MailSettings::save(['host' => 'smtp2.example.gov.tl'] + self::SERVER, '');
        $this->assertSame('secret-pass', MailSettings::savedPassword(), 'an empty field keeps the saved password');

        MailSettings::save(self::SERVER, '', clearPassword: true);
        $this->assertFalse(MailSettings::current()['has_password']);
    }

    public function test_a_saved_server_replaces_the_env_one_and_without_one_the_env_stays(): void
    {
        config(['mail.default' => 'log', 'mail.mailers.smtp.host' => 'env-host']);
        MailSettings::apply();
        $this->assertSame('log', config('mail.default'), 'nothing saved: the .env settings stay');

        MailSettings::save(['security' => 'ssl', 'port' => 465] + self::SERVER, 'secret-pass');
        MailSettings::apply();
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.example.gov.tl', config('mail.mailers.smtp.host'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('secret-pass', config('mail.mailers.smtp.password'));
        $this->assertSame('paa@example.gov.tl', config('mail.from.address'));
    }

    public function test_the_administrator_sets_the_server_in_settings_and_sends_a_test(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.gov.tl']);
        $admin->assignRole('Admin');
        $this->actingAs($admin);
        Mail::fake();

        Volt::test('pages.management.settings')
            ->set('emailNotifications', true)
            ->assertSee('Mail server')
            ->set('mail.host', 'smtp.example.gov.tl')->set('mail.port', 587)->set('mail.from_address', 'paa@example.gov.tl')
            ->set('mailPassword', 'secret-pass')
            ->call('testMail')->assertDispatched('toast', type: 'success')
            ->call('saveSettings')->assertHasNoErrors();

        $this->assertSame('smtp.example.gov.tl', MailSettings::current()['host']);
        $this->assertSame('secret-pass', MailSettings::savedPassword());

        // a server without a sender address is refused
        Volt::test('pages.management.settings')->set('mail.from_address', '')->call('saveSettings')->assertHasErrors('mail.from_address');
    }

    public function test_delivery_right_away_needs_no_queue_worker(): void
    {
        SystemSetting::set(EmailNotifications::KEY, true, 'boolean');
        $user = User::factory()->create();
        Queue::fake();

        MailSettings::save(self::SERVER, null);
        $user->notify(new SystemNotification('Title', 'Message'));
        Queue::assertPushed(\Illuminate\Notifications\SendQueuedNotifications::class, fn ($job) => $job->notification instanceof SystemNotificationEmail);

        Queue::fake();
        config(['mail.default' => 'array']); // the e-mail is "sent" into memory here
        MailSettings::save(['delivery' => 'now', 'host' => ''] + self::SERVER, null);
        $user->notify(new SystemNotification('Title', 'Message'));
        Queue::assertNothingPushed();
    }
}
