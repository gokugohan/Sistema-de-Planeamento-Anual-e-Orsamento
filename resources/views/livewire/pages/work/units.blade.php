<?php

use App\Services\UnitWorkspace;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Every unit the person may open, as the tree of the institution with how each is doing this year. It is how an
 * administrator, the Minister or a head of directorate finds the action plan of a directorate, a department or a
 * unit: pick it here and its workspace opens.
 */
new class extends Component {
    #[Url(as: 'q')]
    public string $search = '';

    /** '': every unit; or one light: alert | work | ok | none */
    #[Url(as: 'show')]
    public string $show = '';

    public function mount(): void
    {
        $u = auth()->user();
        abort_unless($u->isAdministrator() || $u->can('view_all_paa') || $u->isDirector(), 403);
    }

    public function with(): array
    {
        $year = \App\Support\FiscalYear::current();
        $rows = app(UnitWorkspace::class)->tree(auth()->user(), $year);
        $totals = ['alert' => 0, 'work' => 0, 'ok' => 0, 'none' => 0];
        foreach ($rows as $r) {
            $totals[$r['light']]++;
        }
        $q = mb_strtolower(trim($this->search));
        if ($q !== '' || $this->show !== '') {
            $rows = array_values(array_filter($rows, fn ($r) => ($this->show === '' || $r['light'] === $this->show)
                && ($q === '' || str_contains(mb_strtolower($r['code'].' '.$r['name']), $q))));
        }

        return ['rows' => $rows, 'year' => $year, 'totals' => $totals, 'filtering' => $q !== '' || $this->show !== ''];
    }
}; ?>

@php
    $lightTone = ['none' => 'bg-zinc-100 text-zinc-600', 'ok' => 'bg-emerald-50 text-emerald-800', 'work' => 'bg-amber-50 text-amber-800', 'alert' => 'bg-rose-50 text-rose-800'];
    $lightLabel = ['none' => __('No activity yet'), 'ok' => __('All approved'), 'work' => __('Work in progress'), 'alert' => __('Needs correction')];
    $dot = ['none' => 'bg-zinc-300', 'ok' => 'bg-emerald-500', 'work' => 'bg-amber-500', 'alert' => 'bg-rose-500'];
@endphp

<div class="space-y-6">
    <x-ui.page-header :title="__('Units')" :subtitle="__('The action plan of every directorate, department and unit for :year. Open one to see its plan, what waits for whom and how the reporting is going.', ['year' => $year])" />

    <x-ui.card padding="p-4">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-[14rem] flex-1">
                <label for="un-q" class="field-label">{{ __('Find a unit') }}</label>
                <input id="un-q" type="search" wire:model.live.debounce.250ms="search" class="flux-input" placeholder="{{ __('Code or name') }}">
            </div>
            <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('Filter by state') }}">
                <button type="button" wire:click="$set('show', '')" class="rounded-full px-3 py-1.5 text-xs font-medium ring-1 {{ $show === '' ? 'bg-zinc-900 text-white ring-zinc-900' : 'bg-white text-zinc-700 ring-zinc-300' }}">{{ __('All') }}</button>
                @foreach(['alert', 'work', 'ok', 'none'] as $k)
                    <button type="button" wire:click="$set('show', '{{ $k }}')" class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-medium ring-1 {{ $show === $k ? 'bg-zinc-900 text-white ring-zinc-900' : 'bg-white text-zinc-700 ring-zinc-300' }}"><span class="h-1.5 w-1.5 rounded-full {{ $dot[$k] }}" aria-hidden="true"></span>{{ $lightLabel[$k] }} · {{ $totals[$k] }}</button>
                @endforeach
            </div>
        </div>
    </x-ui.card>

    <x-ui.card padding="p-0">
        @if(! $rows)
            <x-ui.empty-state :title="__('No unit found')" :text="__('Try another name, or clear the filter.')" />
        @else
            @php
                // while looking at everything the units are grouped under their directorate (open where something needs attention);
                // when searching or filtering they are a flat list
                $groups = [];
                if ($filtering) {
                    $groups = array_map(fn ($r) => ['head' => $r, 'rest' => []], $rows);
                } else {
                    foreach ($rows as $r) {
                        if ($r['depth'] === 0) { $groups[] = ['head' => $r, 'rest' => []]; } else { $groups[array_key_last($groups)]['rest'][] = $r; }
                    }
                }
                $line = function ($r, $pad) use ($lightTone, $lightLabel, $dot) {
                    return view('work.unit-line', ['r' => $r, 'pad' => $pad, 'lightTone' => $lightTone, 'lightLabel' => $lightLabel, 'dot' => $dot])->render();
                };
            @endphp
            <div class="divide-y divide-zinc-100">
                @foreach($groups as $g)
                    @php $needs = collect(array_merge([$g['head']], $g['rest']))->contains(fn ($x) => in_array($x['light'], ['alert', 'work'], true)); @endphp
                    @if($g['rest'])
                        <details @if($needs) open @endif class="group">
                            <summary class="flex cursor-pointer list-none items-center gap-2 hover:bg-zinc-50">
                                <span class="pl-4 text-zinc-400 transition group-open:rotate-90" aria-hidden="true">›</span>
                                <span class="min-w-0 flex-1">{!! $line($g['head'], 0.5) !!}</span>
                            </summary>
                            <div class="divide-y divide-zinc-100 border-t border-zinc-100 bg-zinc-50/40">
                                @foreach($g['rest'] as $r){!! $line($r, 3 + ($r['depth'] - 1) * 1.5) !!}@endforeach
                            </div>
                        </details>
                    @else
                        {!! $line($g['head'], 2.25) !!}
                    @endif
                @endforeach
            </div>
        @endif
    </x-ui.card>
</div>
