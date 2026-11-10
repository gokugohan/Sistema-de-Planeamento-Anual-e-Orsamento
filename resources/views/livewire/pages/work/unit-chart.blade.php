<?php

use App\Models\Directorate;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\UnitWorkspace;
use App\Support\Branding;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * The organization chart: the units the person may see, as boxes under the institution, each with its kind and its
 * head. An extra view of the structure the plan is built on (the Units page is where the work is). Each top-level unit
 * is a column with the units below it, so the chart stays readable however many units an institution has; the
 * autonomous bodies (indirect administration) are a section of their own. Optionally it shows how each unit's plan of
 * the year is doing. Opening a box opens that unit's workspace.
 */
new class extends Component {
    /** Show each unit's plan of the year (its light and number of activities) */
    #[Url(as: 'plan')]
    public bool $withPlan = false;

    public function mount(): void
    {
        $u = auth()->user();
        abort_unless($u->isAdministrator() || $u->can('view_all_paa') || $u->isDirector(), 403);
    }

    public function with(): array
    {
        $year = \App\Support\FiscalYear::current();
        $rows = collect(app(UnitWorkspace::class)->tree(auth()->user(), $year));
        $units = Directorate::whereIn('id', $rows->pluck('id'))->get(['id', 'type', 'administration', 'legal_form', 'director_name'])->keyBy('id');

        // who heads each unit: the people with the Director role placed in it, else the name on the unit's record
        $heads = User::role('Director')->whereIn('directorate_id', $rows->pluck('id'))->whereNull('deactivated_at')
            ->orderBy('name')->get(['id', 'name', 'directorate_id'])->groupBy('directorate_id');

        $nodes = $rows->map(function ($r) use ($units, $heads) {
            $u = $units[$r['id']] ?? null;

            return $r + [
                'type' => $u?->type,
                'autonomous' => $u?->isAutonomous() ?? false,
                'legal_form' => $u?->legal_form,
                'title' => $u ? $u->leaderTitle() : __('Head'),
                'head' => ($heads[$r['id']] ?? collect())->pluck('name')->implode(', ') ?: (string) $u?->director_name,
                'total' => (int) ($r['counts']['total'] ?? 0),
            ];
        });

        // the top of what the person sees (a head sees their own unit as the top), then each unit's children
        $ids = $nodes->pluck('id')->all();
        $roots = $nodes->filter(fn ($n) => ! $n['parent_id'] || ! in_array($n['parent_id'], $ids, true))->values();
        $children = $nodes->filter(fn ($n) => $n['parent_id'] && in_array($n['parent_id'], $ids, true))->groupBy('parent_id');

        return [
            'year' => $year,
            'organization' => SystemSetting::getLocalized('org_name', config('app.name')),
            'abbr' => SystemSetting::getLocalized('org_abbr', ''),
            'logo' => Branding::logoDataUri(),
            'own' => $roots->where('autonomous', false)->values(),
            'bodies' => $roots->where('autonomous', true)->values(),
            'children' => $children,
            'count' => $nodes->count(),
        ];
    }
}; ?>

@php
    $dot = ['none' => 'bg-zinc-300', 'ok' => 'bg-emerald-500', 'work' => 'bg-amber-500', 'alert' => 'bg-rose-500'];
    $lightLabel = ['none' => __('No activity yet'), 'ok' => __('All approved'), 'work' => __('Work in progress'), 'alert' => __('Needs correction')];
    $typeWord = fn ($n) => $n['autonomous'] ? __('Autonomous body').($n['legal_form'] ? ' ('.$n['legal_form'].')' : '') : ($n['type'] ? __($n['type']) : '');
@endphp

<div class="space-y-6">
    <x-ui.page-header :title="__('Organization chart')" :subtitle="trans_choice(':n unit of the institution, with its head.|:n units of the institution, each with its head.', $count, ['n' => $count])">
        <label class="inline-flex items-center gap-2 text-sm text-zinc-700">
            <input type="checkbox" wire:model.live="withPlan" class="rounded border-zinc-300 text-indigo-600">
            {{ __('Show the plan of :year', ['year' => $year]) }}
        </label>
        <x-ui.button variant="secondary" href="{{ route('units.index') }}" wire:navigate>{{ __('Units') }}</x-ui.button>
        <x-ui.button variant="secondary" onclick="window.print()">{{ __('Print') }}</x-ui.button>
    </x-ui.page-header>

    <div id="org-chart" class="org-chart">
        {{-- the institution --}}
        <div class="flex justify-center">
            <div class="org-top rounded-xl border-2 border-indigo-600 bg-white px-6 py-4 text-center shadow-card">
                @if($logo)<img src="{{ $logo }}" alt="" class="mx-auto mb-2 h-12">@endif
                <div class="text-xs font-medium uppercase tracking-wider text-zinc-500">República Democrática de Timor-Leste</div>
                <div class="mt-1 text-base font-semibold text-zinc-900">{{ $organization }}</div>
                @if($abbr)<div class="text-sm text-zinc-500">{{ $abbr }}</div>@endif
            </div>
        </div>

        @foreach([[__('Services of the institution'), $own, false], [__('Autonomous bodies'), $bodies, true]] as [$label, $roots, $autonomous])
            @continue($roots->isEmpty())
            <section class="mt-8" aria-label="{{ $label }}">
                @if($bodies->isNotEmpty())
                    <h2 class="mb-3 text-center text-xs font-semibold uppercase tracking-wider text-zinc-500">{{ $label }}</h2>
                @endif
                <div class="org-roots flex flex-wrap items-start justify-center gap-4">
                    @foreach($roots as $root)
                        <div class="org-col w-72 max-w-full">
                            @include('livewire.pages.work.partials.org-node', ['n' => $root, 'children' => $children, 'withPlan' => $withPlan, 'dot' => $dot, 'lightLabel' => $lightLabel, 'typeWord' => $typeWord, 'root' => true])
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach

        @if($count === 0)
            <x-ui.empty-state :title="__('No units yet')" :text="__('The units of the institution are set in Management.')" />
        @endif
    </div>

    <style>
        .org-chart .org-kids { position: relative; margin-left: 1.1rem; padding-left: 1rem; border-left: 1.5px solid var(--c-neutral-300, #D0D5DD); }
        .org-chart .org-kids > li { position: relative; padding-top: .5rem; }
        .org-chart .org-kids > li::before { content: ""; position: absolute; left: -1rem; top: 1.6rem; width: 1rem; border-top: 1.5px solid var(--c-neutral-300, #D0D5DD); }
        .org-chart .org-kids > li:last-child::after { content: ""; position: absolute; left: -1.1rem; top: 1.65rem; bottom: 0; width: 3px; background: var(--surface, #fff); }
        @media print {
            @page { size: A4 landscape; margin: 10mm; }
            body * { visibility: hidden; }
            #org-chart, #org-chart * { visibility: visible; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            #org-chart { position: absolute; left: 0; top: 0; width: 100%; }
            .org-col { break-inside: avoid; }
        }
    </style>
</div>
