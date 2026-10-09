<?php

use App\Services\TwoFactor;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component
{
    public string $code = '';

    public string $password = '';

    /** @var list<string>|null recovery codes, shown once right after they are created */
    public ?array $recoveryCodes = null;

    public bool $settingUp = false;

    public function mount(TwoFactor $twoFactor): void
    {
        // A required-but-not-enabled user lands here: open the setup straight away.
        $user = auth()->user();
        if (! $twoFactor->isEnabled($user) && $twoFactor->isRequiredFor($user)) {
            $this->startSetup($twoFactor);
        }
    }

    public function startSetup(TwoFactor $twoFactor): void
    {
        $twoFactor->beginSetup();
        $this->settingUp = true;
        $this->reset('code', 'password');
        $this->resetErrorBag();
    }

    public function cancelSetup(TwoFactor $twoFactor): void
    {
        $twoFactor->cancelSetup();
        $this->settingUp = false;
        $this->reset('code');
    }

    public function confirmSetup(TwoFactor $twoFactor): void
    {
        $this->validate(['code' => ['required', 'string', 'max:12']]);

        $codes = $twoFactor->confirmSetup(auth()->user(), $this->code);
        if ($codes === null) {
            $this->reset('code');
            throw ValidationException::withMessages(['code' => __('That code is not valid. Check the time on your phone and try again.')]);
        }

        $this->recoveryCodes = $codes;
        $this->settingUp = false;
        $this->reset('code');
        $this->dispatch('toast', message: __('Two-factor authentication is on.'), type: 'success');
    }

    public function regenerateCodes(TwoFactor $twoFactor): void
    {
        $this->requirePassword();
        $this->recoveryCodes = $twoFactor->regenerateRecoveryCodes(auth()->user());
        $this->reset('password');
    }

    public function disable(TwoFactor $twoFactor): void
    {
        $user = auth()->user();
        abort_if($twoFactor->isRequiredFor($user), 403);
        $this->requirePassword();

        $twoFactor->disable($user);
        $this->recoveryCodes = null;
        $this->reset('password');
        $this->dispatch('toast', message: __('Two-factor authentication is off.'), type: 'success');
    }

    public function dismissCodes(): void
    {
        $this->recoveryCodes = null;
    }

    private function requirePassword(): void
    {
        $this->validate(['password' => ['required', 'string']]);

        if (! Hash::check($this->password, auth()->user()->password)) {
            $this->reset('password');

            throw ValidationException::withMessages(['password' => __('That password is not correct.')]);
        }
    }

    public function with(TwoFactor $twoFactor): array
    {
        $user = auth()->user();
        $secret = $this->settingUp ? $twoFactor->pendingSecret() : null;

        return [
            'enabled' => $twoFactor->isEnabled($user),
            'required' => $twoFactor->isRequiredFor($user),
            'remaining' => $twoFactor->remainingRecoveryCodes($user),
            'secret' => $secret,
            'qr' => $secret ? $twoFactor->qrSvg($user, $secret) : null,
        ];
    }
}; ?>

<div class="space-y-5">
    @if($recoveryCodes)
        <div class="alert alert-success !items-start" role="status">
            <div class="flex-1">
                <p class="font-semibold">{{ __('Save these recovery codes now') }}</p>
                <p class="text-xs font-normal mt-1">{{ __('Each code works once if you lose your phone. They are shown only now and cannot be recovered; store them somewhere safe, not on the same phone.') }}</p>
                <ul class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 font-mono text-sm select-all">
                    @foreach($recoveryCodes as $c)<li>{{ $c }}</li>@endforeach
                </ul>
            </div>
            <button type="button" wire:click="dismissCodes" class="text-sm font-medium underline shrink-0">{{ __('I have saved them') }}</button>
        </div>
    @endif

    @if($settingUp && $secret)
        <div class="grid gap-6 sm:grid-cols-[auto_1fr] items-start">
            <div class="bg-white border border-zinc-200 rounded-md p-2 w-[216px] h-[216px]" aria-label="{{ __('QR code for your authenticator app') }}">{!! $qr !!}</div>
            <div class="space-y-3 text-sm">
                <ol class="list-decimal pl-5 space-y-1.5 text-zinc-600">
                    <li>{{ __('Install an authenticator app (Google Authenticator, Microsoft Authenticator, FreeOTP, Aegis…).') }}</li>
                    <li>{{ __('Scan the QR code, or type this key into the app:') }}
                        <code class="block mt-1 font-mono text-xs bg-zinc-100 rounded px-2 py-1.5 break-all select-all">{{ $secret }}</code></li>
                    <li>{{ __('Enter the 6-digit code the app shows.') }}</li>
                </ol>
                <form wire:submit="confirmSetup" class="flex items-start gap-3">
                    <div>
                        <label for="tf-code" class="sr-only">{{ __('Authentication code') }}</label>
                        <input wire:model="code" id="tf-code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="123456"
                            class="flux-input !w-40 text-center tracking-widest" @if($errors->has('code')) aria-invalid="true" @endif>
                        @error('code')<p class="mt-1.5 text-xs text-rose-600" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <x-ui.button type="submit" variant="primary">{{ __('Turn on') }}</x-ui.button>
                    @unless($required)
                        <x-ui.button type="button" variant="ghost" wire:click="cancelSetup">{{ __('Cancel') }}</x-ui.button>
                    @endunless
                </form>
            </div>
        </div>
    @elseif($enabled)
        <div class="flex items-center gap-3">
            <x-ui.badge variant="emerald">{{ __('On') }}</x-ui.badge>
            <p class="text-sm text-zinc-600">{{ __('You are asked for a code from your authenticator app each time you sign in.') }}
                <span class="text-zinc-500">{{ trans_choice(':n recovery code left|:n recovery codes left', $remaining, ['n' => $remaining]) }}</span></p>
        </div>

        <div class="max-w-sm">
            <label for="tf-pass" class="block text-sm font-medium text-zinc-700 mb-1.5">{{ __('Your password') }}</label>
            <input wire:model="password" id="tf-pass" type="password" autocomplete="current-password" class="flux-input" @if($errors->has('password')) aria-invalid="true" @endif>
            @error('password')<p class="mt-1.5 text-xs text-rose-600" role="alert">{{ $message }}</p>@enderror
            <p class="mt-1.5 text-xs text-zinc-500">{{ __('Needed to make new recovery codes or to turn two-factor off.') }}</p>
        </div>
        <div class="flex flex-wrap gap-3">
            <x-ui.button type="button" variant="secondary" wire:click="regenerateCodes">{{ __('New recovery codes') }}</x-ui.button>
            @if($required)
                <p class="text-xs text-zinc-500 self-center">{{ __('Your role requires two-factor authentication, so it cannot be turned off.') }}</p>
            @else
                <x-ui.button type="button" variant="danger" wire:click="disable" wire:confirm="{{ __('Turn two-factor authentication off?') }}">{{ __('Turn off') }}</x-ui.button>
            @endif
        </div>
    @else
        <div class="flex items-center gap-3">
            <x-ui.badge variant="gray">{{ __('Off') }}</x-ui.badge>
            <p class="text-sm text-zinc-600">{{ __('Add a second step to sign-in so a stolen password alone is not enough.') }}</p>
        </div>
        <x-ui.button type="button" variant="primary" wire:click="startSetup">{{ __('Set up two-factor authentication') }}</x-ui.button>
    @endif
</div>
