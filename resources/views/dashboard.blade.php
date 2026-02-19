<x-app-layout title="{{ __('Dashboard') }}">
    {{--
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    --}}
    <livewire:pages.dashboard.summary />
</x-app-layout>