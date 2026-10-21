<?php

use App\Models\Activity;
use App\Models\BudgetExecution;
use App\Models\Directorate;
use App\Models\IndicatorProgress;
use App\Policies\ActivityPolicy;
use App\Services\ReportingWindows;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

/**
 * Report execution: for one approved activity and one month, what it spent (monthly) and what it achieved
 * (indicators are reported once a quarter, so the month's quarter is shown). This page holds the choice of
 * activity and month and the one action that concerns both ("submit everything"); the spending and the
 * indicators are two panels that follow the same rules: the unit reports, someone else verifies, only
 * verified figures count.
 */
new class extends Component {
    public int $year;
    public int $month = 1;
    #[\Livewire\Attributes\Url(as: 'activity')]
    public ?int $activityId = null;
    public string $search = '';
    /** which activities the list shows: all | todo | waiting | done */
    public string $filter = 'all';

    private const PICKER_LIMIT = 100;

    public function mount(): void
    {
        $this->year = \App\Support\FiscalYear::current();
        $this->month = $this->latestMonth();
    }

    /** The newest month that has started: reporting on a month that has not begun makes no sense. */
    private function latestMonth(): int
    {
        return match (true) {
            now()->year < $this->year => 1,
            now()->year > $this->year => 12,
            default => now()->month,
        };
    }

    public function updatedMonth(): void
    {
        $this->month = max(1, min((int) $this->month, $this->latestMonth()));
    }

    /** Indicators are reported once a quarter: the month's quarter. */
    public function getQuarterProperty(): int
    {
        return (int) ceil($this->month / 3);
    }

    public function updatedSearch(): void
    {
        $this->activityId = null;
    }

    /** Approved activities that have something to report: a distributed budget line or an indicator. */
    public function getActivitiesProperty()
    {
        $user = auth()->user();
        $query = Activity::where('fiscal_year', $this->year)->where('version', '>', 0) // an approved version in force, even while a revision is in progress
            ->where(fn ($q) => $q->whereHas('lineItems.adjustedBudget')->orWhereHas('indicators'))
            ->select(['id', 'code', 'description', 'directorate_id'])->with('directorate:id,code');

        if (! $user->can('view_all_paa') && ! $user->isAdministrator()) {
            $query->whereIn('directorate_id', Directorate::find($user->directorate_id)?->getSelfAndDescendantIds() ?? [0]);
        }
        if ($this->search !== '') {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $this->search).'%';
            $query->where(fn ($w) => $w->where('description', 'like', $term)->orWhere('code', 'like', $term));
        }

        return $query->orderBy('directorate_id')->orderBy('id')->limit(self::PICKER_LIMIT + 1)->get();
    }

    /**
     * The worklist: each approved activity with where its spending (this month) and its indicators (this quarter) stand.
     * State per part: none (nothing to report), todo, returned, waiting (for verification), done. Four grouped queries.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function getWorklistProperty()
    {
        $activities = $this->activities->take(self::PICKER_LIMIT)->values();
        $ids = $activities->pluck('id');

        $lines = DB::table('line_items as li')->join('adjusted_budgets as ab', 'ab.line_item_id', '=', 'li.id')->whereIn('li.activity_id', $ids)
            ->groupBy('li.activity_id')->selectRaw('li.activity_id, COUNT(*) AS n')->pluck('n', 'activity_id');
        $spend = DB::table('budget_executions as be')->join('line_items as li', 'li.id', '=', 'be.line_item_id')->whereIn('li.activity_id', $ids)->where('be.month', $this->month)
            ->groupBy('li.activity_id', 'be.status')->selectRaw('li.activity_id, be.status, COUNT(*) AS n')->get()->groupBy('activity_id');

        $due = \App\Models\Indicator::whereIn('activity_id', $ids)->get(['id', 'activity_id', 'due_quarters'])->filter(fn ($i) => $i->isDueIn($this->quarter));
        $indicators = $due->countBy('activity_id');
        $progress = DB::table('indicator_progress as ip')->join('indicators as i', 'i.id', '=', 'ip.indicator_id')->whereIn('ip.indicator_id', $due->pluck('id'))->where('ip.quarter', $this->quarter)
            ->groupBy('i.activity_id', 'ip.status')->selectRaw('i.activity_id, ip.status, COUNT(*) AS n')->get()->groupBy('activity_id');

        return $activities->map(function ($a) use ($lines, $spend, $indicators, $progress) {
            $a->spendingState = $this->stateOf((int) ($lines[$a->id] ?? 0), $spend->get($a->id, collect()));
            $a->progressState = $this->stateOf((int) ($indicators[$a->id] ?? 0), $progress->get($a->id, collect()));
            $states = [$a->spendingState, $a->progressState];
            $a->overall = array_intersect($states, ['todo', 'returned']) ? 'todo' : (in_array('waiting', $states, true) ? 'waiting' : 'done');

            return $a;
        });
    }

    /** @param  \Illuminate\Support\Collection<int, object>  $rows  the counts per status of what was reported */
    private function stateOf(int $total, $rows): string
    {
        if ($total === 0) {
            return 'none';
        }
        $n = fn (string $status) => (int) ($rows->firstWhere('status', $status)->n ?? 0);
        $reported = $n('Verified') + $n('Submitted') + $n('Rejected') + $n('Draft');

        return match (true) {
            $n('Rejected') > 0 => 'returned',
            $reported < $total || $n('Draft') > 0 => 'todo',
            $n('Submitted') > 0 => 'waiting',
            default => 'done',
        };
    }

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['all', 'todo', 'waiting', 'done'], true) ? $filter : 'all';
    }

    public function getActivityProperty(): ?Activity
    {
        if (! $this->activityId) {
            return null;
        }
        $activity = Activity::with(['directorate:id,code,name', 'program:id,oge_code,name', 'subprogram:id,code,name'])->find($this->activityId);
        abort_unless($activity && app(ActivityPolicy::class)->inScope(auth()->user(), $activity->directorate_id), 403);

        return $activity;
    }

    /** The one action that concerns both panels. Each panel checks who may report, on its own. */
    public function submitAll(): void
    {
        abort_unless($this->activity, 404);
        $this->dispatch('submit-execution');
    }

    /** A panel changed something: show the new counts. */
    #[On('execution-changed')]
    public function refreshSummary(): void
    {
    }

    /** @return array{spending: array<string, int>, progress: array<string, int>} how many lines (this month) and indicators (this quarter) are in each state */
    private function summary(Activity $activity): array
    {
        $lines = DB::table('line_items as li')->join('adjusted_budgets as ab', 'ab.line_item_id', '=', 'li.id')->where('li.activity_id', $activity->id)->pluck('li.id');
        $spending = BudgetExecution::whereIn('line_item_id', $lines)->where('month', $this->month)->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->all();
        $spending['total'] = $lines->count();

        $indicators = $activity->indicators()->pluck('id');
        $progress = IndicatorProgress::whereIn('indicator_id', $indicators)->where('quarter', $this->quarter)->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->all();
        $progress['total'] = $indicators->count();

        return compact('spending', 'progress');
    }

    public function with(): array
    {
        $activity = $this->activity;

        return [
            'summary' => $activity ? $this->summary($activity) : null,
            'worklist' => $activity ? collect() : $this->worklist,
            'maxMonth' => $this->latestMonth(),
            'closed' => array_filter([
                __('Executed amount and monthly report') => app(ReportingWindows::class)->closedReason(auth()->user(), $this->year, 'month', $this->month),
                __('Indicators and quarterly report') => app(ReportingWindows::class)->closedReason(auth()->user(), $this->year, 'quarter', $this->quarter),
            ]),
            'canReport' => auth()->user()->can('update_indicator_progress') || auth()->user()->can('approve_progress_report'),
        ];
    }
}; ?>

<div class="space-y-6">
    <x-ui.page-header :title="__('Report execution')" :subtitle="__('For one approved activity: what it spent in a month and what it achieved in the quarter. Figures count once someone else has verified them.')">
        <span class="inline-flex items-center rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-800">{{ __('Fiscal year') }} {{ $year }}</span>
        @can('view_reports')
            <x-ui.button variant="secondary" size="md" shadow="none" href="{{ route('reports.performance', ['year' => $year, 'type' => 'month', 'period' => $month]) }}">{{ __('Performance report') }}</x-ui.button>
        @endcan
    </x-ui.page-header>

    <x-ui.card padding="p-5">
        <div class="flex flex-wrap items-end gap-4">
            <div>
                <label for="re-m" class="field-label">{{ __('Month') }}</label>
                <select id="re-m" wire:model.live="month" class="flux-input !w-auto">
                    @foreach(range(1, $maxMonth) as $m)<option value="{{ $m }}">{{ \Carbon\Carbon::create($year, $m, 1)->translatedFormat('F') }}</option>@endforeach
                </select>
            </div>
            <p class="max-w-xl pb-2 text-sm text-zinc-600">{{ __('You report the executed amount for the month you choose, and indicators for the quarter it belongs to (:months).', ['months' => \Carbon\Carbon::create($year, ($this->quarter - 1) * 3 + 1, 1)->translatedFormat('F').' – '.\Carbon\Carbon::create($year, $this->quarter * 3, 1)->translatedFormat('F')]) }}</p>
            @if(! $activityId)
                <div class="ml-auto w-full sm:w-72">
                    <label for="re-s" class="field-label">{{ __('Search activities') }}</label>
                    <input id="re-s" type="search" wire:model.live.debounce.300ms="search" class="flux-input" placeholder="{{ __('Code or description') }}">
                </div>
            @endif
        </div>
    </x-ui.card>

    @if(! $activityId)
        @php
            $activities = $this->activities;
            $counts = ['all' => $worklist->count(), 'todo' => $worklist->where('overall', 'todo')->count(), 'waiting' => $worklist->where('overall', 'waiting')->count(), 'done' => $worklist->where('overall', 'done')->count()];
            $shown = $filter === 'all' ? $worklist : $worklist->where('overall', $filter);
            $chips = ['all' => __('All'), 'todo' => __('To report'), 'waiting' => __('Waiting for verification'), 'done' => __('Done')];
            $badge = ['todo' => ['bg-amber-50 text-amber-800', __('To report')], 'returned' => ['bg-rose-50 text-rose-800', __('Returned')], 'waiting' => ['bg-sky-50 text-sky-800', __('Waiting for verification')], 'done' => ['bg-emerald-50 text-emerald-800', __('Verified')], 'none' => ['bg-zinc-100 text-zinc-500', __('Nothing to report')]];
        @endphp
        <x-ui.card padding="p-0">
            <div class="flex flex-wrap items-center gap-2 border-b border-zinc-200 px-5 py-3" role="tablist" aria-label="{{ __('Show') }}">
                @foreach($chips as $key => $label)
                    <button type="button" role="tab" aria-selected="{{ $filter === $key ? 'true' : 'false' }}" wire:click="setFilter('{{ $key }}')"
                        class="rounded-full px-3 py-1 text-xs font-medium {{ $filter === $key ? 'bg-indigo-600 text-white' : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200' }}">{{ $label }} <span class="tabular-nums opacity-80">{{ $counts[$key] }}</span></button>
                @endforeach
                <span class="ml-auto text-xs text-zinc-500">{{ __('Choose an activity to report it. Only activities with an approved version appear.') }}</span>
            </div>
            @if($shown->isEmpty())
                <x-ui.empty-state :title="$worklist->isEmpty() ? __('No approved activity to report') : __('Nothing in this list')" :text="$worklist->isEmpty() ? __('Only activities with an approved version can report execution.') : __('Try another filter or month.')" />
            @else
                <ul class="divide-y divide-zinc-100">
                    @foreach($shown as $a)
                        <li wire:key="wl-{{ $a->id }}">
                            <button type="button" wire:click="$set('activityId', {{ $a->id }})" class="flex w-full flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3 text-left hover:bg-zinc-50">
                                <span class="min-w-0 flex-1">
                                    <span class="block text-xs font-medium text-indigo-700">{{ $a->directorate?->code }}@if($a->code) · {{ $a->code }}@endif</span>
                                    <span class="block text-sm text-zinc-900">{{ \Illuminate\Support\Str::limit($a->description, 110) }}</span>
                                </span>
                                @foreach(['spendingState' => __('Executed amount'), 'progressState' => __('Indicators')] as $field => $what)
                                    <span class="w-44 shrink-0 text-xs"><span class="block text-zinc-500">{{ $what }}</span><span class="mt-0.5 inline-flex rounded-full px-2 py-0.5 font-medium {{ $badge[$a->$field][0] }}">{{ $badge[$a->$field][1] }}</span></span>
                                @endforeach
                                <span class="text-xs font-semibold text-indigo-700">{{ __('Open') }} →</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
                @if($activities->count() > 100)<p class="border-t border-zinc-100 px-5 py-3 text-xs text-zinc-500">{{ __('Showing the first 100: search to narrow the list.') }}</p>@endif
            @endif
        </x-ui.card>
    @else
        @php $activity = $this->activity; @endphp
        <x-ui.card padding="p-5">
            <button type="button" wire:click="$set('activityId', null)" class="mb-3 text-xs font-medium text-indigo-700 hover:underline">← {{ __('All activities') }}</button>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0">
                    <h2 class="text-base font-semibold text-zinc-900">{{ $activity->description }}</h2>
                    <p class="mt-1 text-sm text-zinc-500">
                        {{ $activity->directorate?->name }} · {{ $activity->program?->oge_code }}@if($activity->subprogram)/{{ $activity->subprogram->code }}@endif
                        · <a href="{{ route('activities.show', $activity) }}" wire:navigate class="font-medium text-indigo-700 hover:underline">{{ __('Open the activity') }}</a>
                    </p>
                </div>
                <dl class="flex shrink-0 flex-wrap gap-x-8 gap-y-2 text-sm">
                    @foreach(['spending' => __('Executed amount'), 'progress' => __('Indicators')] as $key => $label)
                        @php $c = $summary[$key]; @endphp
                        <div>
                            <dt class="text-xs font-medium text-zinc-500">{{ $label }} · {{ $key === 'spending' ? \Carbon\Carbon::create($year, $month, 1)->translatedFormat('F') : 'Q'.$this->quarter }}</dt>
                            <dd class="mt-0.5 tabular-nums text-zinc-900">
                                <span class="font-semibold">{{ $c['Verified'] ?? 0 }}</span> {{ __('verified') }}
                                <span class="text-zinc-400">/ {{ $c['total'] }}</span>
                                @if(($c['Submitted'] ?? 0) > 0)<span class="ml-2 text-xs text-sky-800">{{ $c['Submitted'] }} {{ __('waiting for verification') }}</span>@endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </x-ui.card>

        @foreach($closed as $what => $reason)
            <div role="status" class="rounded-lg border border-amber-200 bg-amber-50 px-5 py-3 text-sm text-amber-900"><span class="font-medium">{{ $what }}:</span> {{ $reason }}</div>
        @endforeach

        <nav aria-label="{{ __('What to do for this activity') }}" class="grid gap-3 sm:grid-cols-3">
            @foreach([['spending', '1', __('Executed amount'), __('Executed in :month', ['month' => \Carbon\Carbon::create($year, $month, 1)->translatedFormat('F')]), $summary['spending']], ['progress', '2', __('Indicators'), __('What was achieved in Q:q', ['q' => $this->quarter]), $summary['progress']], ['narrative', '3', __('Narrative'), __('The words that go with the numbers (optional)'), null]] as [$anchor, $n, $label, $hint, $c])
                <a href="#{{ $anchor }}" class="flex items-start gap-3 rounded-lg border border-zinc-200 bg-white p-3 text-sm hover:border-indigo-300">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-indigo-50 text-xs font-semibold text-indigo-800">{{ $n }}</span>
                    <span><span class="block font-medium text-zinc-900">{{ $label }}</span><span class="block text-xs text-zinc-500">{{ $hint }}</span>
                        @if($c)<span class="mt-1 block text-xs tabular-nums text-zinc-700">{{ $c['Verified'] ?? 0 }} {{ __('verified') }} / {{ $c['total'] }}@if(($c['Submitted'] ?? 0) > 0) · <span class="text-sky-800">{{ $c['Submitted'] }} {{ __('waiting for verification') }}</span>@endif</span>@endif</span>
                </a>
            @endforeach
        </nav>

        <div class="grid gap-6 xl:grid-cols-1">
            <div id="spending" class="scroll-mt-20"><livewire:pages.monitoring.execution-spending :activity-id="$activityId" :month="$month" :key="'spending-'.$activityId.'-'.$month" /></div>
            <div id="progress" class="scroll-mt-20"><livewire:pages.monitoring.execution-progress :activity-id="$activityId" :quarter="$this->quarter" :key="'progress-'.$activityId.'-'.$this->quarter" /></div>
            <div id="narrative" class="scroll-mt-20"><livewire:pages.monitoring.execution-narrative :activity-id="$activityId" :month="$month" :key="'narrative-'.$activityId.'-'.$month" /></div>
        </div>

        @if($canReport)
            <div class="sticky bottom-4 z-10 flex flex-col items-start justify-between gap-3 rounded-lg border border-indigo-200 bg-white px-5 py-4 shadow-lg sm:flex-row sm:items-center">
                <p class="text-sm text-zinc-700">{{ __('Done? Send the executed amount of :month and the indicators of Q:q for verification together. Someone else then checks them; until they do, they do not count.', ['month' => \Carbon\Carbon::create($year, $month, 1)->translatedFormat('F'), 'q' => $this->quarter]) }}</p>
                <x-ui.button type="button" wire:click="submitAll">{{ __('Send everything for verification') }}</x-ui.button>
            </div>
        @endif
    @endif
</div>
