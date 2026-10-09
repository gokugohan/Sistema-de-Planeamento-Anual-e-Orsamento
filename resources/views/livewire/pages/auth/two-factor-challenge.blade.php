<?php

use App\Livewire\Actions\Logout;
use App\Services\TwoFactor;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $code = '';

    public bool $useRecovery = false;

    public function mount(TwoFactor $twoFactor): void
    {
        // Nothing to do for a session that already passed, or for an account without 2FA.
        if (! $twoFactor->isEnabled(auth()->user()) || $twoFactor->passed()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));
        }
    }

    public function toggleRecovery(): void
    {
        $this->useRecovery = ! $this->useRecovery;
        $this->reset('code');
        $this->resetErrorBag();
    }

    public function verify(TwoFactor $twoFactor): void
    {
        $this->validate(['code' => ['required', 'string', 'max:32']]);

        $user = auth()->user();
        $key = 'two-factor:'.$user->id.'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'code' => __('Too many attempts. Try again in :s seconds.', ['s' => RateLimiter::availableIn($key)]),
            ]);
        }

        $ok = $this->useRecovery ? $twoFactor->useRecoveryCode($user, $this->code) : $twoFactor->verify($user, $this->code);

        if (! $ok) {
            RateLimiter::hit($key, 60);
            $this->reset('code');

            throw ValidationException::withMessages([
                'code' => $this->useRecovery ? __('That recovery code is not valid, or was already used.') : __('That code is not valid. Check the time on your phone and try again.'),
            ]);
        }

        RateLimiter::clear($key);
        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }

    public function cancel(Logout $logout): void
    {
        $logout();
        $this->redirect('/', navigate: true);
    }
}; ?>

<div>
    <x-auth-heading
        :title="$useRecovery ? __('Use a recovery code') : __('Two-factor authentication')"
        :subtitle="$useRecovery ? __('Enter one of the recovery codes you saved when you set up two-factor authentication. Each works once.') : __('Open your authenticator app and enter the 6-digit code for this account.')" />

    <form wire:submit="verify" class="space-y-5" novalidate>
        <div>
            <label for="code" class="block text-sm font-medium text-zinc-700 mb-1.5">{{ $useRecovery ? __('Recovery code') : __('Authentication code') }}</label>
            <input wire:model="code" id="code" name="code" type="text" required autofocus
                @if($useRecovery) autocomplete="off" placeholder="xxxxx-xxxxx" @else inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="123456" @endif
                @class(['flux-input text-center text-lg tracking-widest', '!border-rose-400' => $errors->has('code')])
                @if($errors->has('code')) aria-invalid="true" aria-describedby="code-error" @endif>
            @error('code')
                <p id="code-error" class="mt-2 text-sm text-rose-600" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <x-primary-button class="w-full justify-center !py-2.5 text-[15px]" wire:loading.attr="disabled" wire:target="verify">
            <span wire:loading.remove wire:target="verify">{{ __('Verify') }}</span>
            <span wire:loading wire:target="verify">{{ __('Verifying…') }}</span>
        </x-primary-button>
    </form>

    <div class="mt-6 flex items-center justify-between text-sm">
        <button type="button" wire:click="toggleRecovery" class="font-medium text-indigo-600 hover:underline">
            {{ $useRecovery ? __('Use an authentication code instead') : __('Lost your device? Use a recovery code') }}
        </button>
        <button type="button" wire:click="cancel" class="text-zinc-500 hover:text-zinc-800 hover:underline">{{ __('Sign out') }}</button>
    </div>
</div>
