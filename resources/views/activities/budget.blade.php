<x-app-layout>
    {{--
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Orçamento da Atividade') }}
        </h2>
    </x-slot>
    --}}

    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
        <div class="p-6 text-gray-900">
            <livewire:pages.budget.manage-budget :activity="$activity" />
        </div>
    </div>
</x-app-layout>