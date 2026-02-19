<x-app-layout title="{{ __('PAA Activities') }}">
    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
        <div class="p-6 text-gray-900">
            <livewire:pages.activities.show :activity="$activity" />
        </div>
    </div>
</x-app-layout>