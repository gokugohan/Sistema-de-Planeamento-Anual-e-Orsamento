<?php

use App\Livewire\Forms\LoginForm;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public LoginForm $form;

    /**
     * Handle an incoming authentication request.
     */
    public function login(): void
    {
        $this->validate();

        $this->form->authenticate();

        Session::regenerate();

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div>
    <x-auth-heading :title="__('Sign in')" :subtitle="__('Use your institutional account to continue.')" />

    <x-auth-session-status class="mb-5" :status="session('status')" />

    {{-- One summary for every sign-in problem, announced to screen readers --}}
    @if($errors->has('form.email') || $errors->has('form.password'))
        <div class="alert alert-error mb-6 !items-start" role="alert">
            <svg class="mt-0.5 shrink-0" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
            <span>{{ $errors->first('form.email') ?: $errors->first('form.password') }}</span>
        </div>
    @endif

    <form wire:submit="login" class="space-y-5" x-data="{ show: false, caps: false }" novalidate>
        <div>
            <label for="email" class="block text-sm font-medium text-zinc-700 mb-1.5">{{ __('Email') }}</label>
            <div class="relative">
                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-zinc-400 pointer-events-none" aria-hidden="true">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                </span>
                <input wire:model="form.email" id="email" name="email" type="email" required autofocus
                    autocomplete="username" inputmode="email" spellcheck="false" placeholder="name@institution.example"
                    @class(['flux-input !pl-10', '!border-rose-400' => $errors->has('form.email')])
                    @if($errors->has('form.email')) aria-invalid="true" @endif>
            </div>
        </div>

        <div>
            <div class="flex items-center justify-between mb-1.5">
                <label for="password" class="block text-sm font-medium text-zinc-700">{{ __('Password') }}</label>
                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" wire:navigate class="text-sm font-medium text-indigo-600 hover:text-indigo-700 hover:underline">{{ __('Forgot your password?') }}</a>
                @endif
            </div>
            <div class="relative">
                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-zinc-400 pointer-events-none" aria-hidden="true">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </span>
                <input wire:model="form.password" id="password" name="password" required autocomplete="current-password"
                    :type="show ? 'text' : 'password'" type="password"
                    @keyup="caps = $event.getModifierState && $event.getModifierState('CapsLock')"
                    @keydown="caps = $event.getModifierState && $event.getModifierState('CapsLock')"
                    @class(['flux-input !pl-10 !pr-11', '!border-rose-400' => $errors->has('form.password')])
                    aria-describedby="caps-warning">
                <button type="button" @click="show = !show" :aria-pressed="show.toString()"
                    :aria-label="show ? '{{ __('Hide password') }}' : '{{ __('Show password') }}'"
                    class="absolute inset-y-0 right-0 px-3 flex items-center text-zinc-400 hover:text-zinc-700 rounded-r-md">
                    <svg x-show="!show" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg x-show="show" x-cloak width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.53 13.53 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><path d="m2 2 20 20"/></svg>
                </button>
            </div>
            <p id="caps-warning" x-show="caps" x-cloak class="mt-1.5 text-xs font-medium text-amber-700" role="status">{{ __('Caps Lock is on') }}</p>
        </div>

        <label for="remember" class="flex items-center gap-2.5 text-sm text-zinc-600 cursor-pointer select-none">
            <input wire:model="form.remember" id="remember" name="remember" type="checkbox"
                class="h-4 w-4 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-600">
            {{ __('Keep me signed in on this device') }}
        </label>

        <x-primary-button class="w-full justify-center !py-2.5 text-[15px]" wire:loading.attr="disabled" wire:target="login">
            <span wire:loading.remove wire:target="login">{{ __('Sign in') }}</span>
            <span wire:loading wire:target="login" class="inline-flex items-center gap-2">
                <svg class="animate-spin" width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity=".25" stroke-width="4"/><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="4" stroke-linecap="round"/></svg>
                {{ __('Signing in…') }}
            </span>
        </x-primary-button>
    </form>

    <div class="mt-8 pt-6 border-t border-zinc-200 text-sm text-zinc-500">
        {{ __('Need access or have trouble signing in? Contact your system administrator.') }}
        @if (config('auth.allow_registration') && Route::has('register'))
            <a href="{{ route('register') }}" wire:navigate class="font-medium text-indigo-600 hover:underline">{{ __('Create an account') }}</a>
        @endif
    </div>
</div>
