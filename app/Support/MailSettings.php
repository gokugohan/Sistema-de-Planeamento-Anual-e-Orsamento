<?php

namespace App\Support;

use App\Models\SystemSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * The mail server, set by an administrator in Settings instead of in the .env file. When a server is saved it is
 * used for every e-mail; when none is saved the .env settings apply as before. The password is stored encrypted
 * with the application key and is never shown back.
 *
 * Delivery: 'queue' hands each notification e-mail to the queue worker (the page is never held up by a slow mail
 * server, but a worker must run); 'now' sends it during the action (nothing else to run).
 */
final class MailSettings
{
    public const KEY = 'mail_server';

    /** security => the transport scheme: STARTTLS when the server offers it (usually port 587 or 25), or TLS from the start (465) */
    public const SECURITY = ['starttls' => 'smtp', 'ssl' => 'smtps'];

    public const DELIVERY = ['queue', 'now'];

    /** @return array{host: string, port: int, security: string, username: string, from_address: string, from_name: string, delivery: string, has_password: bool} */
    public static function current(): array
    {
        $s = SystemSetting::get(self::KEY);
        $s = is_array($s) ? $s : [];

        return [
            'host' => (string) ($s['host'] ?? ''),
            'port' => (int) ($s['port'] ?? 587),
            'security' => array_key_exists($s['security'] ?? '', self::SECURITY) ? $s['security'] : 'starttls',
            'username' => (string) ($s['username'] ?? ''),
            'from_address' => (string) ($s['from_address'] ?? ''),
            'from_name' => (string) ($s['from_name'] ?? ''),
            'delivery' => in_array($s['delivery'] ?? '', self::DELIVERY, true) ? $s['delivery'] : 'queue',
            'has_password' => filled($s['password'] ?? null),
        ];
    }

    /** Is a mail server saved here (otherwise the .env settings apply)? */
    public static function configured(): bool
    {
        return self::current()['host'] !== '';
    }

    /** Should notification e-mails be sent during the action rather than by the queue worker? */
    public static function sendNow(): bool
    {
        return self::current()['delivery'] === 'now';
    }

    /**
     * Store the server. An empty password keeps the saved one; $clearPassword removes it (a server without login).
     *
     * @param  array{host: string, port: int|string, security: string, username: string, from_address: string, from_name: string, delivery: string}  $values
     */
    public static function save(array $values, ?string $password = null, bool $clearPassword = false): void
    {
        $stored = SystemSetting::get(self::KEY);
        $secret = is_array($stored) ? ($stored['password'] ?? null) : null;
        if ($clearPassword) {
            $secret = null;
        } elseif (filled($password)) {
            $secret = Crypt::encryptString($password);
        }

        SystemSetting::set(self::KEY, [
            'host' => trim($values['host']),
            'port' => (int) $values['port'],
            'security' => $values['security'],
            'username' => trim($values['username']),
            'password' => $secret,
            'from_address' => trim($values['from_address']),
            'from_name' => trim($values['from_name']),
            'delivery' => $values['delivery'],
        ], 'json', 'Mail server for e-mail notifications (the password is encrypted)');
    }

    /** Put the saved server into the mail configuration (nothing when none is saved: the .env settings stay). */
    public static function apply(): void
    {
        $s = SystemSetting::get(self::KEY);
        if (! is_array($s) || trim((string) ($s['host'] ?? '')) === '') {
            return;
        }
        self::use($s, self::decrypt($s['password'] ?? null));
    }

    /**
     * Put these values into the mail configuration, for this process, and forget a mailer built with older ones.
     *
     * @param  array<string, mixed>  $s
     */
    public static function use(array $s, ?string $password): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.scheme' => self::SECURITY[$s['security'] ?? 'starttls'] ?? 'smtp',
            'mail.mailers.smtp.url' => null,
            'mail.mailers.smtp.host' => trim((string) $s['host']),
            'mail.mailers.smtp.port' => (int) ($s['port'] ?? 587),
            'mail.mailers.smtp.username' => filled($s['username'] ?? null) ? $s['username'] : null,
            'mail.mailers.smtp.password' => $password,
            'mail.mailers.smtp.timeout' => 15,
        ]);
        if (filled($s['from_address'] ?? null)) {
            config(['mail.from.address' => $s['from_address'], 'mail.from.name' => filled($s['from_name'] ?? null) ? $s['from_name'] : config('app.name')]);
        }
        if (app()->resolved('mail.manager')) {
            app('mail.manager')->purge('smtp');
        }
    }

    /** The saved password, for a test that keeps it. */
    public static function savedPassword(): ?string
    {
        $s = SystemSetting::get(self::KEY);

        return is_array($s) ? self::decrypt($s['password'] ?? null) : null;
    }

    private static function decrypt(?string $secret): ?string
    {
        if (! filled($secret)) {
            return null;
        }
        try {
            return Crypt::decryptString($secret);
        } catch (DecryptException) {
            return null; // saved with another application key: the administrator types it again
        }
    }
}
