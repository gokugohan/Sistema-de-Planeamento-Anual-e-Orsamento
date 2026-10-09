<x-app-layout title="{{ __('Dashboard') }}">
    {{--
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    --}}
    <div class="space-y-6">
        <x-next-actions />
        <livewire:pages.dashboard.summary />
    </div>
</x-app-layout>