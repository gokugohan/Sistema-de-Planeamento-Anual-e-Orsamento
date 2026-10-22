<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The e-mail twin of a SystemNotification. It is queued, so that a slow or broken mail server never holds up (or breaks)
 * the action that caused it; the in-app notification has already been written.
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
        $mail = (new MailMessage)->subject($this->title)->greeting($this->title)->line($this->message);

        if ($this->actionUrl) {
            $mail->action(__('Open in the application'), url($this->actionUrl));
        }

        return $mail->line(__('You receive this e-mail because you have an account in :app. You can switch e-mail notifications off in your profile.', ['app' => config('app.name')]));
    }
}
