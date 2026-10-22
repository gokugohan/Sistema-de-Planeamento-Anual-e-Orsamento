<?php

namespace App\Support;

use App\Models\SystemSetting;

/**
 * Notifications always arrive inside the application. Where an administrator switches it on (Management > Settings) and the
 * server can send mail, each one is also e-mailed to the people who have not opted out. Off by default.
 */
final class EmailNotifications
{
    public const KEY = 'email_notifications';

    public static function enabled(): bool
    {
        return (bool) SystemSetting::get(self::KEY, false);
    }
}
