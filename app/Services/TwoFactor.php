<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Time-based one-time passwords (RFC 6238) for sign-in, with single-use recovery codes.
 *
 *  - The secret and the recovery codes are stored encrypted; recovery codes are also hashed,
 *    so even a database dump does not reveal a usable code.
 *  - A code is accepted once only (replay protection via the last accepted time-step).
 *  - Roles listed in config('auth.two_factor.required_roles') must enable it.
 *
 * "Passed" is kept in the session: it is set after the challenge and lost on sign-out, so a
 * stolen "remember me" cookie alone never skips the second factor.
 */
class TwoFactor
{
    public const SESSION_PASSED = 'two_factor_passed';

    public const SESSION_PENDING_SECRET = 'two_factor_pending_secret';

    private Google2FA $totp;

    public function __construct()
    {
        $this->totp = new Google2FA;
        $this->totp->setWindow(1); // tolerate ±30 s of clock drift
    }

    public function isEnabled(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null && filled($user->two_factor_secret);
    }

    public function isRequiredFor(User $user): bool
    {
        $roles = array_filter((array) config('auth.two_factor.required_roles'));

        return $roles !== [] && $user->hasAnyRole($roles);
    }

    public function passed(): bool
    {
        return (bool) session(self::SESSION_PASSED, false);
    }

    public function markPassed(): void
    {
        session([self::SESSION_PASSED => true]);
    }

    // ── setup ──

    /** Start enrolment: a fresh secret kept in the session until a valid code confirms it. */
    public function beginSetup(): string
    {
        $secret = $this->totp->generateSecretKey(32);
        session([self::SESSION_PENDING_SECRET => $secret]);

        return $secret;
    }

    public function pendingSecret(): ?string
    {
        return session(self::SESSION_PENDING_SECRET);
    }

    public function cancelSetup(): void
    {
        session()->forget(self::SESSION_PENDING_SECRET);
    }

    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->totp->getQRCodeUrl(config('app.name'), $user->email, $secret);
    }

    /** Inline SVG of the enrolment QR code (generated locally; the secret never leaves the server). */
    public function qrSvg(User $user, string $secret): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd));

        return $writer->writeString($this->otpauthUrl($user, $secret));
    }

    /**
     * Confirm enrolment with a code from the authenticator app.
     *
     * @return list<string>|null the recovery codes (shown once), or null if the code was wrong
     */
    public function confirmSetup(User $user, string $code): ?array
    {
        $secret = $this->pendingSecret();
        if (! $secret) {
            return null;
        }

        $step = $this->totp->verifyKeyNewer($secret, $this->clean($code), null);
        if ($step === false) {
            return null;
        }

        $codes = $this->newRecoveryCodes();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $codes['hashed'],
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => $step,
        ])->save();

        $this->cancelSetup();
        $this->markPassed();
        $this->log('two_factor.enabled', $user, $user);

        return $codes['plain'];
    }

    // ── sign-in ──

    /** Verify a code from the app. Each code works once. */
    public function verify(User $user, string $code): bool
    {
        if (! $this->isEnabled($user)) {
            return false;
        }

        $step = $this->totp->verifyKeyNewer($user->two_factor_secret, $this->clean($code), $user->two_factor_last_step);
        if ($step === false) {
            return false;
        }

        $user->forceFill(['two_factor_last_step' => $step])->save();
        $this->markPassed();

        return true;
    }

    /** Use (and burn) a recovery code. */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $code = strtolower(trim($code));
        $hashes = (array) $user->two_factor_recovery_codes;

        foreach ($hashes as $i => $hash) {
            if (Hash::check($code, $hash)) {
                unset($hashes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();
                $this->markPassed();
                $this->log('two_factor.recovery_code_used', $user, $user, ['remaining' => count($hashes)]);

                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $codes['hashed']])->save();
        $this->log('two_factor.recovery_codes_regenerated', $user, $user);

        return $codes['plain'];
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return count((array) $user->two_factor_recovery_codes);
    }

    public function disable(User $user, ?User $by = null): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();

        $this->log($by && $by->isNot($user) ? 'two_factor.reset_by_admin' : 'two_factor.disabled', $by ?? $user, $user);
    }

    // ── helpers ──

    /** @return array{plain: list<string>, hashed: list<string>} */
    private function newRecoveryCodes(int $count = 8): array
    {
        $plain = collect(range(1, $count))->map(fn () => strtolower(Str::random(5).'-'.Str::random(5)))->all();

        return ['plain' => $plain, 'hashed' => array_map(fn ($c) => Hash::make($c), $plain)];
    }

    private function clean(string $code): string
    {
        return preg_replace('/\D/', '', $code) ?? '';
    }

    private function log(string $action, ?User $actor, User $subject, array $meta = []): void
    {
        AuditLog::create([
            'action' => $action,
            'user_id' => $actor?->id,
            'auditable_type' => $subject->getMorphClass(),
            'auditable_id' => $subject->id,
            'description' => "{$action}: {$subject->email}",
            'metadata' => $meta ?: null,
            'ip_address' => request()?->ip(),
        ]);
    }
}
