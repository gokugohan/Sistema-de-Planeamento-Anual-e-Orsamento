<x-app-layout title="{{ __('Insights') }}">
    <div class="space-y-10">
        <!-- <div class="flex justify-between items-end">
            <div>
                <h1 class="text-3xl font-black text-slate-900 tracking-tight">{{ __('Physical Execution Monitoring') }}
                </h1>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em] mt-2">
                    {{ __('Performance & Indicator Tracking') }}</p>
            </div>
        </div> -->

        <livewire:pages.monitoring.execution-dashboard />

        <x-ui.card padding="p-0">
            <x-slot name="header">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest">
                        {{ __('Quarterly Progress Entry') }}
                    </h3>
                </div>
            </x-slot>
            <div class="p-8">
                <livewire:pages.monitoring.quarterly-progress />
            </div>
        </x-ui.card>
    </div>
</x-app-layout>