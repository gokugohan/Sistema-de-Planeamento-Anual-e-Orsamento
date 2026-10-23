<x-app-layout title="{{ __('Profile') }}">
    <div class="max-w-3xl space-y-6">

        <!-- Page Header -->
        <div class="flex items-center gap-4 mb-2">
            <div class="w-14 h-14 bg-indigo-600 rounded-xl flex items-center justify-center text-white text-xl font-semibold
                        shadow-sm ring-1 ring-white/20 flex-shrink-0">
                {{ substr(auth()->user()->name, 0, 2) }}
            </div>
            <div>
                <h1 class="page-title">{{ auth()->user()->name }}</h1>
                <p class="text-sm text-zinc-400 mt-0.5">
                    {{ __(auth()->user()->roles->first()?->display_name ?: 'System User') }}
                    &middot; {{ auth()->user()->email }}
                </p>
            </div>
        </div>

        <!-- Profile Information -->
        <x-ui.card>
            <x-slot name="header">
                <div>
                    <h2 class="text-sm font-semibold text-zinc-900">{{ __('Profile Information') }}</h2>
                    <p class="text-xs text-zinc-400 mt-0.5">{{ __("Update your account's profile information and email address.") }}</p>
                </div>
            </x-slot>
            <livewire:profile.update-profile-information-form />
        </x-ui.card>

        <!-- Update Password -->
        <x-ui.card>
            <x-slot name="header">
                <div>
                    <h2 class="text-sm font-semibold text-zinc-900">{{ __('Update Password') }}</h2>
                    <p class="text-xs text-zinc-400 mt-0.5">{{ __('Use a long, random password to keep your account secure.') }}</p>
                </div>
            </x-slot>
            <livewire:profile.update-password-form />
        </x-ui.card>

        <!-- Two-factor authentication -->
        <x-ui.card>
            <x-slot name="header">
                <div>
                    <h2 class="text-sm font-semibold text-zinc-900">{{ __('Two-factor authentication') }}</h2>
                    <p class="text-xs text-zinc-400 mt-0.5">{{ __('A code from your phone as a second step when you sign in.') }}</p>
                </div>
            </x-slot>
            <livewire:profile.two-factor-authentication-form />
        </x-ui.card>

        <!-- What this person did -->
        <x-ui.card>
            <x-slot name="header">
                <div>
                    <h2 class="text-sm font-semibold text-zinc-900">{{ __('Your recent activity') }}</h2>
                    <p class="text-xs text-zinc-400 mt-0.5">{{ __('When you signed in and what you did in the system. Nobody can edit this record.') }}</p>
                </div>
            </x-slot>
            <livewire:profile.my-activity />
        </x-ui.card>

        <p class="text-xs text-zinc-500">{{ __('Accounts are created, changed and removed by your system administrator; ask them if you need to leave the system.') }}</p>

    </div>
</x-app-layout>
