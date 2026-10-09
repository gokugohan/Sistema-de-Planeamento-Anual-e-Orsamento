<x-app-layout title="{{ __('Profile') }}">
    <div class="max-w-3xl space-y-6">

        <!-- Page Header -->
        <div class="flex items-center gap-4 mb-2">
            <div class="w-14 h-14 bg-indigo-600 rounded-2xl flex items-center justify-center text-white text-xl font-semibold
                        shadow-lg shadow-indigo-600/20 ring-1 ring-white/20 flex-shrink-0">
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

        <!-- Delete Account -->
        <x-ui.card>
            <x-slot name="header">
                <div>
                    <h2 class="text-sm font-semibold text-rose-600">{{ __('Delete Account') }}</h2>
                    <p class="text-xs text-zinc-400 mt-0.5">{{ __('Permanently delete your account and all associated data.') }}</p>
                </div>
            </x-slot>
            <livewire:profile.delete-user-form />
        </x-ui.card>

    </div>
</x-app-layout>
