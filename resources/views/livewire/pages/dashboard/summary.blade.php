<?php

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\Indicator;
use App\Models\Program;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;

/**
 * Executive dashboard for one fiscal year (the year chosen in the top bar).
 * Every figure comes from grouped SQL, so cost does not grow with the number of activities.
 */
new class extends Component {
    public $unit = '';      // top-level unit filter ('' = all)
    public $category = '';  // economic category filter ('' = all)
    public $program = '';   // programme filter ('' = all)

    public function clearFilters(): void
    {
        $this->unit = '';
        $this->category = '';
        $this->program = '';
    }

    public function with(): array
    {
        $year = \App\Support\FiscalYear::current();
        $dirs = Directorate::get(['id', 'code', 'name', 'type', 'parent_id'])->keyBy('id');
        $rootOf = fn ($id) => ($dirs[$id]->parent_id ?? null) ?: $id;
        $classes = EconomicClassification::orderBy('code')->get(['id', 'code', 'name'])->keyBy('id');

        $unitIds = $this->unit !== ''
            ? $dirs->filter(fn ($d) => $d->id == $this->unit || $d->parent_id == $this->unit)->keys()->all()
            : null;
        $cat = $this->category !== '' ? (int) $this->category : null;
        $prog = $this->program !== '' ? (int) $this->program : null;
        $programs = Program::forYear($year)->orderBy('oge_code')->get(['id', 'oge_code', 'name'])->keyBy('id');

        $base = fn () => Activity::withoutGlobalScopes()
            ->where('activities.is_test_data', false)
            ->where('activities.fiscal_year', $year)
            ->when($unitIds, fn ($q) => $q->whereIn('activities.directorate_id', $unitIds))
            ->when($prog, fn ($q) => $q->where('activities.program_id', $prog))
            ->when($cat, fn ($q) => $q->whereExists(fn ($s) => $s->selectRaw('1')->from('line_items')
                ->whereColumn('line_items.activity_id', 'activities.id')
                ->where('line_items.is_test_data', false)
                ->where('line_items.economic_classification_id', $cat)));

        // ── counts: activities by unit and status ──
        $statusOrder = [Activity::STATUS_DRAFT, Activity::STATUS_SUBMITTED, Activity::STATUS_REVIEWED, Activity::STATUS_VALIDATED, Activity::STATUS_WAITING_APPROVAL, Activity::STATUS_APPROVED];
        $byStatus = array_fill_keys($statusOrder, 0);
        $unitStats = [];
        $progStats = [];
        $subStats = [];
        foreach ($base()->selectRaw('activities.directorate_id as d, activities.program_id as p, activities.subprogram_id as sp, activities.status as s, COUNT(*) as n')->groupBy('activities.directorate_id', 'activities.program_id', 'activities.subprogram_id', 'activities.status')->get() as $r) {
            $root = $rootOf($r->d);
            $progStats[$r->p]['n'] = ($progStats[$r->p]['n'] ?? 0) + $r->n;
            if ($r->sp) {
                $subStats[$r->sp]['n'] = ($subStats[$r->sp]['n'] ?? 0) + $r->n;
            }
            if ($r->s === Activity::STATUS_APPROVED) {
                $progStats[$r->p]['approved'] = ($progStats[$r->p]['approved'] ?? 0) + $r->n;
                if ($r->sp) {
                    $subStats[$r->sp]['approved'] = ($subStats[$r->sp]['approved'] ?? 0) + $r->n;
                }
            }
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
            ->groupBy('activities.directorate_id', 'activities.program_id', 'activities.subprogram_id', 'activities.status', 'line_items.economic_classification_id')
            ->selectRaw('activities.directorate_id as d, activities.program_id as p, activities.subprogram_id as sp, activities.status as s, line_items.economic_classification_id as c,
                COALESCE(SUM(CASE WHEN line_items.deleted_at IS NULL THEN line_items.estimated_amount ELSE 0 END), 0) as req, COALESCE(SUM(adjusted_budgets.approved_amount), 0) as alloc')
            ->get() as $r) {
            // planned: what every activity asks for; approved: the amounts of the approved versions in force
            $planAll += $r->req;
            $allocated += $r->alloc;
            if ($r->s === Activity::STATUS_APPROVED) {
                $plan += $r->req;
            }
            $root = $rootOf($r->d);
            $unitStats[$root]['plan'] = ($unitStats[$root]['plan'] ?? 0) + $r->req;
            $unitStats[$root]['alloc'] = ($unitStats[$root]['alloc'] ?? 0) + $r->alloc;
            $progStats[$r->p]['plan'] = ($progStats[$r->p]['plan'] ?? 0) + $r->req;
            $progStats[$r->p]['alloc'] = ($progStats[$r->p]['alloc'] ?? 0) + $r->alloc;
            if ($r->sp) {
                $subStats[$r->sp]['plan'] = ($subStats[$r->sp]['plan'] ?? 0) + $r->req;
                $subStats[$r->sp]['alloc'] = ($subStats[$r->sp]['alloc'] ?? 0) + $r->alloc;
            }
            if ($r->c) {
                $planByClass[$r->c] = ($planByClass[$r->c] ?? 0) + $r->req;
                $allocByClass[$r->c] = ($allocByClass[$r->c] ?? 0) + $r->alloc;
            }
        }

        // ── the cycle of the annual plan for this year ──
        $planCycle = app(\App\Services\PlanCycle::class);
        $planning = app(\App\Services\PlanningWindow::class)->progress($year);
        $ended = $planning['ended'];
        $steps = $planCycle->steps([
            'planWindow' => $planCycle->windowState($year, 'plan', 1), 'ended' => $ended, 'planning' => $planning, 'total' => $totalActivities, 'approved' => $byStatus[Activity::STATUS_APPROVED] ?? 0,
            'pending' => ($byStatus[Activity::STATUS_SUBMITTED] ?? 0) + ($byStatus[Activity::STATUS_REVIEWED] ?? 0) + ($byStatus[Activity::STATUS_VALIDATED] ?? 0) + ($byStatus[Activity::STATUS_WAITING_APPROVAL] ?? 0),
            'drafts' => ($byStatus[Activity::STATUS_DRAFT] ?? 0) + ($byStatus[Activity::STATUS_REJECTED] ?? 0),
            'year' => $year,
        ]);
        $currentStep = collect($steps)->search(fn ($s) => $s['state'] === 'current');

        // ── physical delivery: verified progress against annual targets (cumulative or level, see DeliveryReport) ──
        $delivery = app(\App\Services\DeliveryReport::class)->summary($base()->select('activities.id')->toBase());
        $achievement = $delivery['achievement'];
        $quarterly = $delivery['quarters'];

        // ── execution: committed and paid against the approved plan amount, and against the calendar ──
        $execReport = app(\App\Services\ExecutionReport::class);
        $execData = $execReport->load($year, $unitIds, $cat, null, $prog);
        $exec = $execReport->summary($execData);
        $behind = array_map(fn ($a) => $a + ['unit' => $dirs[$a['unit_id']]->code ?? ''], $execReport->behind($execData, 5));

        // ── programmes: what each one plans, receives and has paid ──
        $execByProg = $execReport->grouped($execData, 'program_id');
        $execBySub = $execReport->grouped($execData, 'subprogram_id');
        $subsByProg = \App\Models\Subprogram::whereIn('id', array_keys($subStats))->orderBy('code')->get(['id', 'program_id', 'code', 'name'])->groupBy('program_id');
        $progRows = [];
        foreach ($programs as $id => $p) {
            $s = $progStats[$id] ?? [];
            if (($s['n'] ?? 0) === 0) {
                continue; // no activity in the year (or outside the filters)
            }
            $e = $execByProg[$id] ?? null;
            $progRows[] = [
                'id' => $id, 'code' => $p->oge_code, 'name' => $p->name, 'n' => $s['n'], 'approved' => $s['approved'] ?? 0,
                'plan' => (float) ($s['plan'] ?? 0), 'alloc' => (float) ($s['alloc'] ?? 0),
                'paid_pct' => $e && $e['approved'] > 0 ? $e['paid'] / $e['approved'] * 100 : null,
                'due_pct' => $e && $e['approved'] > 0 ? $e['planned_to_date'] / $e['approved'] * 100 : null,
                'subs' => $subsByProg->get($id, collect())->map(function ($sub) use ($subStats, $execBySub) {
                    $s = $subStats[$sub->id] ?? [];
                    $x = $execBySub[$sub->id] ?? null;

                    return [
                        'code' => $sub->code, 'name' => $sub->name, 'n' => $s['n'] ?? 0, 'approved' => $s['approved'] ?? 0,
                        'plan' => (float) ($s['plan'] ?? 0), 'alloc' => (float) ($s['alloc'] ?? 0),
                        'paid_pct' => $x && $x['approved'] > 0 ? $x['paid'] / $x['approved'] * 100 : null,
                    ];
                })->all(),
            ];
        }
        usort($progRows, fn ($a, $b) => [$b['plan'], $b['n']] <=> [$a['plan'], $a['n']]);

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
        $noLines = $base()->where('activities.status', Activity::STATUS_APPROVED)->doesntHave('lineItems')->count();
        if ($noLines > 0) {
            $watch[] = ['tone' => 'amber', 'text' => trans_choice(':n approved activity has no budget lines|:n approved activities have no budget lines', $noLines, ['n' => $noLines]), 'url' => route('activities.index')];
        }
        // indicators that should have been reported this quarter and have no verified figure yet (current year only)
        if ($year === (int) date('Y') && $exec['quarter'] >= 1 && $exec['quarter'] <= 4) {
            $due = app(\App\Services\DeliveryReport::class)->dueWithoutFigure($base()->select('activities.id')->toBase(), $exec['quarter']);
            if ($due > 0) {
                $watch[] = ['tone' => 'amber', 'text' => trans_choice(':n indicator is due in Q:q with no verified figure yet|:n indicators are due in Q:q with no verified figure yet', $due, ['n' => $due, 'q' => $exec['quarter']]), 'url' => route('monitoring.index')];
            }
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
            'execution' => [
                'planned' => array_column($exec['quarters'], 'planned'),
                'committed' => array_column($exec['quarters'], 'committed'),
                'paid' => array_column($exec['quarters'], 'paid'),
            ],
            'labels' => [__('Requested'), __('Planned'), __('Planned'), __('Approved plan'), __('Plan'), __('Cumulative progress'), __('Planned (cumulative)'), __('Committed'), __('Paid')],
        ];

        return [
            'year' => $year, 'org' => SystemSetting::getLocalized('org_abbr', ''),
            'steps' => $steps, 'currentStep' => $currentStep,
            'plan' => $plan, 'planAll' => $planAll, 'allocated' => $allocated,
            'byStatus' => $byStatus, 'statusOrder' => $statusOrder, 'totalActivities' => $totalActivities,
            'achievement' => $achievement, 'indicatorCount' => $delivery['n'],
            'units' => $units, 'watch' => $watch, 'charts' => $charts, 'exec' => $exec, 'behind' => $behind, 'programs' => $programs, 'progRows' => $progRows,
            'rootUnits' => $dirs->filter(fn ($d) => !$d->parent_id)->sortBy('name'),
            'classes' => $classes,
        ];
    }
}; ?>

@php $wide = auth()->user()->can('view_all_paa'); @endphp

<div class="space-y-6">
    {{-- Header --}}
    <x-ui.page-header :title="__('Dashboard')" :subtitle="($org ? $org . ' · ' : '') . __('Fiscal year') . ' ' . $year">
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label for="f-unit" class="field-label">{{ __('Unit') }}</label>
                <select id="f-unit" wire:model.live="unit" class="flux-input !w-56">
                    <option value="">{{ __('All units') }}</option>
                    @foreach($rootUnits as $d)
                        <option value="{{ $d->id }}">{{ $d->code }} — {{ $d->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="f-prog" class="field-label">{{ __('Program') }}</label>
                <select id="f-prog" wire:model.live="program" class="flux-input !w-56">
                    <option value="">{{ __('All programs') }}</option>
                    @foreach($programs as $p)
                        <option value="{{ $p->id }}">{{ $p->oge_code }} — {{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="f-cat" class="field-label">{{ __('Category') }}</label>
                <select id="f-cat" wire:model.live="category" class="flux-input !w-48">
                    <option value="">{{ __('All categories') }}</option>
                    @foreach($classes as $c)
                        <option value="{{ $c->id }}">{{ $c->code }} {{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            @if($unit !== '' || $category !== '' || $program !== '')
                <button wire:click="clearFilters" class="pb-2 text-sm font-medium text-indigo-700 hover:underline">{{ __('Clear filters') }}</button>
            @endif
            @if($wide)
                <x-ui.button variant="secondary" size="md" href="{{ route('reports.approved-plan', ['year' => $year]) }}">{{ __('Final plan report') }}</x-ui.button>
            @endif
        </div>
    </x-ui.page-header>

    {{-- The annual plan cycle: where is this year? --}}
    <section aria-label="{{ __('Plan cycle') }}" class="bg-white rounded-lg border border-zinc-200 shadow-card px-5 py-4">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-sm font-semibold text-zinc-900">{{ __('Plan cycle') }} {{ $year }}</h2>
            <a href="{{ route('planning.cycle') }}" wire:navigate class="text-xs font-medium text-indigo-600 hover:underline">{{ __('Open') }} →</a>
        </div>
        <ol class="grid grid-cols-2 gap-4 md:grid-cols-6">
            @foreach($steps as $i => $step)
                @php $done = $step['state'] === 'done'; $current = $step['state'] === 'current'; @endphp
                <li class="relative">
                    <div class="flex items-center gap-2">
                        <span class="w-6 h-6 shrink-0 rounded-full flex items-center justify-center text-xs font-semibold
                            {{ $done ? 'bg-emerald-600 text-white' : ($current ? 'bg-indigo-600 text-white ring-4 ring-indigo-100' : 'bg-zinc-100 text-zinc-500') }}">
                            @if($done)<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>@else{{ $i + 1 }}@endif
                        </span>
                        <span class="text-sm {{ $current ? 'font-semibold text-zinc-900' : ($done ? 'font-medium text-zinc-700' : 'text-zinc-500') }}">{{ $step['label'] }}</span>
                    </div>
                    <p class="mt-1 ml-8 text-xs {{ $done || $current ? 'text-zinc-600' : 'text-zinc-400' }}">{{ $step['detail'] }}</p>
                </li>
            @endforeach
        </ol>
    </section>

    {{-- Key figures --}}
    @php
        $approvedPct = $planAll > 0 ? min(100, $allocated / $planAll * 100) : null;
        $approvedShare = $totalActivities > 0 ? ($byStatus[\App\Models\Activity::STATUS_APPROVED] / $totalActivities * 100) : 0;
    @endphp
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <x-ui.kpi :label="__('Planned')" :value="'$ ' . number_format($planAll)"
            :sub="trans_choice(':n activity in the plan|:n activities in the plan', $totalActivities, ['n' => $totalActivities])" tone="zinc"
            href="{{ route('activities.index') }}" />
        <x-ui.kpi :label="__('Approved plan')" :value="'$ ' . number_format($allocated)"
            :sub="$approvedPct !== null ? number_format($approvedPct, 1) . '% ' . __('of what is planned') : __('Nothing planned yet')"
            :progress="$approvedPct" tone="indigo" href="{{ route('planning.paa-approval') }}" />
        <x-ui.kpi :label="__('Paid')" :value="'$ ' . number_format((float) ($exec['paid'] ?? 0))"
            :sub="$allocated > 0 ? number_format(min(100, (float) ($exec['paid'] ?? 0) / $allocated * 100), 1) . '% ' . __('of the approved plan, verified') : __('Verified spending')"
            :progress="$allocated > 0 ? min(100, (float) ($exec['paid'] ?? 0) / $allocated * 100) : null" tone="emerald" href="{{ route('monitoring.index') }}" />
        <x-ui.kpi :label="__('Activities approved')" :value="$byStatus[\App\Models\Activity::STATUS_APPROVED] . ' / ' . $totalActivities"
            :sub="number_format($approvedShare, 0) . '% ' . __('of the plan')" :progress="$approvedShare" tone="indigo"
            href="{{ route('planning.paa-approval') }}" />
        <x-ui.kpi :label="__('Delivery against targets')" :value="$achievement !== null ? number_format($achievement, 1) . '%' : '—'"
            :sub="$achievement !== null ? trans_choice(':n indicator with a target|:n indicators with targets', $indicatorCount, ['n' => $indicatorCount]) : __('No verified progress yet')"
            :progress="$achievement" tone="amber" href="{{ route('monitoring.index') }}" />
    </div>

    {{-- Budget execution: what has been committed and paid, read against how much of the year has passed --}}
    @if($exec['has_data'])
        @php
            $gap = $exec['paid_rate'] !== null && $exec['planned_rate'] !== null ? $exec['paid_rate'] - $exec['planned_rate'] : null;
            [$paceLabel, $paceTone] = match (true) {
                ! $exec['has_actuals'] => [__('Nothing reported yet'), 'bg-zinc-100 text-zinc-700'],
                $gap >= 5 => [__('Ahead of schedule'), 'bg-emerald-50 text-emerald-800'],
                $gap >= -5 => [__('On schedule'), 'bg-indigo-50 text-indigo-800'],
                default => [__('Behind schedule'), 'bg-amber-50 text-amber-800'],
            };
        @endphp
        <x-ui.card :title="__('Budget execution')" :description="__('Paid and committed against the approved plan amount, by quarter')" padding="p-0">
            <x-slot:actions>
                @if($exec['quarter'] > 0)
                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $paceTone }}">{{ $paceLabel }}</span>
                @endif
            </x-slot:actions>
            <div class="grid gap-0 lg:grid-cols-12">
                <div class="min-w-0 p-5 lg:col-span-7">
                    <div wire:key="c-x-{{ md5(json_encode($charts['execution'])) }}" x-data="dashChart('execution', @js($charts))" x-init="mount()" class="relative h-80">
                        <canvas role="img" aria-label="{{ __('Cumulative planned, committed and paid amounts after each quarter') }}"></canvas>
                    </div>
                </div>
                <div class="space-y-5 border-t border-zinc-200 p-5 lg:col-span-5 lg:border-l lg:border-t-0">
                    <dl class="grid grid-cols-3 gap-3">
                        <div><dt class="text-xs font-medium text-zinc-500">{{ __('Approved plan amount') }}</dt><dd class="mt-1 text-lg font-semibold tabular-nums text-zinc-900">$ {{ number_format($exec['approved']) }}</dd></div>
                        <div><dt class="text-xs font-medium text-zinc-500">{{ __('Committed') }}</dt><dd class="mt-1 text-lg font-semibold tabular-nums text-zinc-900">$ {{ number_format($exec['committed']) }}</dd></div>
                        <div><dt class="text-xs font-medium text-zinc-500">{{ __('Paid') }}</dt><dd class="mt-1 text-lg font-semibold tabular-nums text-indigo-700">$ {{ number_format($exec['paid']) }}</dd></div>
                    </dl>

                    <div>
                        <div class="flex items-baseline justify-between text-sm">
                            <span class="font-medium text-zinc-700">{{ __('Paid of the approved plan amount') }}</span>
                            <span class="font-semibold tabular-nums text-zinc-900">{{ number_format($exec['paid_rate'] ?? 0, 1) }}%</span>
                        </div>
                        {{-- the bar is the paid share; the tick is where the year stands --}}
                        <div class="relative mt-2 h-2 rounded-full bg-zinc-100" role="img"
                            aria-label="{{ __(':paid% paid, :elapsed% of the year has passed', ['paid' => number_format($exec['paid_rate'] ?? 0, 1), 'elapsed' => number_format($exec['elapsed'], 0)]) }}">
                            <div class="h-full rounded-full bg-indigo-600" style="width: {{ min(100, $exec['paid_rate'] ?? 0) }}%"></div>
                            <div class="absolute -top-1 h-4 w-0.5 bg-zinc-900" style="left: {{ min(100, $exec['elapsed']) }}%"></div>
                        </div>
                        <p class="mt-2 text-xs text-zinc-500">{{ __(':elapsed% of the year has passed; the schedule calls for :planned% paid by now.', ['elapsed' => number_format($exec['elapsed'], 0), 'planned' => number_format($exec['planned_rate'] ?? 0, 0)]) }}</p>
                    </div>

                    @if(count($behind))
                        <div>
                            <h3 class="text-sm font-semibold text-zinc-900">{{ __('Furthest behind schedule') }}</h3>
                            <ul class="mt-2 divide-y divide-zinc-100">
                                @foreach($behind as $b)
                                    <li class="flex items-start justify-between gap-3 py-2">
                                        <a href="{{ route('activities.show', $b['id']) }}" wire:navigate class="min-w-0 text-sm text-zinc-700 hover:text-indigo-700">
                                            <span class="line-clamp-1">{{ $b['name'] }}</span>
                                            <span class="text-xs text-zinc-500">{{ $b['unit'] }}</span>
                                        </a>
                                        <span class="shrink-0 text-right text-xs tabular-nums text-amber-800">{{ __('short by') }} $ {{ number_format($b['gap']) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            </div>
        </x-ui.card>
    @endif

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
                    <canvas role="img" aria-label="{{ __('Planned and approved amount for each economic category') }}"></canvas>
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
                                <span class="h-2.5 w-2.5 rounded-sm" style="background: {{ ['#94A3B8', '#0EA5E9', '#5F8BF0', '#2152C4', '#F59E0B', '#10B981'][$i] }}"></span>{{ __($st) }}
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
                        <canvas role="img" aria-label="{{ __('Planned and approved amount for the largest units') }}"></canvas>
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
        <x-ui.table :headers="[__('Unit'), __('Activities'), __('Planned'), __('Approved plan'), __('Approved')]">
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
                <tr><td colspan="5"><x-ui.empty-state :title="__('No units to show.')" /></td></tr>
            @endforelse
        </x-ui.table>
    </x-ui.card>

    {{-- Programmes: the same plan seen by programme --}}
    <x-ui.card padding="p-0">
        <x-slot name="header"><h2 class="text-sm font-semibold text-zinc-900">{{ __('Programs') }}</h2></x-slot>
        <x-ui.table :headers="[__('Program'), __('Activities'), __('Planned'), __('Approved plan'), __('Approved'), __('Paid')]" :right="[2, 3]">
            @forelse($progRows as $r)
                @php $pct = $r['plan'] > 0 ? min(100, $r['alloc'] / $r['plan'] * 100) : null; $late = $r['paid_pct'] !== null && $r['due_pct'] !== null && $r['paid_pct'] < $r['due_pct'] - 5; @endphp
                <tr>
                    <td class="px-6 py-3 text-sm">
                        <button type="button" wire:click="$set('program', '{{ $r['id'] }}')" class="text-left hover:text-indigo-700" title="{{ __('Show only this program') }}">
                            <span class="font-semibold text-zinc-900">{{ $r['code'] }}</span> <span class="text-zinc-500">{{ $r['name'] }}</span>
                        </button>
                    </td>
                    <td class="px-6 py-3 text-sm tabular-nums">{{ $r['approved'] }}<span class="text-zinc-400"> / {{ $r['n'] }}</span></td>
                    <td class="px-6 py-3 num text-sm">{{ $r['plan'] > 0 ? '$ ' . number_format($r['plan']) : '—' }}</td>
                    <td class="px-6 py-3 num text-sm">{{ $r['alloc'] > 0 ? '$ ' . number_format($r['alloc']) : '—' }}</td>
                    <td class="px-6 py-3 text-sm w-44">
                        @if($pct !== null)
                            <div class="flex items-center gap-2">
                                <div class="h-1.5 flex-1 rounded-full bg-zinc-100 overflow-hidden"><div class="h-full rounded-full {{ $pct >= 99.5 ? 'bg-emerald-500' : 'bg-amber-500' }}" style="width: {{ $pct }}%"></div></div>
                                <span class="text-xs tabular-nums text-zinc-600 w-10 text-right">{{ number_format($pct, 0) }}%</span>
                            </div>
                        @else
                            <span class="text-zinc-400">—</span>
                        @endif
                    </td>
                    <td class="px-6 py-3 text-sm tabular-nums {{ $late ? 'text-amber-800 font-medium' : 'text-zinc-700' }}">
                        @if($r['paid_pct'] !== null){{ number_format($r['paid_pct'], 0) }}%@if($late)<span class="sr-only"> ({{ __('behind schedule') }})</span>@endif @else — @endif
                    </td>
                </tr>
                @foreach($r['subs'] as $sub)
                    @php $spct = $sub['plan'] > 0 ? min(100, $sub['alloc'] / $sub['plan'] * 100) : null; @endphp
                    <tr class="bg-zinc-50/60">
                        <td class="py-2 pr-6 pl-12 text-xs text-zinc-700"><span class="font-mono font-semibold">{{ $sub['code'] }}</span> {{ $sub['name'] }}</td>
                        <td class="px-6 py-2 text-xs tabular-nums text-zinc-600">{{ $sub['approved'] }}<span class="text-zinc-400"> / {{ $sub['n'] }}</span></td>
                        <td class="px-6 py-2 num text-xs text-zinc-700">{{ $sub['plan'] > 0 ? '$ ' . number_format($sub['plan']) : '—' }}</td>
                        <td class="px-6 py-2 num text-xs text-zinc-700">{{ $sub['alloc'] > 0 ? '$ ' . number_format($sub['alloc']) : '—' }}</td>
                        <td class="px-6 py-2 text-xs tabular-nums text-zinc-600">{{ $spct !== null ? number_format($spct, 0) . '%' : '—' }}</td>
                        <td class="px-6 py-2 text-xs tabular-nums text-zinc-600">{{ $sub['paid_pct'] !== null ? number_format($sub['paid_pct'], 0) . '%' : '—' }}</td>
                    </tr>
                @endforeach
            @empty
                <tr><td colspan="6"><x-ui.empty-state :title="__('No programs to show.')" :text="__('Programs are registered for each fiscal year at Management > Entities.')" /></td></tr>
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
                    const moneyAxis = { beginAtZero: true, grid, ticks: { ...tick, callback: (v) => v >= 1000000 ? '$' + +(v / 1000000).toFixed(1) + 'M' : (v >= 1000 ? '$' + (v / 1000) + 'k' : '$' + v) } };
                    const base = { responsive: true, maintainAspectRatio: false, animation: false,
                        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, color: '#475569', font: { family: 'Inter', size: 11 } } },
                                   tooltip: { callbacks: { label: (c) => ' ' + c.dataset.label + ': ' + (kind === 'status' || kind === 'quarterly' ? c.parsed.y ?? c.parsed : usd(c.parsed.y ?? c.parsed.x)) } } } };
                    const cfg = {
                        categories: () => ({ type: 'bar', data: { labels: d.categories.labels.map(l => l.length > 22 ? l.slice(0, 21) + '…' : l), datasets: [
                            { label: L[1], data: d.categories.plan, backgroundColor: '#2152C4', borderRadius: 3 },
                            { label: L[3], data: d.categories.alloc, backgroundColor: '#10B981', borderRadius: 3 } ] },
                            options: { ...base, scales: { y: moneyAxis, x: { grid: { display: false }, ticks: tick } } } }),
                        status: () => ({ type: 'doughnut', data: { labels: d.status.labels, datasets: [{ data: d.status.values, backgroundColor: ['#94A3B8', '#0EA5E9', '#5F8BF0', '#2152C4', '#F59E0B', '#10B981'], borderWidth: 2, borderColor: '#fff' }] },
                            options: { ...base, cutout: '68%', plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => ' ' + c.label + ': ' + c.parsed } } } } }),
                        units: () => ({ type: 'bar', data: { labels: d.units.labels, datasets: [
                            { label: L[1], data: d.units.plan, backgroundColor: '#2152C4', borderRadius: 3 },
                            { label: L[3], data: d.units.alloc, backgroundColor: '#10B981', borderRadius: 3 } ] },
                            options: { ...base, scales: { y: moneyAxis, x: { grid: { display: false }, ticks: tick } } } }),
                        execution: () => ({ type: 'bar', data: { labels: ['Q1', 'Q2', 'Q3', 'Q4'], datasets: [
                            { type: 'bar', label: L[8], data: d.execution.paid, backgroundColor: '#2152C4', borderRadius: 3, order: 2 },
                            { type: 'line', label: L[7], data: d.execution.committed, borderColor: '#10B981', backgroundColor: '#10B981', tension: .25, pointRadius: 3, spanGaps: false, order: 1 },
                            { type: 'line', label: L[6], data: d.execution.planned, borderColor: '#94A3B8', borderDash: [5, 4], backgroundColor: '#94A3B8', tension: .25, pointRadius: 2, order: 0 } ] },
                            options: { ...base, scales: { y: moneyAxis, x: { grid: { display: false }, ticks: tick } } } }),
                        quarterly: () => ({ type: 'line', data: { labels: ['Q1', 'Q2', 'Q3', 'Q4'], datasets: [{ label: L[5], data: d.quarterly, borderColor: '#2152C4', backgroundColor: 'rgba(60,80,224,.08)', fill: true, tension: .3, pointRadius: 4, pointBackgroundColor: '#2152C4' }] },
                            options: { ...base, plugins: { ...base.plugins, legend: { display: false }, tooltip: { callbacks: { label: (c) => ' ' + c.parsed.y + '%' } } },
                                scales: { y: { beginAtZero: true, max: 100, grid, ticks: { ...tick, callback: (v) => v + '%' } }, x: { grid: { display: false }, ticks: tick } } } }),
                    }[kind]();
                    this.chart = new window.Chart(el, cfg);
                },
            });
        </script>
    @endonce
</div>
