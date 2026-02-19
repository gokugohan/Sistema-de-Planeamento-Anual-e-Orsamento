<x-app-layout title="{{ __('Manage Indicators') }}">
    {{--
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Gestão de Indicadores') }}
        </h2>
    </x-slot>
    --}}

    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
        <div class="p-6 text-gray-900">
            <livewire:pages.activities.manage-indicators :activity="$activity" />
        </div>
    </div>
</x-app-layout>