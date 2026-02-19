<x-app-layout title="{{ __('Entities Registry') }}">
    {{--
    <x-slot name="header">
        <h2 class="font-bold text-2xl text-gray-900 leading-tight">
            {{ __('Entity Management') }}
        </h2>
    </x-slot>
    --}}


    <div class="py-2">
        <livewire:pages.admin.entities />
    </div>
</x-app-layout>