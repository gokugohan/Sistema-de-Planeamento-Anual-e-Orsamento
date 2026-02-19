<x-app-layout title="{{ __('Testing Zone') }}">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-amber-800 leading-tight">
            {{ __('PAA Testing - Budget') }} 🚧
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-amber-50 overflow-hidden shadow-sm sm:rounded-lg border border-amber-200 p-6">
                <p class="text-amber-900 font-bold">{{ __('Test Budget Management - Coming Soon') }}</p>
                <p class="text-sm text-amber-700 mt-2">
                    {{ __('This interface will allow simulating budget allocations and scenarios.') }}</p>
            </div>
        </div>
    </div>
</x-app-layout>