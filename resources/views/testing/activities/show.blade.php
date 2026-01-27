<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-amber-800 leading-tight">
            {{ __('PAA Simulation - Activity Analysis') }} 🧪
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-amber-50 rounded-xl p-4 border border-amber-200 mb-6 shadow-sm">
                <p class="text-xs text-amber-900 font-medium">
                    <span class="font-black">🧪 {{ __('SIMULATION VIEW') }}</span>:
                    {{ __('You are analyzing a simulation activity.') }}
                </p>
            </div>

            <livewire:pages.activities.show :activity="$activity" />
        </div>
    </div>
</x-app-layout>