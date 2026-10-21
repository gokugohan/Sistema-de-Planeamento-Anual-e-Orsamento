<?php

use App\Services\ProgrammeOverview;
use Livewire\Volt\Component;

/**
 * The programmes of the plan with their sub-programmes: how many activities each has, how many are approved, and the
 * amounts planned, in force and executed. A directorate sees the figures of its own units only. Reading only: programmes
 * are set up by an administrator under Administration → Structure.
 */
new class extends Component {
    public string $search = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('view_paa'), 403);
    }

    public function with(): array
    {
        $year = \App\Support\FiscalYear::current();
        $data = app(ProgrammeOverview::class)->build(auth()->user(), $year);
        $q = mb_strtolower(trim($this->search));
        if ($q !== '') {
            $data['programmes'] = array_values(array_filter($data['programmes'], fn ($p) => str_contains(mb_strtolower($p['code'].' '.$p['name']), $q)
                || collect($p['subs'])->contains(fn ($s) => str_contains(mb_strtolower($s['code'].' '.$s['name']), $q))));
        }

        return $data + ['year' => $year, 'canEdit' => auth()->user()->isAdministrator()];
    }
}; ?>

@php
    $money = fn ($v) => '$ '.number_format((float) $v, 0);
    $pct = fn ($part, $whole) => $whole > 0 ? min(100, round($part / $whole * 100)) : 0;
@endphp

<div class="space-y-6">
    <x-ui.page-header :title="__('Programmes')" :subtitle="$scoped
        ? __('The programmes and sub-programmes your units plan activities under, with the amounts of your units only. :year', ['year' => $year])
        : __('Every programme and sub-programme of the plan, with its activities and amounts. :year', ['year' => $year])">
        @if($canEdit)
            <x-ui.button variant="secondary" size="md" shadow="none" href="{{ route('management.entities') }}" wire:navigate>{{ __('Edit programmes') }}</x-ui.button>
        @endif
    </x-ui.page-header>

    <div class="grid gap-3 sm:grid-cols-4">
        <x-ui.card padding="p-4"><p class="text-xs text-zinc-500">{{ __('Activities') }}</p><p class="mt-1 text-xl font-semibold tabular-nums text-zinc-900">{{ $totals['activities'] }}</p><p class="text-xs text-zinc-500">{{ __(':n approved', ['n' => $totals['approved_activities']]) }}</p></x-ui.card>
        <x-ui.card padding="p-4"><p class="text-xs text-zinc-500">{{ __('Planned') }}</p><p class="mt-1 text-xl font-semibold tabular-nums text-zinc-900">{{ $money($totals['planned']) }}</p></x-ui.card>
        <x-ui.card padding="p-4"><p class="text-xs text-zinc-500">{{ __('Approved plan amount') }}</p><p class="mt-1 text-xl font-semibold tabular-nums text-zinc-900">{{ $money($totals['in_force']) }}</p></x-ui.card>
        <x-ui.card padding="p-4"><p class="text-xs text-zinc-500">{{ __('Executed (verified)') }}</p><p class="mt-1 text-xl font-semibold tabular-nums text-zinc-900">{{ $money($totals['executed']) }}</p><p class="text-xs text-zinc-500">{{ $pct($totals['executed'], $totals['in_force']) }}% {{ __('of the approved plan amount') }}</p></x-ui.card>
    </div>

    <div>
        <label for="pg-q" class="sr-only">{{ __('Search') }}</label>
        <input id="pg-q" type="search" wire:model.live.debounce.300ms="search" class="flux-input sm:max-w-xs" placeholder="{{ __('Search a programme or sub-programme') }}">
    </div>

    @if(count($programmes) === 0)
        <x-ui.card padding="p-0"><x-ui.empty-state :title="__('No programme to show')" :description="$scoped ? __('None of your units has planned activities for this year yet.') : __('No programme is registered for this year.')" /></x-ui.card>
    @else
        <div class="space-y-3">
            @foreach($programmes as $p)
                <details class="group rounded-lg border border-zinc-200 bg-white shadow-card" wire:key="prog-{{ $p['id'] }}" @if(count($programmes) <= 3 || $search !== '') open @endif>
                    <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3 px-5 py-4">
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold text-zinc-900">{{ $p['code'] }} · {{ $p['name'] }}</span>
                            <span class="block text-xs text-zinc-500">{{ trans_choice(':n sub-programme|:n sub-programmes', count(array_filter($p['subs'], fn ($s) => $s['id'])), ['n' => count(array_filter($p['subs'], fn ($s) => $s['id']))]) }} · {{ trans_choice(':n activity|:n activities', $p['activities'], ['n' => $p['activities']]) }} · {{ __(':n approved', ['n' => $p['approved_activities']]) }}</span>
                        </span>
                        <span class="flex items-center gap-5 text-right text-sm tabular-nums">
                            <span><span class="block text-xs text-zinc-500">{{ __('Planned') }}</span>{{ $money($p['planned']) }}</span>
                            <span><span class="block text-xs text-zinc-500">{{ __('Approved plan amount') }}</span>{{ $money($p['in_force']) }}</span>
                            <span><span class="block text-xs text-zinc-500">{{ __('Executed (verified)') }}</span>{{ $money($p['executed']) }}</span>
                        </span>
                    </summary>
                    <div class="border-t border-zinc-100">
                        @if($p['objective'])<p class="px-5 pt-3 text-sm text-zinc-600">{{ $p['objective'] }}</p>@endif
                        <table class="mt-2 w-full text-sm">
                            <thead class="text-left text-xs text-zinc-500">
                                <tr><th class="px-5 py-2 font-medium">{{ __('Sub-programme') }}</th><th class="px-3 py-2 text-right font-medium">{{ __('Activities') }}</th><th class="px-3 py-2 text-right font-medium">{{ __('Planned') }}</th><th class="px-3 py-2 text-right font-medium">{{ __('Approved plan amount') }}</th><th class="px-3 py-2 text-right font-medium">{{ __('Executed (verified)') }}</th><th class="px-5 py-2"></th></tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100">
                                @foreach($p['subs'] as $s)
                                    <tr wire:key="sub-{{ $p['id'] }}-{{ $s['id'] ?? 0 }}">
                                        <td class="px-5 py-2.5 text-zinc-900">{{ $s['id'] ? $s['code'].' · '.$s['name'] : __('Directly under the programme') }}</td>
                                        <td class="px-3 py-2.5 text-right tabular-nums">{{ $s['activities'] }} <span class="text-xs text-zinc-500">({{ $s['approved_activities'] }})</span></td>
                                        <td class="px-3 py-2.5 text-right tabular-nums">{{ $money($s['planned']) }}</td>
                                        <td class="px-3 py-2.5 text-right tabular-nums">{{ $money($s['in_force']) }}</td>
                                        <td class="px-3 py-2.5 text-right tabular-nums">{{ $money($s['executed']) }}</td>
                                        <td class="px-5 py-2.5 text-right">
                                            @if($s['activities'] > 0)
                                                <a href="{{ route('activities.index', array_filter(['programme' => $p['id'], 'subprogramme' => $s['id']])) }}" wire:navigate class="text-xs font-medium text-indigo-700 hover:underline">{{ __('Open activities') }}</a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        @if($p['activities'] > 0)
                            <p class="px-5 py-3 text-right"><a href="{{ route('activities.index', ['programme' => $p['id']]) }}" wire:navigate class="text-xs font-medium text-indigo-700 hover:underline">{{ __('Open all activities of this programme') }}</a></p>
                        @endif
                    </div>
                </details>
            @endforeach
        </div>
    @endif
</div>
