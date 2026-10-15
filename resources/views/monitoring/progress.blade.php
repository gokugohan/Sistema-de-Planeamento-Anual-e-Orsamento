<x-app-layout title="{{ __('Report progress') }}">
    <div class="space-y-6">
        <x-ui.page-header :title="__('Report progress')" :subtitle="__('Record what each activity achieved against its indicators in a quarter. Figures count once they are verified.')" />
        <livewire:pages.monitoring.quarterly-progress />
    </div>
</x-app-layout>
