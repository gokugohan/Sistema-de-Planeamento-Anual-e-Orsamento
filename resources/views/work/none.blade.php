<x-app-layout title="{{ __('My work') }}">
    <div class="space-y-6">
        <x-ui.page-header :title="__('My work')" :subtitle="__('Your unit, your plan and what waits for you.')" />
        <x-ui.card>
            <x-ui.empty-state :title="__('Your account is not linked to a unit yet')" :text="__('Ask an administrator to link your account to your directorate, department or unit, so that your plan and your work appear here.')" />
        </x-ui.card>
    </div>
</x-app-layout>
