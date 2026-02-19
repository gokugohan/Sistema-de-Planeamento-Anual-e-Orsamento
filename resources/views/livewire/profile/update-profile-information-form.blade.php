<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
    public string $name = '';
    public string $email = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
        ]);

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        $this->dispatch('profile-updated', name: $user->name);
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function sendVerification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }
}; ?>

<form wire:submit="updateProfileInformation" class="space-y-5">
    <div>
        <x-input-label for="name" :value="__('Full Name')" />
        <x-text-input wire:model="name" id="name" name="name" type="text"
            class="w-full" required autofocus autocomplete="name" />
        <x-input-error class="mt-1.5" :messages="$errors->get('name')" />
    </div>

    <div>
        <x-input-label for="email" :value="__('Email Address')" />
        <x-text-input wire:model="email" id="email" name="email" type="email"
            class="w-full" required autocomplete="username" />
        <x-input-error class="mt-1.5" :messages="$errors->get('email')" />

        @if (auth()->user() instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! auth()->user()->hasVerifiedEmail())
            <div class="mt-3 p-3 bg-amber-50 rounded-lg ring-1 ring-amber-200/60">
                <p class="text-sm text-amber-700">
                    {{ __('Your email address is unverified.') }}
                    <button wire:click.prevent="sendVerification"
                        class="underline font-medium hover:text-amber-900 transition-colors">
                        {{ __('Resend verification email.') }}
                    </button>
                </p>
                @if (session('status') === 'verification-link-sent')
                    <p class="mt-2 text-sm font-medium text-emerald-600">
                        {{ __('A new verification link has been sent to your email address.') }}
                    </p>
                @endif
            </div>
        @endif
    </div>

    <div class="flex items-center gap-3 pt-1">
        <x-primary-button>{{ __('Save Changes') }}</x-primary-button>

        <x-action-message on="profile-updated">
            <span class="text-sm text-emerald-600 font-medium">{{ __('Saved.') }}</span>
        </x-action-message>
    </div>
</form>
