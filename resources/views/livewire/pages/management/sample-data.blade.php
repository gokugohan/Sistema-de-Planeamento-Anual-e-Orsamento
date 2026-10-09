<?php

use App\Services\SampleRegistry;
use Database\Seeders\Samples\MtcSampleDataSeeder;
use Livewire\Volt\Component;

new class extends Component {
    public ?array $report = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
    }

    public function load(): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);

        if (app()->isProduction()) {
            $this->dispatch('toast', message: __('Sample data cannot be loaded in production.'), type: 'error');
            return;
        }

        try {
            // Seeders need the container to inject the registry into their run() methods.
            app(MtcSampleDataSeeder::class)->setContainer(app())->run();
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('toast', message: __('Loading the sample data failed: :m', ['m' => $e->getMessage()]), type: 'error');
            return;
        }

        $registry = app(SampleRegistry::class);
        $this->report = ['action' => 'loaded', 'skipped' => $registry->skipped];
        $this->dispatch('toast', message: __('Sample data loaded.'), type: 'success');
    }

    public function purge(): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);

        try {
            $result = app(SampleRegistry::class)->purge('mtc');
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('toast', message: __('Purge failed, nothing was changed: :m', ['m' => $e->getMessage()]), type: 'error');
            return;
        }

        $this->report = ['action' => 'purged'] + $result;
        $this->dispatch('toast', message: __('Sample data purged.'), type: 'success');
    }

    public function with(): array
    {
        $registry = app(SampleRegistry::class);

        return [
            'counts' => $registry->counts(),
            'loaded' => $registry->hasSamples(),
            'production' => app()->isProduction(),
        ];
    }
}; ?>

<div class="space-y-6 max-w-3xl">
    <div>
        <h1 class="page-title">{{ __('Sample Data') }}</h1>
        <p class="text-sm text-zinc-500 mt-1">
            {{ __('An optional demo dataset based on the Ministry of Transport and Communications (MTC) of Timor-Leste. The application itself works for any institution; load this only to explore or train, and purge it before going live.') }}
        </p>
    </div>

    @if($production && $loaded)
        <div class="alert alert-error !items-start">
            <div>
                <p class="font-semibold">{{ __('Sample data is present in a production system.') }}</p>
                <p class="text-xs font-normal mt-1">{{ __('It includes demo accounts with a known password. Purge it now.') }}</p>
            </div>
        </div>
    @endif

    <x-ui.card>
        <div class="flex items-center justify-between gap-4">
            <div>
                <p class="text-sm font-medium text-zinc-900">{{ __('Status') }}</p>
                <div class="mt-1">
                    @if($loaded)
                        <x-ui.badge variant="amber">{{ __('Sample data loaded') }}</x-ui.badge>
                    @else
                        <x-ui.badge variant="gray">{{ __('Not loaded') }}</x-ui.badge>
                    @endif
                </div>
            </div>
            <div class="flex gap-3">
                @if(!$loaded && !$production)
                    <x-ui.button wire:click="load" wire:loading.attr="disabled" variant="primary"
                        wire:confirm="{{ __('Load the MTC sample data? It adds organisational units, programmes, demo accounts and illustrative activities.') }}">
                        <span wire:loading.remove wire:target="load">{{ __('Load sample data') }}</span>
                        <span wire:loading wire:target="load">{{ __('Loading…') }}</span>
                    </x-ui.button>
                @endif
                @if($loaded)
                    <x-ui.button wire:click="purge" wire:loading.attr="disabled" variant="danger"
                        wire:confirm="{{ __('Purge all sample data? Demo accounts, sample units, programmes and activities will be deleted. Real data is never touched.') }}">
                        <span wire:loading.remove wire:target="purge">{{ __('Purge sample data') }}</span>
                        <span wire:loading wire:target="purge">{{ __('Purging…') }}</span>
                    </x-ui.button>
                @endif
            </div>
        </div>

        @if($production && !$loaded)
            <p class="text-xs text-zinc-500 mt-4">{{ __('Loading sample data is disabled in production.') }}</p>
        @endif

        @if($loaded)
            <dl class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-6 text-center">
                <div><dd class="text-2xl font-bold text-zinc-900">{{ $counts['directorates'] }}</dd><dt class="text-xs text-zinc-500">{{ __('Organisational units') }}</dt></div>
                <div><dd class="text-2xl font-bold text-zinc-900">{{ $counts['programs'] }}</dd><dt class="text-xs text-zinc-500">{{ __('Programmes') }}</dt></div>
                <div><dd class="text-2xl font-bold text-zinc-900">{{ $counts['users'] }}</dd><dt class="text-xs text-zinc-500">{{ __('Demo accounts') }}</dt></div>
                <div><dd class="text-2xl font-bold text-zinc-900">{{ $counts['activities'] }}</dd><dt class="text-xs text-zinc-500">{{ __('Sample activities') }}</dt></div>
            </dl>
        @endif
    </x-ui.card>

    @if($report)
        <x-ui.card>
            @if($report['action'] === 'purged')
                <p class="text-sm font-semibold text-zinc-900">{{ __('Purged') }}</p>
                <p class="text-sm text-zinc-600 mt-1">
                    {{ __(':a activities, :u accounts, :d units and :p programmes deleted.', ['a' => $report['deleted']['activities'], 'u' => $report['deleted']['users'], 'd' => $report['deleted']['directorates'], 'p' => $report['deleted']['programs']]) }}
                </p>
                @if(count($report['kept']))
                    <p class="text-sm font-medium text-amber-700 mt-3">{{ __('Kept because real data uses them (they are now ordinary records):') }}</p>
                    <ul class="list-disc pl-5 text-sm text-zinc-600">@foreach($report['kept'] as $k)<li>{{ $k }}</li>@endforeach</ul>
                @endif
            @else
                <p class="text-sm font-semibold text-zinc-900">{{ __('Loaded') }}</p>
                @if(count($report['skipped']))
                    <p class="text-sm font-medium text-amber-700 mt-2">{{ __('Left untouched because they already exist as real data:') }}</p>
                    <ul class="list-disc pl-5 text-sm text-zinc-600">@foreach($report['skipped'] as $k)<li>{{ $k }}</li>@endforeach</ul>
                @endif
            @endif
        </x-ui.card>
    @endif

    <x-ui.card>
        <h2 class="text-sm font-semibold text-zinc-900 mb-2">{{ __('What it contains') }}</h2>
        <ul class="list-disc pl-5 text-sm text-zinc-600 space-y-1">
            <li>{{ __('The 28 MTC entities from Decreto-Lei 75/2023 as amended by 12/2026 (no departments are invented).') }}</li>
            <li>{{ __('7 working programmes with placeholder codes MTC-001 to MTC-007, not official OGE codes.') }}</li>
            <li>{{ __('Demo accounts (@goku.tl, password "password"): a Minister, and a Planner and a Director per unit.') }}</li>
            <li>{{ __('19 illustrative 2027 activities marked [EXEMPLO]. Every amount, target and date is invented.') }}</li>
        </ul>
        <p class="text-xs text-zinc-500 mt-3">{{ __('Purging deletes only what the sample created, and keeps any sample unit or programme that real data already uses.') }}</p>
    </x-ui.card>
</div>
