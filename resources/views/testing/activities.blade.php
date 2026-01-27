<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-amber-800 leading-tight">
            {{ __('PAA Testing - Activities') }} 🧪
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <livewire:testing.simulation-list />
        </div>
    </div>
</x-app-layout>