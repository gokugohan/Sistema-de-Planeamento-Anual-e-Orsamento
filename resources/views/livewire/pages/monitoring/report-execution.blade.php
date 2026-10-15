<?php

use App\Models\Activity;
use App\Models\BudgetExecution;
use App\Models\Directorate;
use App\Models\FiscalEnvelope;
use App\Models\IndicatorProgress;
use App\Policies\ActivityPolicy;
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
    public ?int $activityId = null;
    public string $search = '';

    private const PICKER_LIMIT = 100;

    public function mount(): void
    {
        $this->year = FiscalEnvelope::getEffectiveYear();
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
        $query = Activity::where('fiscal_year', $this->year)->where('status', Activity::STATUS_APPROVED)
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
            'maxMonth' => $this->latestMonth(),
            'canReport' => auth()->user()->can('update_indicator_progress') || auth()->user()->can('approve_progress_report'),
        ];
    }
}; ?>

<div class="space-y-6">
    <x-ui.page-header :title="__('Report execution')" :subtitle="__('For one approved activity: what it spent in a month and what it achieved in the quarter. Figures count once someone else has verified them.')">
        <span class="inline-flex items-center rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-800">{{ __('Fiscal year') }} {{ $year }}</span>
    </x-ui.page-header>

    <x-ui.card padding="p-5">
        <div class="grid gap-4 md:grid-cols-12">
            <div class="md:col-span-2">
                <label for="re-m" class="field-label">{{ __('Month') }}</label>
                <select id="re-m" wire:model.live="month" class="flux-input">
                    @foreach(range(1, $maxMonth) as $m)<option value="{{ $m }}">{{ \Carbon\Carbon::create($year, $m, 1)->translatedFormat('F') }}</option>@endforeach
                </select>
            </div>
            <div class="md:col-span-3">
                <label for="re-s" class="field-label">{{ __('Search activities') }}</label>
                <input id="re-s" type="search" wire:model.live.debounce.300ms="search" class="flux-input" placeholder="{{ __('Code or description') }}">
            </div>
            <div class="md:col-span-7">
                <label for="re-a" class="field-label">{{ __('Activity') }}</label>
                @php $activities = $this->activities; @endphp
                <select id="re-a" wire:model.live="activityId" class="flux-input">
                    <option value="">{{ __('Select an approved activity') }}</option>
                    @foreach($activities->take(100) as $a)
                        <option value="{{ $a->id }}">{{ $a->directorate?->code }} — {{ $a->code ? $a->code.' · ' : '' }}{{ \Illuminate\Support\Str::limit($a->description, 90) }}</option>
                    @endforeach
                </select>
                @if($activities->count() > 100)<p class="field-hint">{{ __('Showing the first 100: search to narrow the list.') }}</p>@endif
            </div>
        </div>
    </x-ui.card>

    @if(! $activityId)
        <x-ui.card padding="p-0">
            <x-ui.empty-state :title="__('Choose an activity')" :text="__('Only approved activities with a distributed budget or indicators can report execution.')" />
        </x-ui.card>
    @else
        @php $activity = $this->activity; @endphp
        <x-ui.card padding="p-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0">
                    <h2 class="text-base font-semibold text-zinc-900">{{ $activity->description }}</h2>
                    <p class="mt-1 text-sm text-zinc-500">
                        {{ $activity->directorate?->name }} · {{ $activity->program?->oge_code }}@if($activity->subprogram)/{{ $activity->subprogram->code }}@endif
                        · <a href="{{ route('activities.show', $activity) }}" wire:navigate class="font-medium text-indigo-700 hover:underline">{{ __('Open the activity') }}</a>
                    </p>
                </div>
                <dl class="flex shrink-0 flex-wrap gap-x-8 gap-y-2 text-sm">
                    @foreach(['spending' => __('Spending'), 'progress' => __('Indicators')] as $key => $label)
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

        <div class="grid gap-6 xl:grid-cols-1">
            <livewire:pages.monitoring.execution-spending :activity-id="$activityId" :month="$month" :key="'spending-'.$activityId.'-'.$month" />
            <livewire:pages.monitoring.execution-progress :activity-id="$activityId" :quarter="$this->quarter" :key="'progress-'.$activityId.'-'.$this->quarter" />
            <livewire:pages.monitoring.execution-narrative :activity-id="$activityId" :month="$month" :key="'narrative-'.$activityId.'-'.$month" />
        </div>

        @if($canReport)
            <div class="flex flex-col items-start justify-between gap-3 rounded-lg border border-zinc-200 bg-white px-5 py-4 shadow-card sm:flex-row sm:items-center">
                <p class="text-sm text-zinc-600">{{ __('Done? Send the month\'s spending and the quarter\'s indicator figures for verification together.') }}</p>
                <x-ui.button type="button" wire:click="submitAll">{{ __('Submit everything for verification') }}</x-ui.button>
            </div>
        @endif
    @endif
</div>
