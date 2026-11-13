<?php

use App\Services\SampleRegistry;
use Database\Seeders\Samples\MtcBudgetSeeder;
use Database\Seeders\Samples\MtcDirectorateSeeder;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Database\Seeders\Samples\MtcSampleDataSeeder;
use Database\Seeders\Samples\OgeCatalog;
use Database\Seeders\Samples\OgeEntitySampleSeeder;
use Livewire\Volt\Component;

new class extends Component {
    public ?array $report = null;

    /** 'entity' (any entity of the State Budget 2027, the default), 'office' (one directorate/office of MTC) or 'ministry' (the whole MTC) */
    public string $scope = 'entity';

    public string $office = MtcOfficeSampleDataSeeder::DEFAULT_OFFICE;

    /** The State Budget entity to load (OgeCatalog) */
    public string $entity = OgeEntitySampleSeeder::DEFAULT_ENTITY;

    /** On purging, keep the sample's units as the institution's own structure */
    public bool $keepUnits = false;

    /** How far the plans of the current year and the next have gone (MtcBudgetSeeder::STATES) */
    public string $state = 'draft';

    /** The years to load, from and to (a year outside the sample takes the plan of the nearest sample year) */
    public int $fromYear = 0;

    public int $toYear = 0;

    public function mount(): void
    {
        $this->fromYear = (int) now()->year - 1;
        $this->toYear = (int) now()->year + 1;
        abort_unless(auth()->user()?->isAdministrator(), 403);
    }

    public function load(): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);

        if (app()->isProduction()) {
            $this->dispatch('toast', message: __('Sample data cannot be loaded in production.'), type: 'error');
            return;
        }
        abort_unless($this->scope !== 'entity' || OgeCatalog::has($this->entity), 422);

        try {
            // Seeders need the container to inject the registry into their run() methods.
            abort_unless(in_array($this->state, MtcBudgetSeeder::STATES, true), 422);
            if ($this->fromYear < 2000 || $this->toYear > (int) now()->year + 5 || $this->fromYear > $this->toYear || $this->toYear - $this->fromYear > 9) {
                $this->dispatch('toast', message: __('Choose a range of at most ten years, from 2000 to :max, with the first year not after the last.', ['max' => (int) now()->year + 5]), type: 'error');

                return;
            }
            $years = range($this->fromYear, $this->toYear);
            if ($this->scope === 'ministry') {
                app(MtcSampleDataSeeder::class)->setContainer(app())->run($this->state, $years);
            } elseif ($this->scope === 'entity') {
                app(OgeEntitySampleSeeder::class)->setContainer(app())->run($this->entity, $this->state, $years);
            } else {
                abort_unless(array_key_exists($this->office, MtcDirectorateSeeder::offices()), 422);
                app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run($this->office, $this->state, $years);
            }
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
            $result = app(SampleRegistry::class)->purge('mtc', $this->keepUnits);
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
            'offices' => MtcDirectorateSeeder::offices(),
            'entities' => OgeCatalog::grouped(),
            'chosen' => OgeCatalog::index()[$this->entity] ?? null,
            'chosenProgrammes' => $this->scope === 'entity' && OgeCatalog::has($this->entity)
                ? collect(OgeCatalog::entity($this->entity)['programmes'])->only(collect(OgeCatalog::entity($this->entity)['activities'])->pluck('programme')->map(fn ($c) => (string) $c)->unique()->all())->all()
                : [],
        ];
    }
}; ?>

<div class="space-y-6 max-w-3xl">
    <div>
        <h1 class="page-title">{{ __('Sample Data') }}</h1>
        <p class="page-subtitle">
            {{ __('Optional demo data taken from the published State Budget: any public entity of Timor-Leste, with its own units and annual plan, as if it were your institution. The application itself works for any institution; load demo data only to explore or train, and purge it before going live.') }}
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
            <div class="flex gap-3 items-end">
                @if(!$loaded && !$production)
                    <x-ui.button x-on:click="$dispatch('open-modal', 'confirm-load')" wire:loading.attr="disabled" variant="primary">
                        <span wire:loading.remove wire:target="load">{{ __('Load sample data') }}</span>
                        <span wire:loading wire:target="load">{{ __('Loading…') }}</span>
                    </x-ui.button>
                @endif
                @if($loaded)
                    <x-ui.button x-on:click="$dispatch('open-modal', 'confirm-purge')" wire:loading.attr="disabled" variant="danger">
                        <span wire:loading.remove wire:target="purge">{{ __('Purge sample data') }}</span>
                        <span wire:loading wire:target="purge">{{ __('Purging…') }}</span>
                    </x-ui.button>
                @endif
            </div>
        </div>

        @if(!$loaded && !$production)
            <fieldset class="mt-6 space-y-3 border-t border-zinc-100 pt-5">
                <legend class="text-sm font-medium text-zinc-900">{{ __('What to load') }}</legend>
                <label class="flex items-start gap-3 text-sm text-zinc-700">
                    <input type="radio" wire:model.live="scope" value="entity" class="mt-1">
                    <span>
                        <span class="font-medium text-zinc-900">{{ __('A public entity (State Budget 2027)') }}</span>
                        <span class="block text-xs text-zinc-500">{{ __('Any ministry, secretariat of state, municipal authority, institute or fund, with the units and the annual plan the State Budget 2027 prints for it.') }}</span>
                    </span>
                </label>
                @if($scope === 'entity')
                    <div class="ml-7 space-y-1">
                        <select wire:model.live="entity" aria-label="{{ __('Public entity') }}" class="w-full max-w-md rounded-md border-zinc-300 text-sm">
                            @foreach($entities as $kind => $group)
                                <optgroup label="{{ __(\Database\Seeders\Samples\OgeCatalog::KINDS[$kind]) }}">
                                    @foreach($group as $code => $e)
                                        <option value="{{ $code }}">{{ $code }} — {{ $e['name'] }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        @if($chosen)
                            <p class="text-xs text-zinc-500">{{ __(':units units · :n activities · $ :total planned for 2027', ['units' => $chosen['units'], 'n' => $chosen['activities'], 'total' => number_format($chosen['total'])]) }}</p>
                        @endif
                    </div>
                @endif
                <p class="pt-2 text-xs font-medium text-zinc-500">{{ __('The most complete example: the Ministry of Transport and Communications (MTC), with its organic law and its plans of 2025, 2026 and 2027') }}</p>
                <label class="flex items-start gap-3 text-sm text-zinc-700">
                    <input type="radio" wire:model.live="scope" value="ministry" class="mt-1">
                    <span>
                        <span class="font-medium text-zinc-900">{{ __('The whole ministry (MTC)') }}</span>
                        <span class="block text-xs text-zinc-500">{{ __('Every unit and autonomous body, with the full annual plans.') }}</span>
                    </span>
                </label>
                <label class="flex items-start gap-3 text-sm text-zinc-700">
                    <input type="radio" wire:model.live="scope" value="office" class="mt-1">
                    <span>
                        <span class="font-medium text-zinc-900">{{ __('One directorate or office of MTC') }}</span>
                        <span class="block text-xs text-zinc-500">{{ __('Only that unit and the units under it, with their own plans and accounts, as an institution of its own.') }}</span>
                    </span>
                </label>
                @if($scope === 'office')
                    <select wire:model="office" aria-label="{{ __('Directorate or office') }}"
                        class="ml-7 w-full max-w-md rounded-md border-zinc-300 text-sm">
                        @foreach($offices as $code => $name)
                            <option value="{{ $code }}">{{ $code }} — {{ $name }}</option>
                        @endforeach
                    </select>
                @endif
            </fieldset>

            <fieldset class="mt-6 space-y-3 border-t border-zinc-100 pt-5">
                <legend class="text-sm font-medium text-zinc-900">{{ __('Which years') }}</legend>
                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <label for="sample-from" class="field-label">{{ __('From year') }}</label>
                        <input id="sample-from" type="number" min="2000" max="{{ now()->year + 5 }}" wire:model.live="fromYear" class="flux-input !w-32">
                    </div>
                    <div>
                        <label for="sample-to" class="field-label">{{ __('To year') }}</label>
                        <input id="sample-to" type="number" min="2000" max="{{ now()->year + 5 }}" wire:model.live="toYear" class="flux-input !w-32">
                    </div>
                </div>
                @if($scope === 'entity')
                    <p class="text-xs text-zinc-500">{{ __('The State Budget books give the plan of 2027 for every entity: each year you load takes it, with its dates, programs, calendar and reported spending following the year you chose. Years before :current are loaded approved, with their spending reported.', ['current' => now()->year]) }}</p>
                @else
                <p class="text-xs text-zinc-500">{{ __('The sample holds the real plans of :years. Each year you load takes the plan of the same year, or of the nearest of these; its dates, programs, calendar and reported spending follow the year you chose. Years before :current are loaded approved, with their spending reported.', ['years' => implode(', ', \Database\Seeders\Samples\MtcBudgetSeeder::YEARS), 'current' => now()->year]) }}</p>
                @endif
            </fieldset>

            <fieldset class="mt-6 space-y-3 border-t border-zinc-100 pt-5">
                <legend class="text-sm font-medium text-zinc-900">{{ __('Stage of the demo plans for :current and :next', ['current' => now()->year, 'next' => now()->year + 1]) }}</legend>
                <p class="text-xs text-zinc-500">{{ __('Only the demo data is affected. Earlier years are always loaded approved, with their spending and progress reported, so that there is a finished year to read.') }}</p>
                @foreach([
                    'draft' => [__('Everything to do: all drafts'), __('Practise the whole cycle: units complete and submit, directors certify, heads approve and finalize, then the units report and others verify.')],
                    'halfway' => [__('Halfway through approval'), __('Each directorate at one stage, in turn: still drafting, submitted (one activity returned with a reason), certified and waiting for the final approval, approved. Every role finds work to do.')],
                    'approved' => [__('Approved and being carried out'), __('Every activity approved; the current year with its spending and progress reported up to today.')],
                ] as $value => [$label, $hint])
                    <label class="flex items-start gap-3 text-sm text-zinc-700">
                        <input type="radio" wire:model.live="state" value="{{ $value }}" class="mt-1">
                        <span>
                            <span class="font-medium text-zinc-900">{{ $label }}</span>
                            <span class="block text-xs text-zinc-500">{{ $hint }}</span>
                        </span>
                    </label>
                @endforeach
            </fieldset>
        @endif

        @if($loaded)
            <label class="mt-6 flex items-start gap-3 border-t border-zinc-100 pt-5 text-sm text-zinc-700">
                <input type="checkbox" wire:model.live="keepUnits" class="mt-1 rounded border-zinc-300 text-indigo-600">
                <span>
                    <span class="font-medium text-zinc-900">{{ __('When purging, keep the units as our structure') }}</span>
                    <span class="block text-xs text-zinc-500">{{ __('The units, with their codes, names and hierarchy, and the name of the institution stay as your own data. Activities, programmes, the calendar and the demo accounts (whose password is public) are still deleted, so the units have no people yet: add them in System Users.') }}</span>
                </span>
            </label>
        @endif

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
            @if($loaded)
                <li>{{ __('The institution shown in the application (its name), its units, programs, demo accounts and the plans of the years that were loaded.') }}</li>
            @elseif($scope === 'entity' && $chosen)
                <li>{{ __(':name becomes the institution shown in the application (its name; its logo stays yours to set in Settings).', ['name' => $chosen['name']]) }}</li>
                <li>{{ trans_choice(':n unit: the division the State Budget 2027 prints for it.|:n units: the divisions the State Budget 2027 prints for it, each under its general directorate where the budget codes show one (a reading of the codes, not of its organic law).', $chosen['units'], ['n' => $chosen['units']]) }}</li>
                <li>{{ __('Its programs, with their official State Budget codes:') }} {{ collect($chosenProgrammes)->map(fn ($name, $code) => $code.' '.$name)->implode('; ') }}.</li>
                <li>{{ __('Its 2027 annual plan as printed: :n activities with their official codes, indicators, starting points and targets, and amounts by economic category ($ :total), with the outcomes and outputs of its programs.', ['n' => $chosen['activities'], 'total' => number_format($chosen['total'])]) }}</li>
                <li>{{ $chosen['kind'] === 'ministry'
                    ? __('Demo accounts (…@gdsc.tl, password "password"): a Minister, a Technical Officer and a Director per unit, and a Finance officer per directorate.')
                    : __('Demo accounts (…@gdsc.tl, password "password"): a Technical Officer and a Director per unit, and a Finance officer per directorate.') }}</li>
            @elseif($scope === 'ministry')
                <li>{{ __('For the whole ministry: the MTC entities from Decreto-Lei 75/2023 as amended by 12/2026, the five autonomous bodies of its indirect administration, and the divisions the State Budget itself prints (municipal directorates, AACTL directorates).') }}</li>
                <li>{{ __('The six programmes of the State Budget used by the ministry, with their official OGE codes (502, 510, 558, 980, A08, A38), registered for each year of the sample.') }}</li>
                <li>{{ __('The real annual plans of 2025, 2026 and 2027 from the State Budget books (about 90 activities a year, with their official codes, indicators and amounts by economic category), for the years you choose: any other year takes the plan of the nearest of these.') }}</li>
                <li>{{ __('Demo accounts (@gdsc.tl, password "password"): a Minister, a Technical Officer and a Director per unit, and a Finance officer per directorate.') }}</li>
            @else
                <li>{{ __('For one office: only that unit, its sub-units and the activities the State Budget plans for it, with the same amounts as in the whole ministry.') }}</li>
                <li>{{ __('The real annual plans of 2025, 2026 and 2027 from the State Budget books (about 90 activities a year, with their official codes, indicators and amounts by economic category), for the years you choose: any other year takes the plan of the nearest of these.') }}</li>
                <li>{{ __('Demo accounts (…@gdsc.tl, password "password"): a head of the office, a Technical Officer and a Director per unit.') }}</li>
            @endif
            @unless($loaded)
                <li>{{ __('Years before the current one are approved, with monthly executed amounts and quarterly progress reported. The current year and the next are loaded as you choose above: as drafts, halfway through approval, or approved (the current year then with its spending reported up to today). Approved activities are version 1 of the plan.') }}</li>
            @endunless
        </ul>

        {{-- where the figures come from: the published State Budget books, nothing made up --}}
        <div class="mt-4 rounded-md border border-indigo-100 bg-indigo-50 px-4 py-3 text-sm text-zinc-700">
            <p class="font-semibold text-zinc-900">{{ __('Where the figures come from') }}</p>
            <p class="mt-1">
                @if(!$loaded && $scope === 'entity')
                    {{ __('The State Budget 2027 books published by the Ministry of Finance: the annual plans of Elementos Informativos PAA, Volumes II-A and II-B (Annex K), and the new Government Policy Priorities (PPG) of Volume X.') }}
                @else
                    {{ __('The State Budget books published by the Ministry of Finance: the annual plans of Elementos Informativos PAA (Annex K: Volume II for 2025, Volumes II-A and II-B for 2026 and 2027), and the new Government Policy Priorities (PPG) of Volume X, which mark the activities funded as a new policy.') }}
                @endif
            </p>
            <p class="mt-1">{{ __('Activities, codes, indicators, starting points, targets and amounts are as printed; nothing is made up. A unit or office loaded alone has the same amounts as in the whole ministry.') }}</p>
            <p class="mt-1 text-xs text-zinc-500">{{ __('The books do not print monthly spending or the steps of each activity, so the sample simulates those: the spending reported for past months and three generic steps per indicator (prepare, carry out, report).') }}</p>
        </div>

        <p class="text-xs text-zinc-500 mt-3">{{ __('Purging deletes only what the sample created, and keeps any sample unit or programme that real data already uses.') }}</p>
    </x-ui.card>
    <x-confirm-dialog name="confirm-load" :title="__('Load sample data')" :confirm="__('Load sample data')" wire:click="load"
        :message="match ($scope) {
            'ministry' => __('Load the MTC sample data? It adds organisational units, programmes, demo accounts and the activities of the State Budget books.'),
            'entity' => __('Load the demo data of :name? It becomes the institution shown, with its units, programmes, demo accounts and activities.', ['name' => $chosen['name'] ?? $entity]),
            default => __('Load the sample data for :office? It adds that office, its units, programmes, demo accounts and activities.', ['office' => $office]),
        }" />

    <x-confirm-dialog name="confirm-purge" :title="__('Purge sample data')" :confirm="__('Purge sample data')" variant="danger" wire:click="purge"
        :message="$keepUnits
            ? __('Purge the sample data but keep its units as your structure? Demo accounts, programmes, activities and the calendar will be deleted. Real data is never touched.')
            : __('Purge all sample data? Demo accounts, sample units, programmes and activities will be deleted. Real data is never touched.')" />
</div>
