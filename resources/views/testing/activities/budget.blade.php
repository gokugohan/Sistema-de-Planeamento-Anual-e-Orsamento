<x-app-layout title="{{ __('Testing Zone') }}">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-amber-800 leading-tight">
            {{ __('PAA Simulation - Budget Planning') }} 🧪
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-amber-50 rounded-xl p-4 border border-amber-200 mb-6 shadow-sm text-center">
                <p class="text-xs text-amber-900 font-black uppercase tracking-widest">
                    {{ __('Sandbox: Simulating financial allocations') }}
                </p>
            </div>

            <div class="bg-white overflow-hidden shadow-2xl sm:rounded-3xl border border-amber-100">
                <div class="p-8 text-gray-900">
                    <livewire:pages.activities.manage-line-items :activity="$activity" />
                </div>
            </div>
        </div>
    </div>
</x-app-layout>