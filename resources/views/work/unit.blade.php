<x-app-layout title="{{ $directorate->code }} · {{ __('My work') }}">
    <livewire:pages.work.unit :directorate="$directorate" />
</x-app-layout>
