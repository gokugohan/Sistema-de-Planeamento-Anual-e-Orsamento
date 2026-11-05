<?php

namespace App\Notifications;

use App\Models\SystemSetting;
use App\Support\MailSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The e-mail twin of a SystemNotification. It is queued, so that a slow or broken mail server never holds up (or breaks)
 * the action that caused it; the in-app notification has already been written. Where Settings ask for delivery
 * "right away" (no queue worker), it is sent during the action instead (see MailSettings).
 */
class SystemNotificationEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $title, public string $message, public ?string $actionUrl = null) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // a queue worker runs for days: take the mail server as it is saved in Settings now, not as it was at start
        SystemSetting::forgetMemo();
        MailSettings::apply();

        $mail = (new MailMessage)->subject($this->title)->greeting($this->title)->line($this->message);

        if ($this->actionUrl) {
            $mail->action(__('Open in the application'), url($this->actionUrl));
        }

        return $mail->line(__('You receive this e-mail because you have an account in :app. You can switch e-mail notifications off in your profile.', ['app' => config('app.name')]));
    }
}
