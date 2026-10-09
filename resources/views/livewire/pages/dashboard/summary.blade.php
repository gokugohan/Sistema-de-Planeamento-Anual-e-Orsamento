<?php

use App\Models\Activity;
use App\Models\BudgetCycle;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\FiscalEnvelope;
use App\Models\Indicator;
use App\Models\SystemSetting;
use Livewire\Volt\Component;

/**
 * Executive dashboard for one fiscal year (the year chosen in the top bar).
 * Every figure comes from grouped SQL, so cost does not grow with the number of activities.
 */
new class extends Component {
    public $unit = '';      // top-level unit filter ('' = all)
    public $category = '';  // economic category filter ('' = all)

    public function clearFilters(): void
    {
        $this->unit = '';
        $this->category = '';
    }

    public function with(): array
    {
        $year = FiscalEnvelope::getEffectiveYear();
        $dirs = Directorate::get(['id', 'code', 'name', 'type', 'parent_id'])->keyBy('id');
        $rootOf = fn ($id) => ($dirs[$id]->parent_id ?? null) ?: $id;
        $classes = EconomicClassification::orderBy('code')->get(['id', 'code', 'name'])->keyBy('id');

        $unitIds = $this->unit !== ''
            ? $dirs->filter(fn ($d) => $d->id == $this->unit || $d->parent_id == $this->unit)->keys()->all()
            : null;
        $cat = $this->category !== '' ? (int) $this->category : null;

        $base = fn () => Activity::withoutGlobalScopes()
            ->where('activities.is_test_data', false)
            ->where('activities.fiscal_year', $year)
            ->when($unitIds, fn ($q) => $q->whereIn('activities.directorate_id', $unitIds))
            ->when($cat, fn ($q) => $q->whereExists(fn ($s) => $s->selectRaw('1')->from('line_items')
                ->whereColumn('line_items.activity_id', 'activities.id')
                ->where('line_items.is_test_data', false)
                ->where('line_items.economic_classification_id', $cat)));

        // ── counts: activities by unit and status ──
        $statusOrder = [Activity::STATUS_DRAFT, Activity::STATUS_SUBMITTED, Activity::STATUS_REVIEWED, Activity::STATUS_VALIDATED, Activity::STATUS_WAITING_APPROVAL, Activity::STATUS_APPROVED];
        $byStatus = array_fill_keys($statusOrder, 0);
        $unitStats = [];
        foreach ($base()->selectRaw('activities.directorate_id as d, activities.status as s, COUNT(*) as n')->groupBy('activities.directorate_id', 'activities.status')->get() as $r) {
            $root = $rootOf($r->d);
            $byStatus[$r->s] = ($byStatus[$r->s] ?? 0) + $r->n;
            $unitStats[$root]['n'] = ($unitStats[$root]['n'] ?? 0) + $r->n;
            if ($r->s === Activity::STATUS_APPROVED) {
                $unitStats[$root]['approved'] = ($unitStats[$root]['approved'] ?? 0) + $r->n;
            }
        }
        $totalActivities = array_sum($byStatus);

        // ── money: by unit, status and category ──
        $planAll = 0.0;
        $plan = 0.0;
        $allocated = 0.0;
        $planByClass = $allocByClass = [];
        foreach ($base()
            ->leftJoin('line_items', fn ($j) => $j->on('line_items.activity_id', '=', 'activities.id')->where('line_items.is_test_data', false))
            ->leftJoin('adjusted_budgets', 'adjusted_budgets.line_item_id', '=', 'line_items.id')
            ->when($cat, fn ($q) => $q->where('line_items.economic_classification_id', $cat))
            ->groupBy('activities.directorate_id', 'activities.status', 'line_items.economic_classification_id')
            ->selectRaw('activities.directorate_id as d, activities.status as s, line_items.economic_classification_id as c,
                COALESCE(SUM(line_items.estimated_amount), 0) as req, COALESCE(SUM(adjusted_budgets.approved_amount), 0) as alloc')
            ->get() as $r) {
            $planAll += $r->req;
            $allocated += $r->alloc;
            if ($r->s === Activity::STATUS_APPROVED) {
                $plan += $r->req;
                $root = $rootOf($r->d);
                $unitStats[$root]['plan'] = ($unitStats[$root]['plan'] ?? 0) + $r->req;
                $unitStats[$root]['alloc'] = ($unitStats[$root]['alloc'] ?? 0) + $r->alloc;
                if ($r->c) {
                    $planByClass[$r->c] = ($planByClass[$r->c] ?? 0) + $r->req;
                    $allocByClass[$r->c] = ($allocByClass[$r->c] ?? 0) + $r->alloc;
                }
            }
        }

        // ── the budget cycle for this year ──
        $cycle = BudgetCycle::where('fiscal_year', $year)->first();
        $env = FiscalEnvelope::where('fiscal_year', $year)->with('categoryAllocations')->first();
        $envelope = $env ? (float) $env->total_amount : null;
        $envApproved = $env && in_array($env->status, [FiscalEnvelope::STATUS_APPROVED, FiscalEnvelope::STATUS_CLOSED], true);
        $croTotal = $cycle?->cro_total !== null ? (float) $cycle->cro_total : null;
        $ceiling = $cycle?->indicative_ceiling !== null ? (float) $cycle->indicative_ceiling : null;

        $steps = [
            ['label' => __('Indicative ceiling'), 'done' => $ceiling !== null, 'value' => $ceiling],
            ['label' => __('Submitted to CRO'), 'done' => (bool) $cycle?->submitted_at, 'value' => $cycle?->submitted_total !== null ? (float) $cycle->submitted_total : null],
            ['label' => __('CRO decision'), 'done' => $croTotal !== null, 'value' => $croTotal],
            ['label' => __('Budget law'), 'done' => (bool) $env?->hasLegalBasis(), 'value' => null],
            ['label' => __('Envelope approved'), 'done' => $envApproved, 'value' => $envApproved ? $envelope : null],
            ['label' => __('Distributed'), 'done' => $envApproved && $allocated > 0, 'value' => $envApproved && $allocated > 0 ? $allocated : null],
        ];
        $currentStep = collect($steps)->search(fn ($s) => !$s['done']);

        // The best available "money in hand" figure and its honest label
        [$fundLabel, $fund] = match (true) {
            $envelope !== null => [__('Fiscal envelope'), $envelope],
            $croTotal !== null => [__('CRO decision'), $croTotal],
            $ceiling !== null => [__('Indicative ceiling'), $ceiling],
            default => [__('Fiscal envelope'), null],
        };

        // ── physical delivery: verified progress against annual targets ──
        $verified = fn ($q) => $q->where('status', 'Verified');
        $indicators = Indicator::whereIn('activity_id', $base()->select('activities.id'))
            ->where('annual_target', '>', 0)
            ->withSum(['progress as achieved' => $verified], 'achieved_value')
            ->withSum(['progress as q1' => fn ($q) => $verified($q)->where('quarter', 1)], 'achieved_value')
            ->withSum(['progress as q2' => fn ($q) => $verified($q)->where('quarter', 2)], 'achieved_value')
            ->withSum(['progress as q3' => fn ($q) => $verified($q)->where('quarter', 3)], 'achieved_value')
            ->withSum(['progress as q4' => fn ($q) => $verified($q)->where('quarter', 4)], 'achieved_value')
            ->get(['id', 'annual_target']);
        $achievement = $indicators->isEmpty() ? null : round($indicators->avg(fn ($i) => min(100, ($i->achieved / $i->annual_target) * 100)), 1);
        $targets = $indicators->sum('annual_target');
        $cumulative = 0;
        $quarterly = [];
        foreach ([1, 2, 3, 4] as $q) {
            $cumulative += $indicators->sum("q{$q}");
            $quarterly[] = $targets > 0 ? round($cumulative / $targets * 100, 1) : 0;
        }

        // ── units table, sorted by approved plan ──
        $units = [];
        foreach ($dirs->filter(fn ($d) => !$d->parent_id) as $d) {
            if ($this->unit !== '' && $d->id != $this->unit) {
                continue;
            }
            $s = $unitStats[$d->id] ?? [];
            $units[] = [
                'code' => $d->code, 'name' => $d->name, 'n' => $s['n'] ?? 0, 'approved' => $s['approved'] ?? 0,
                'plan' => $s['plan'] ?? 0.0, 'alloc' => $s['alloc'] ?? 0.0,
            ];
        }
        usort($units, fn ($a, $b) => [$b['plan'], $b['n']] <=> [$a['plan'], $a['n']]);

        // ── things worth a look ──
        $watch = [];
        if ($croTotal !== null && $plan > $croTotal) {
            $watch[] = ['tone' => 'rose', 'text' => __('The approved plan exceeds the CRO decision by :a.', ['a' => '$ ' . number_format($plan - $croTotal)]), 'url' => route('planning.budget-cycle')];
        }
        if ($envApproved && $plan > 0 && $allocated == 0) {
            $watch[] = ['tone' => 'amber', 'text' => __('The envelope is approved but has not been distributed yet.'), 'url' => route('budget.envelope.current')];
        }
        if ($envelope !== null && $allocated > 0 && $envelope - $allocated > 0.5) {
            $watch[] = ['tone' => 'zinc', 'text' => __(':a of the envelope is unallocated.', ['a' => '$ ' . number_format($envelope - $allocated)]), 'url' => route('budget.allocation')];
        }
        $noLines = $base()->where('activities.status', Activity::STATUS_APPROVED)->doesntHave('lineItems')->count();
        if ($noLines > 0) {
            $watch[] = ['tone' => 'amber', 'text' => trans_choice(':n approved activity has no budget lines|:n approved activities have no budget lines', $noLines, ['n' => $noLines]), 'url' => route('activities.index')];
        }
        if ($this->unit === '' && $totalActivities > 0) {
            $silent = collect($units)->where('n', 0)->count();
            if ($silent > 0) {
                $watch[] = ['tone' => 'zinc', 'text' => trans_choice(':n unit has no activities for :year|:n units have no activities for :year', $silent, ['n' => $silent, 'year' => $year]), 'url' => route('activities.index')];
            }
        }

        // ── series for the charts ──
        $charts = [
            'categories' => [
                'labels' => $classes->map(fn ($c) => $c->code . ' ' . $c->name)->values()->all(),
                'envelope' => $classes->map(fn ($c) => (float) ($env?->categoryAllocations->firstWhere('economic_classification_id', $c->id)?->allocated_amount ?? 0))->values()->all(),
                'plan' => $classes->map(fn ($c) => (float) ($planByClass[$c->id] ?? 0))->values()->all(),
                'alloc' => $classes->map(fn ($c) => (float) ($allocByClass[$c->id] ?? 0))->values()->all(),
            ],
            'status' => ['labels' => array_map(fn ($s) => __($s), $statusOrder), 'values' => array_values($byStatus)],
            'units' => [
                'labels' => collect($units)->where('plan', '>', 0)->take(8)->pluck('code')->values()->all(),
                'plan' => collect($units)->where('plan', '>', 0)->take(8)->pluck('plan')->map(fn ($v) => (float) $v)->values()->all(),
                'alloc' => collect($units)->where('plan', '>', 0)->take(8)->pluck('alloc')->map(fn ($v) => (float) $v)->values()->all(),
            ],
            'quarterly' => $quarterly,
            'labels' => [__('Requested'), __('Approved plan'), __('Envelope'), __('Distributed'), __('Plan'), __('Cumulative progress')],
        ];

        return [
            'year' => $year, 'org' => SystemSetting::getLocalized('org_abbr', ''),
            'steps' => $steps, 'currentStep' => $currentStep,
            'fundLabel' => $fundLabel, 'fund' => $fund, 'envelope' => $envelope,
            'plan' => $plan, 'planAll' => $planAll, 'allocated' => $allocated,
            'byStatus' => $byStatus, 'statusOrder' => $statusOrder, 'totalActivities' => $totalActivities,
            'achievement' => $achievement, 'indicatorCount' => $indicators->count(),
            'units' => $units, 'watch' => $watch, 'charts' => $charts,
            'rootUnits' => $dirs->filter(fn ($d) => !$d->parent_id)->sortBy('name'),
            'classes' => $classes,
        ];
    }
}; ?>

<div class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-4">
        <div>
            <h1 class="page-title">{{ __('Dashboard') }}</h1>
            <p class="page-subtitle">{{ $org ? $org . ' · ' : '' }}{{ __('Fiscal year') }} {{ $year }}</p>
        </div>
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label for="f-unit" class="block text-xs font-medium text-zinc-500 mb-1">{{ __('Unit') }}</label>
                <select id="f-unit" wire:model.live="unit" class="flux-input !w-56 !py-2">
                    <option value="">{{ __('All units') }}</option>
                    @foreach($rootUnits as $d)
                        <option value="{{ $d->id }}">{{ $d->code }} — {{ $d->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="f-cat" class="block text-xs font-medium text-zinc-500 mb-1">{{ __('Category') }}</label>
                <select id="f-cat" wire:model.live="category" class="flux-input !w-48 !py-2">
                    <option value="">{{ __('All categories') }}</option>
                    @foreach($classes as $c)
                        <option value="{{ $c->id }}">{{ $c->code }} {{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            @if($unit !== '' || $category !== '')
                <button wire:click="clearFilters" class="pb-2.5 text-sm font-medium text-indigo-600 hover:underline">{{ __('Clear filters') }}</button>
            @endif
            <x-ui.button variant="secondary" size="md" href="{{ route('reports.approved-plan', ['year' => $year]) }}">{{ __('Approved plan (PDF)') }}</x-ui.button>
        </div>
    </div>

    {{-- Budget cycle: where is this year? --}}
    <section aria-label="{{ __('Budget cycle') }}" class="bg-white rounded-lg border border-zinc-200 shadow-card px-5 py-4">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-sm font-semibold text-zinc-900">{{ __('Budget cycle') }} {{ $year }}</h2>
            <a href="{{ route('planning.budget-cycle') }}" wire:navigate class="text-xs font-medium text-indigo-600 hover:underline">{{ __('Open') }} →</a>
        </div>
        <ol class="grid grid-cols-2 md:grid-cols-6 gap-4">
            @foreach($steps as $i => $step)
                @php $current = $currentStep === $i; @endphp
                <li class="relative">
                    <div class="flex items-center gap-2">
                        <span class="w-6 h-6 shrink-0 rounded-full flex items-center justify-center text-xs font-semibold
                            {{ $step['done'] ? 'bg-emerald-600 text-white' : ($current ? 'bg-indigo-600 text-white ring-4 ring-indigo-100' : 'bg-zinc-100 text-zinc-500') }}">
                            @if($step['done'])<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>@else{{ $i + 1 }}@endif
                        </span>
                        <span class="text-sm {{ $current ? 'font-semibold text-zinc-900' : ($step['done'] ? 'font-medium text-zinc-700' : 'text-zinc-500') }}">{{ $step['label'] }}</span>
                    </div>
                    <p class="mt-1 ml-8 text-xs tabular-nums {{ $step['value'] !== null ? 'text-zinc-600' : 'text-zinc-400' }}">
                        {{ $step['value'] !== null ? '$ ' . number_format($step['value']) : ($current ? __('Next step') : '—') }}
                    </p>
                </li>
            @endforeach
        </ol>
    </section>

    {{-- Key figures --}}
    @php
        $fundedPct = $envelope ? min(100, $allocated / max($envelope, 1) * 100) : null;
        $approvedShare = $totalActivities > 0 ? ($byStatus[\App\Models\Activity::STATUS_APPROVED] / $totalActivities * 100) : 0;
    @endphp
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <x-ui.kpi :label="$fundLabel" :value="$fund !== null ? '$ ' . number_format($fund) : '—'"
            :sub="$fund !== null ? ($envelope === null ? __('Not yet the final envelope') : __('Received for the year')) : __('Not registered yet')" tone="zinc"
            href="{{ route('budget.envelope.current') }}" />
        <x-ui.kpi :label="__('Approved plan')" :value="'$ ' . number_format($plan)"
            :sub="__('of $ :t requested in total', ['t' => number_format($planAll)])"
            :progress="$fund ? min(100, $plan / max($fund, 1) * 100) : null" :tone="($fund && $plan > $fund) ? 'rose' : 'indigo'"
            href="{{ route('activities.index') }}" />
        <x-ui.kpi :label="__('Distributed')" :value="'$ ' . number_format($allocated)"
            :sub="$envelope ? number_format($fundedPct, 1) . '% ' . __('of the envelope') : __('Needs an approved envelope')"
            :progress="$fundedPct" tone="emerald" href="{{ route('budget.allocation') }}" />
        <x-ui.kpi :label="__('Activities approved')" :value="$byStatus[\App\Models\Activity::STATUS_APPROVED] . ' / ' . $totalActivities"
            :sub="number_format($approvedShare, 0) . '% ' . __('of the plan')" :progress="$approvedShare" tone="indigo"
            href="{{ route('planning.paa-approval') }}" />
        <x-ui.kpi :label="__('Delivery against targets')" :value="$achievement !== null ? number_format($achievement, 1) . '%' : '—'"
            :sub="$achievement !== null ? trans_choice(':n indicator with a target|:n indicators with targets', $indicatorCount, ['n' => $indicatorCount]) : __('No verified progress yet')"
            :progress="$achievement" tone="amber" href="{{ route('monitoring.index') }}" />
    </div>

    @if($totalActivities === 0)
        <x-ui.card>
            <div class="text-center py-10">
                <h2 class="text-base font-semibold text-zinc-900">{{ __('No activities for :year yet', ['year' => $year]) }}</h2>
                <p class="mt-1 text-sm text-zinc-500">{{ __('Plan the first activity, or switch the fiscal year in the top bar.') }}</p>
                <x-ui.button variant="primary" class="mt-5" href="{{ route('activities.create') }}">{{ __('Create first activity') }}</x-ui.button>
            </div>
        </x-ui.card>
    @endif

    {{-- Attention + watch list | money by category --}}
    <div class="grid gap-6 xl:grid-cols-12">
        <div class="xl:col-span-5 space-y-6">
            <x-next-actions />

            @if(count($watch))
                <section class="bg-white rounded-lg border border-zinc-200 shadow-card" aria-labelledby="watch-title">
                    <div class="px-5 py-4 border-b border-zinc-200"><h2 id="watch-title" class="text-sm font-semibold text-zinc-900">{{ __('Worth a look') }}</h2></div>
                    <ul class="divide-y divide-zinc-200">
                        @foreach($watch as $w)
                            <li>
                                <a href="{{ $w['url'] }}" wire:navigate class="flex items-start gap-3 px-5 py-3 hover:bg-zinc-50">
                                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ ['rose' => 'bg-rose-500', 'amber' => 'bg-amber-500', 'zinc' => 'bg-zinc-400'][$w['tone']] ?? 'bg-zinc-400' }}"></span>
                                    <span class="text-sm text-zinc-700">{{ $w['text'] }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>

        <x-ui.card class="xl:col-span-7" padding="p-0">
            <x-slot name="header"><h2 class="text-sm font-semibold text-zinc-900">{{ __('Money by economic category') }}</h2></x-slot>
            <div class="p-5">
                <div wire:key="c-cat-{{ md5(json_encode($charts['categories'])) }}" x-data="dashChart('categories', @js($charts))" x-init="mount()" class="relative h-72">
                    <canvas role="img" aria-label="{{ __('Envelope, approved plan and distributed amount for each economic category') }}"></canvas>
                </div>
            </div>
        </x-ui.card>
    </div>

    {{-- Status | units --}}
    <div class="grid gap-6 xl:grid-cols-12">
        <x-ui.card class="xl:col-span-5" padding="p-0">
            <x-slot name="header"><h2 class="text-sm font-semibold text-zinc-900">{{ __('Where the activities stand') }}</h2></x-slot>
            <div class="p-5 grid sm:grid-cols-2 gap-4 items-center">
                <div wire:key="c-status-{{ md5(json_encode($charts['status'])) }}" x-data="dashChart('status', @js($charts))" x-init="mount()" class="relative h-52">
                    <canvas role="img" aria-label="{{ __('Activities by workflow status') }}"></canvas>
                </div>
                <ul class="space-y-2 text-sm">
                    @foreach($statusOrder as $i => $st)
                        <li class="flex items-center justify-between gap-3">
                            <span class="flex items-center gap-2 text-zinc-600">
                                <span class="h-2.5 w-2.5 rounded-sm" style="background: {{ ['#CBD5E1', '#80CAEE', '#6F80EE', '#3C50E0', '#F59E0B', '#10B981'][$i] }}"></span>{{ __($st) }}
                            </span>
                            <span class="font-semibold tabular-nums text-zinc-900">{{ $byStatus[$st] }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </x-ui.card>

        <x-ui.card class="xl:col-span-7" padding="p-0">
            <x-slot name="header"><h2 class="text-sm font-semibold text-zinc-900">{{ __('Approved plan and distribution by unit') }}</h2></x-slot>
            <div class="p-5">
                @if(count($charts['units']['labels']))
                    <div wire:key="c-units-{{ md5(json_encode($charts['units'])) }}" x-data="dashChart('units', @js($charts))" x-init="mount()" class="relative h-52">
                        <canvas role="img" aria-label="{{ __('Approved plan and distributed amount for the largest units') }}"></canvas>
                    </div>
                @else
                    <p class="text-sm text-zinc-500 py-10 text-center">{{ __('No approved activities with a budget yet.') }}</p>
                @endif
            </div>
        </x-ui.card>
    </div>

    {{-- Units table: the numbers behind the charts --}}
    <x-ui.card padding="p-0">
        <x-slot name="header"><h2 class="text-sm font-semibold text-zinc-900">{{ __('Units') }}</h2></x-slot>
        <x-ui.table :headers="[__('Unit'), __('Activities'), __('Approved plan'), __('Distributed'), __('Funded')]">
            @forelse($units as $u)
                @php $pct = $u['plan'] > 0 ? min(100, $u['alloc'] / $u['plan'] * 100) : null; @endphp
                <tr>
                    <td class="px-6 py-3 text-sm"><span class="font-semibold text-zinc-900">{{ $u['code'] }}</span> <span class="text-zinc-500">{{ $u['name'] }}</span></td>
                    <td class="px-6 py-3 text-sm tabular-nums">
                        @if($u['n'] > 0){{ $u['approved'] }}<span class="text-zinc-400"> / {{ $u['n'] }}</span>@else<span class="text-zinc-400">{{ __('None') }}</span>@endif
                    </td>
                    <td class="px-6 py-3 text-sm tabular-nums">{{ $u['plan'] > 0 ? '$ ' . number_format($u['plan']) : '—' }}</td>
                    <td class="px-6 py-3 text-sm tabular-nums">{{ $u['alloc'] > 0 ? '$ ' . number_format($u['alloc']) : '—' }}</td>
                    <td class="px-6 py-3 text-sm w-48">
                        @if($pct !== null)
                            <div class="flex items-center gap-2">
                                <div class="h-1.5 flex-1 rounded-full bg-zinc-100 overflow-hidden"><div class="h-full rounded-full {{ $pct >= 99.5 ? 'bg-emerald-500' : 'bg-amber-500' }}" style="width: {{ $pct }}%"></div></div>
                                <span class="text-xs tabular-nums text-zinc-600 w-10 text-right">{{ number_format($pct, 0) }}%</span>
                            </div>
                        @else
                            <span class="text-zinc-400">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-6 py-8 text-center text-sm text-zinc-500">{{ __('No units to show.') }}</td></tr>
            @endforelse
        </x-ui.table>
    </x-ui.card>

    {{-- Delivery over the year --}}
    @if($achievement !== null)
        <x-ui.card padding="p-0">
            <x-slot name="header"><h2 class="text-sm font-semibold text-zinc-900">{{ __('Cumulative progress against annual targets') }}</h2></x-slot>
            <div class="p-5">
                <div wire:key="c-q-{{ md5(json_encode($charts['quarterly'])) }}" x-data="dashChart('quarterly', @js($charts))" x-init="mount()" class="relative h-52">
                    <canvas role="img" aria-label="{{ __('Verified cumulative progress after each quarter') }}"></canvas>
                </div>
            </div>
        </x-ui.card>
    @endif

    @once
        <script>
            // Drawn with the bundled Chart.js. Each chart is rebuilt when its data changes (new wire:key).
            window.dashChart = (kind, d) => ({
                chart: null,
                mount() { window.onChart(() => this.draw()); },
                destroy() { this.chart && this.chart.destroy(); },
                draw() {
                    const el = this.$el.querySelector('canvas');
                    if (!el || this.chart) return;
                    const L = d.labels, usd = (v) => '$ ' + Math.round(v).toLocaleString();
                    const grid = { color: '#E2E8F0' }, tick = { color: '#64748B', font: { family: 'Inter', size: 11 } };
                    const moneyAxis = { beginAtZero: true, grid, ticks: { ...tick, callback: (v) => v >= 1000 ? '$' + (v / 1000) + 'k' : '$' + v } };
                    const base = { responsive: true, maintainAspectRatio: false, animation: { duration: 250 },
                        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, color: '#475569', font: { family: 'Inter', size: 11 } } },
                                   tooltip: { callbacks: { label: (c) => ' ' + c.dataset.label + ': ' + (kind === 'status' || kind === 'quarterly' ? c.parsed.y ?? c.parsed : usd(c.parsed.y ?? c.parsed.x)) } } } };
                    const cfg = {
                        categories: () => ({ type: 'bar', data: { labels: d.categories.labels.map(l => l.length > 22 ? l.slice(0, 21) + '…' : l), datasets: [
                            { label: L[2], data: d.categories.envelope, backgroundColor: '#CBD5E1', borderRadius: 3 },
                            { label: L[1], data: d.categories.plan, backgroundColor: '#3C50E0', borderRadius: 3 },
                            { label: L[3], data: d.categories.alloc, backgroundColor: '#10B981', borderRadius: 3 } ] },
                            options: { ...base, scales: { y: moneyAxis, x: { grid: { display: false }, ticks: tick } } } }),
                        status: () => ({ type: 'doughnut', data: { labels: d.status.labels, datasets: [{ data: d.status.values, backgroundColor: ['#CBD5E1', '#80CAEE', '#6F80EE', '#3C50E0', '#F59E0B', '#10B981'], borderWidth: 2, borderColor: '#fff' }] },
                            options: { ...base, cutout: '68%', plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => ' ' + c.label + ': ' + c.parsed } } } } }),
                        units: () => ({ type: 'bar', data: { labels: d.units.labels, datasets: [
                            { label: L[1], data: d.units.plan, backgroundColor: '#3C50E0', borderRadius: 3 },
                            { label: L[3], data: d.units.alloc, backgroundColor: '#10B981', borderRadius: 3 } ] },
                            options: { ...base, scales: { y: moneyAxis, x: { grid: { display: false }, ticks: tick } } } }),
                        quarterly: () => ({ type: 'line', data: { labels: ['Q1', 'Q2', 'Q3', 'Q4'], datasets: [{ label: L[5], data: d.quarterly, borderColor: '#3C50E0', backgroundColor: 'rgba(60,80,224,.08)', fill: true, tension: .3, pointRadius: 4, pointBackgroundColor: '#3C50E0' }] },
                            options: { ...base, plugins: { ...base.plugins, legend: { display: false }, tooltip: { callbacks: { label: (c) => ' ' + c.parsed.y + '%' } } },
                                scales: { y: { beginAtZero: true, max: 100, grid, ticks: { ...tick, callback: (v) => v + '%' } }, x: { grid: { display: false }, ticks: tick } } } }),
                    }[kind]();
                    this.chart = new window.Chart(el, cfg);
                },
            });
        </script>
    @endonce
</div>
