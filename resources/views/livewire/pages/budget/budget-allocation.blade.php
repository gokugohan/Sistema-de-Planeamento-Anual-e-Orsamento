<?php

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\FiscalEnvelope;
use App\Models\Program;
use Livewire\Volt\Component;

/**
 * How the fiscal envelope was shared for the selected fiscal year.
 *
 * Only real figures: the approved plan (what units asked for and had approved) and
 * the distributed amount (adjusted_budgets). This system does not track spending,
 * so nothing here reports "used" or "remaining" money.
 */
new class extends Component {
    public string $tab = 'unit'; // unit | category | program

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['unit', 'category', 'program'], true) ? $tab : 'unit';
    }

    public function with(): array
    {
        $year = FiscalEnvelope::getEffectiveYear();
        $env = FiscalEnvelope::where('fiscal_year', $year)->with('categoryAllocations')->first();

        // One grouped query: approved plan and distributed amount by unit, programme and category.
        $rows = Activity::withoutGlobalScopes()
            ->where('activities.is_test_data', false)
            ->where('activities.fiscal_year', $year)
            ->where('activities.status', Activity::STATUS_APPROVED)
            ->leftJoin('line_items', fn ($j) => $j->on('line_items.activity_id', '=', 'activities.id')->where('line_items.is_test_data', false))
            ->leftJoin('adjusted_budgets', 'adjusted_budgets.line_item_id', '=', 'line_items.id')
            ->groupBy('activities.directorate_id', 'activities.program_id', 'line_items.economic_classification_id')
            ->selectRaw('activities.directorate_id as d, activities.program_id as p, line_items.economic_classification_id as c,
                COUNT(DISTINCT activities.id) as n,
                COALESCE(SUM(line_items.estimated_amount), 0) as plan, COALESCE(SUM(adjusted_budgets.approved_amount), 0) as alloc')
            ->get();

        $plan = (float) $rows->sum('plan');
        $allocated = (float) $rows->sum('alloc');
        $envelope = $env ? (float) $env->total_amount : null;

        $dirs = Directorate::get(['id', 'code', 'name', 'parent_id', 'administration', 'legal_form'])->keyBy('id');
        $acts = fn ($group) => Activity::withoutGlobalScopes()->where('is_test_data', false)->where('fiscal_year', $year)
            ->where('status', Activity::STATUS_APPROVED)->selectRaw("$group as g, COUNT(*) as n")->groupBy($group)->pluck('n', 'g');

        // by unit: top-level units, each with its own children underneath
        $byDir = $rows->groupBy('d')->map(fn ($g) => ['plan' => (float) $g->sum('plan'), 'alloc' => (float) $g->sum('alloc')]);
        $dirActs = $acts('directorate_id');
        $units = [];
        foreach ($dirs->filter(fn ($d) => !$d->parent_id)->sortBy('code') as $root) {
            $children = $dirs->filter(fn ($d) => $d->parent_id == $root->id)->sortBy('code');
            $line = fn ($d) => ['code' => $d->code, 'name' => $d->name, 'n' => (int) ($dirActs[$d->id] ?? 0),
                'plan' => $byDir[$d->id]['plan'] ?? 0.0, 'alloc' => $byDir[$d->id]['alloc'] ?? 0.0];
            $self = $line($root);
            $kids = $children->map($line)->values()->all();
            $total = ['plan' => $self['plan'] + array_sum(array_column($kids, 'plan')), 'alloc' => $self['alloc'] + array_sum(array_column($kids, 'alloc')),
                'n' => $self['n'] + array_sum(array_column($kids, 'n'))];
            if ($total['plan'] > 0 || $total['n'] > 0) {
                $units[] = ['autonomous' => $root->isAutonomous(), 'legal_form' => $root->legal_form, 'root' => $self + ['total' => $total], 'children' => array_values(array_filter($kids, fn ($k) => $k['n'] > 0 || $k['plan'] > 0))];
            }
        }
        usort($units, fn ($a, $b) => $b['root']['total']['plan'] <=> $a['root']['total']['plan']);

        // by category
        $classes = EconomicClassification::orderBy('code')->get(['id', 'code', 'name']);
        $byClass = $rows->groupBy('c')->map(fn ($g) => ['plan' => (float) $g->sum('plan'), 'alloc' => (float) $g->sum('alloc')]);
        $categories = $classes->map(fn ($c) => [
            'code' => $c->code, 'name' => $c->name,
            'envelope' => (float) ($env?->categoryAllocations->firstWhere('economic_classification_id', $c->id)?->allocated_amount ?? 0),
            'plan' => $byClass[$c->id]['plan'] ?? 0.0, 'alloc' => $byClass[$c->id]['alloc'] ?? 0.0,
        ])->all();

        // by programme
        $progActs = $acts('program_id');
        $byProg = $rows->groupBy('p')->map(fn ($g) => ['plan' => (float) $g->sum('plan'), 'alloc' => (float) $g->sum('alloc')]);
        $programs = Program::forYear($year)->orderBy('oge_code')->get(['id', 'oge_code', 'name'])->map(fn ($p) => [
            'code' => $p->oge_code, 'name' => $p->name, 'n' => (int) ($progActs[$p->id] ?? 0),
            'plan' => $byProg[$p->id]['plan'] ?? 0.0, 'alloc' => $byProg[$p->id]['alloc'] ?? 0.0,
        ])->filter(fn ($p) => $p['n'] > 0 || $p['plan'] > 0)->sortByDesc('plan')->values()->all();

        return compact('year', 'env', 'envelope', 'plan', 'allocated', 'units', 'categories', 'programs');
    }
}; ?>

@php
    $money = fn ($v) => $v > 0 ? '$ ' . number_format($v) : '—';
    $bar = function ($alloc, $plan) {
        if ($plan <= 0) return '<span class="text-zinc-400">—</span>';
        $pct = min(100, $alloc / $plan * 100);
        $color = $pct >= 99.5 ? 'bg-emerald-500' : 'bg-amber-500';
        return '<div class="flex items-center gap-2"><div class="h-1.5 flex-1 rounded-full bg-zinc-100 overflow-hidden"><div class="h-full rounded-full ' . $color . '" style="width:' . $pct . '%"></div></div><span class="w-10 text-right text-xs tabular-nums text-zinc-600">' . number_format($pct, 0) . '%</span></div>';
    };
    $unallocated = $envelope !== null ? $envelope - $allocated : null;
@endphp

<div class="space-y-6">
    <div>
        <h1 class="page-title">{{ __('Budget Allocation') }}</h1>
        <p class="page-subtitle">{{ __('How the fiscal envelope was shared over the approved plan') }} · {{ __('Fiscal year') }} {{ $year }}</p>
    </div>

    @if(!$env)
        <x-ui.card>
            <div class="text-center py-10 max-w-lg mx-auto">
                <h2 class="text-base font-semibold text-zinc-900">{{ __('No fiscal envelope registered for :year yet', ['year' => $year]) }}</h2>
                <p class="mt-1 text-sm text-zinc-500">{{ __('The envelope is the amount received after the State Budget law is promulgated. Until it is registered and distributed, only the approved plan below is available.') }}</p>
                <x-ui.button variant="primary" class="mt-5" href="{{ route('budget.envelope.current') }}">{{ __('Go to fiscal envelope') }}</x-ui.button>
            </div>
        </x-ui.card>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.kpi :label="__('Fiscal envelope')" :value="$envelope !== null ? '$ ' . number_format($envelope) : '—'" :sub="$env ? __($env->status) : __('Not registered yet')" tone="zinc" href="{{ route('budget.envelope.current') }}" />
        <x-ui.kpi :label="__('Approved plan')" :value="'$ ' . number_format($plan)" :sub="__('Budget lines of approved activities')" :progress="$envelope ? min(100, $plan / max($envelope, 1) * 100) : null" :tone="($envelope && $plan > $envelope) ? 'rose' : 'indigo'" />
        <x-ui.kpi :label="__('Distributed')" :value="'$ ' . number_format($allocated)" :sub="$plan > 0 ? number_format(min(100, $allocated / $plan * 100), 1) . '% ' . __('of the approved plan') : '—'" :progress="$plan > 0 ? min(100, $allocated / $plan * 100) : null" tone="emerald" />
        <x-ui.kpi :label="$unallocated !== null && $unallocated < 0 ? __('Over the envelope') : __('Unallocated')" :value="$unallocated !== null ? '$ ' . number_format(abs($unallocated)) : '—'"
            :sub="$unallocated === null ? __('Needs an envelope') : ($unallocated < 0 ? __('The plan asks for more than the envelope') : __('Envelope not yet shared'))" :tone="$unallocated !== null && $unallocated < 0 ? 'rose' : 'zinc'" />
    </div>

    {{-- Tabs --}}
    <div class="border-b border-zinc-200" role="tablist" aria-label="{{ __('View by') }}">
        @foreach(['unit' => __('By unit'), 'category' => __('By economic category'), 'program' => __('By programme')] as $key => $label)
            <button role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="setTab('{{ $key }}')"
                class="px-4 py-2.5 text-sm font-medium -mb-px border-b-2 transition-colors {{ $tab === $key ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-zinc-500 hover:text-zinc-800' }}">{{ $label }}</button>
        @endforeach
    </div>

    <x-ui.card padding="p-0">
        @if($tab === 'unit')
            <x-ui.table :headers="[__('Unit'), __('Activities'), __('Approved plan'), __('Distributed'), __('Funded')]">
                @php $sections = collect($units)->groupBy(fn ($u) => $u['autonomous'] ? 1 : 0)->sortKeys(); @endphp
                @forelse($sections as $isAuto => $group)
                    @if($sections->count() > 1)
                        @php
                            $sub = ['n' => collect($group)->sum('root.total.n'), 'plan' => collect($group)->sum('root.total.plan'), 'alloc' => collect($group)->sum('root.total.alloc')];
                        @endphp
                        <tr><td colspan="5" class="px-6 pt-5 pb-2 text-xs font-semibold text-zinc-500">{{ $isAuto ? __('Transfers to autonomous bodies (indirect administration)') : __('Ministry services') }}</td></tr>
                    @endif
                    @foreach($group as $u)
                    @php $r = $u['root']; $t = $r['total']; @endphp
                    <tr class="bg-zinc-50">
                        <td class="px-6 py-3 text-sm"><span class="font-semibold text-zinc-900">{{ $r['code'] }}</span> <span class="text-zinc-600">{{ $r['name'] }}</span>@if($u['autonomous']) <x-ui.badge variant="indigo">{{ __('Autonomous') }}{{ $u['legal_form'] ? ' · '.$u['legal_form'] : '' }}</x-ui.badge>@endif</td>
                        <td class="px-6 py-3 text-sm tabular-nums">{{ $t['n'] }}</td>
                        <td class="px-6 py-3 text-sm font-semibold tabular-nums">{{ $money($t['plan']) }}</td>
                        <td class="px-6 py-3 text-sm font-semibold tabular-nums">{{ $money($t['alloc']) }}</td>
                        <td class="px-6 py-3 w-52">{!! $bar($t['alloc'], $t['plan']) !!}</td>
                    </tr>
                    @foreach($u['children'] as $k)
                        <tr>
                            <td class="pl-12 pr-6 py-2.5 text-sm"><span class="font-medium text-zinc-800">{{ $k['code'] }}</span> <span class="text-zinc-500">{{ $k['name'] }}</span></td>
                            <td class="px-6 py-2.5 text-sm tabular-nums text-zinc-600">{{ $k['n'] }}</td>
                            <td class="px-6 py-2.5 text-sm tabular-nums text-zinc-600">{{ $money($k['plan']) }}</td>
                            <td class="px-6 py-2.5 text-sm tabular-nums text-zinc-600">{{ $money($k['alloc']) }}</td>
                            <td class="px-6 py-2.5 w-52">{!! $bar($k['alloc'], $k['plan']) !!}</td>
                        </tr>
                    @endforeach
                    @endforeach
                    @if($sections->count() > 1)
                        <tr class="border-t border-zinc-200">
                            <td class="px-6 py-3 text-sm font-semibold text-zinc-700">{{ __('Subtotal') }}</td>
                            <td class="px-6 py-3 text-sm tabular-nums font-semibold">{{ $sub['n'] }}</td>
                            <td class="px-6 py-3 text-sm tabular-nums font-semibold">{{ $money($sub['plan']) }}</td>
                            <td class="px-6 py-3 text-sm tabular-nums font-semibold">{{ $money($sub['alloc']) }}</td>
                            <td class="px-6 py-3"></td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="5"><x-ui.empty-state :title="__('No approved activities with a budget for :year yet.', ['year' => $year])" /></td></tr>
                @endforelse
            </x-ui.table>
        @elseif($tab === 'category')
            <x-ui.table :headers="[__('Economic category'), __('Envelope'), __('Approved plan'), __('Distributed'), __('Funded')]">
                @foreach($categories as $c)
                    <tr>
                        <td class="px-6 py-3 text-sm"><span class="font-semibold text-zinc-900">{{ $c['code'] }}</span> <span class="text-zinc-600">{{ $c['name'] }}</span></td>
                        <td class="px-6 py-3 text-sm tabular-nums">{{ $money($c['envelope']) }}</td>
                        <td class="px-6 py-3 text-sm tabular-nums {{ $c['envelope'] > 0 && $c['plan'] > $c['envelope'] ? 'text-rose-600 font-medium' : '' }}">{{ $money($c['plan']) }}</td>
                        <td class="px-6 py-3 text-sm tabular-nums">{{ $money($c['alloc']) }}</td>
                        <td class="px-6 py-3 w-52">{!! $bar($c['alloc'], $c['plan']) !!}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        @else
            <x-ui.table :headers="[__('Programme'), __('Activities'), __('Approved plan'), __('Distributed'), __('Funded')]">
                @forelse($programs as $p)
                    <tr>
                        <td class="px-6 py-3 text-sm"><span class="font-semibold text-zinc-900">{{ $p['code'] }}</span> <span class="text-zinc-600">{{ $p['name'] }}</span></td>
                        <td class="px-6 py-3 text-sm tabular-nums">{{ $p['n'] }}</td>
                        <td class="px-6 py-3 text-sm tabular-nums">{{ $money($p['plan']) }}</td>
                        <td class="px-6 py-3 text-sm tabular-nums">{{ $money($p['alloc']) }}</td>
                        <td class="px-6 py-3 w-52">{!! $bar($p['alloc'], $p['plan']) !!}</td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-ui.empty-state :title="__('No approved activities with a budget for :year yet.', ['year' => $year])" /></td></tr>
                @endforelse
            </x-ui.table>
        @endif
    </x-ui.card>

    <p class="text-xs text-zinc-500">{{ __('Figures show planning and distribution. Spending is not tracked in this system, so no "used" or "remaining" amounts are shown.') }}</p>
</div>
