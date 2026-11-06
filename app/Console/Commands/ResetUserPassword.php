<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TwoFactor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The way back in when the administrator has lost their password and no other administrator or e-mail can help:
 * someone with access to the server issues a temporary password, which must be changed at the first sign-in.
 */
class ResetUserPassword extends Command
{
    protected $signature = 'paa:reset-password
        {email : the account to recover}
        {--reactivate : also reactivate the account if it was deactivated}
        {--reset-2fa : also turn off two-factor authentication (the phone was lost too)}';

    protected $description = 'Issue a temporary password for an account (recovery of a lost administrator password)';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('No account has that e-mail address.');
            $admins = User::role('Admin')->orderBy('email')->pluck('email');
            if ($admins->isNotEmpty()) {
                $this->line('Administrators: '.$admins->implode(', '));
            }

            return self::FAILURE;
        }

        $temporary = Str::password(14, symbols: false);
        $user->forceFill(['password' => Hash::make($temporary), 'must_change_password' => true, 'remember_token' => Str::random(60)])->save();

        if (! $user->isActive()) {
            if ($this->option('reactivate')) {
                $user->forceFill(['deactivated_at' => null])->save();
                $this->info('The account was reactivated.');
            } else {
                $this->warn('The account is deactivated: add --reactivate to let it sign in.');
            }
        }
        if ($this->option('reset-2fa') && $user->two_factor_confirmed_at) {
            app(TwoFactor::class)->disable($user, null);
            $this->info('Two-factor authentication was turned off; it can be set up again from the profile.');
        }

        $this->info("Temporary password for {$user->email}: {$temporary}");
        $this->line('It works once: a new password must be chosen at the first sign-in.');

        return self::SUCCESS;
    }
}
