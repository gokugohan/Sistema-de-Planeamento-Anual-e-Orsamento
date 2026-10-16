<?php

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\FiscalEnvelope;
use App\Models\Indicator;
use App\Models\LineItem;
use App\Models\Program;
use App\Models\Subprogram;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;

/**
 * One page to plan an activity the way a Timor-Leste annual action plan describes it: what and why,
 * who and where, when, how much (by economic category) and how success is measured.
 * Used for both a new activity and the edit of a draft.
 */
new class extends Component {
    public ?Activity $activity = null;

    public int $year;
    public $directorate_id = '';
    public $program_id = '';
    public $subprogram_id = '';
    public string $description = '';
    public string $objective = '';
    public string $manager_name = '';
    public string $priority = 'Média';
    public string $location = '';
    public string $planned_start = '';
    public string $planned_end = '';
    public array $quarters = [];
    public bool $procurement_required = false;
    public bool $gender_responsive = false;

    /** @var array<int, array{class_id: string, amount: string}> */
    public array $lines = [];

    /** @var array<int, array{name: string, type: string, unit: string, target: string}> */
    public array $indicators = [];

    public function mount(?Activity $activity = null): void
    {
        $user = auth()->user();

        if ($activity && $activity->exists) {
            $this->authorize('update', $activity);
            $this->activity = $activity->load(['lineItems', 'indicators.actions']);
            $this->year = (int) $activity->fiscal_year;
            $this->directorate_id = (string) $activity->directorate_id;
            $this->program_id = (string) $activity->program_id;
            $this->subprogram_id = (string) ($activity->subprogram_id ?? '');
            $this->description = $activity->description;
            $this->objective = (string) $activity->objective;
            $this->manager_name = (string) $activity->manager_name;
            $this->priority = $activity->priority;
            $this->location = (string) $activity->location;
            $this->planned_start = $activity->planned_start->format('Y-m-d');
            $this->planned_end = $activity->planned_end->format('Y-m-d');
            $this->quarters = array_map('strval', $activity->execution_quarters ?: [$activity->quarter]);
            $this->procurement_required = (bool) $activity->procurement_required;
            $this->gender_responsive = (bool) $activity->gender_responsive;
            $indicatorIds = $activity->indicators->pluck('id')->values();
            $this->lines = $activity->lineItems->map(fn ($l) => [
                'class_id' => (string) $l->economic_classification_id, 'source_id' => (string) $l->funding_source_id, 'amount' => (string) (float) $l->estimated_amount,
                'indicator' => ($k = $indicatorIds->search($l->indicator_id)) !== false ? (string) $k : '',
            ])->all();
            $this->indicators = $activity->indicators->map(fn ($i) => [
                'id' => $i->id, 'name' => $i->name, 'type' => $i->type, 'unit' => $i->unit, 'measure' => $i->measure, 'target' => (string) (float) $i->annual_target,
                'baseline' => (string) $i->baseline, 'means' => (string) $i->means_of_verification,
                'quarters' => array_map('strval', $i->dueQuarters()),
                'actions' => $i->actions->map(fn ($a) => ['text' => $a->text, 'months' => array_map('strval', $a->monthList())])->all(),
            ])->all();
        } else {
            $this->authorize('create', Activity::class);
            $this->year = FiscalEnvelope::getEffectiveYear();
            $this->directorate_id = (string) ($user->directorate_id ?? '');
            $this->planned_start = "{$this->year}-01-01";
            $this->planned_end = "{$this->year}-12-31";
            $this->quarters = ['1', '2', '3', '4'];
            $this->manager_name = '';
        }

        $this->lines = $this->lines ?: [$this->blankLine()];
        $this->indicators = $this->indicators ?: [$this->blankIndicator()];
    }

    public function addLine(): void { $this->lines[] = $this->blankLine(); }

    private function blankLine(): array
    {
        return ['class_id' => '', 'source_id' => (string) \App\Models\FundingSource::defaultId(), 'amount' => '', 'indicator' => ''];
    }

    public function removeLine(int $i): void
    {
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines) ?: [$this->blankLine()];
    }

    /** Another programme has other subprogrammes: the choice starts again. */
    public function updatedProgramId(): void
    {
        $this->subprogram_id = '';
        $this->resetErrorBag('subprogram_id');
    }

    /** A percentage is a level, not something that adds up over the quarters. */
    public function updatedIndicators($value, $key): void
    {
        [$i, $field] = array_pad(explode('.', (string) $key, 2), 2, null);
        if ($field === 'unit' && isset($this->indicators[(int) $i]) && trim((string) $value) === '%') {
            $this->indicators[(int) $i]['measure'] = 'level';
        }
    }

    /** An empty indicator row: reported every quarter until said otherwise. */
    private function blankIndicator(): array
    {
        return ['id' => null, 'name' => '', 'type' => 'Output', 'unit' => '', 'measure' => 'cumulative', 'target' => '', 'baseline' => '', 'means' => '', 'quarters' => ['1', '2', '3', '4'], 'actions' => []];
    }

    public function addIndicator(): void { $this->indicators[] = $this->blankIndicator(); }

    public function removeIndicator(int $i): void
    {
        unset($this->indicators[$i]);
        $this->indicators = array_values($this->indicators) ?: [$this->blankIndicator()];
        // budget lines tied to it go back to the whole activity; those tied to later ones move up
        foreach ($this->lines as $n => $l) {
            $k = ($l['indicator'] ?? '') === '' ? null : (int) $l['indicator'];
            $this->lines[$n]['indicator'] = $k === null || $k === $i ? '' : (string) ($k > $i ? $k - 1 : $k);
        }
    }

    public function addAction(int $i): void
    {
        $this->indicators[$i]['actions'][] = ['text' => '', 'months' => []];
    }

    public function removeAction(int $i, int $a): void
    {
        unset($this->indicators[$i]['actions'][$a]);
        $this->indicators[$i]['actions'] = array_values($this->indicators[$i]['actions'] ?? []);
    }

    /** Units the user may plan for: their own and everything below it; everything for administrators and ministers. */
    private function units()
    {
        $user = auth()->user();
        $q = Directorate::orderBy('code');
        if (! ($user->can('view_all_paa') || $user->isAdministrator())) {
            $q->whereIn('id', Directorate::find($user->directorate_id)?->getSelfAndDescendantIds() ?? [0]);
        }

        return $q->get(['id', 'code', 'name']);
    }

    private function planRules(): array
    {
        $y = $this->year;

        return [
            'directorate_id' => ['required', 'exists:directorates,id'],
            'program_id' => ['required', 'exists:programs,id,fiscal_year,'.$this->year],
            // the subprogramme must belong to the chosen programme
            'subprogram_id' => ['nullable', \Illuminate\Validation\Rule::exists('subprograms', 'id')->where('program_id', (int) $this->program_id)],
            'description' => ['required', 'string', 'min:5', 'max:1000'],
            'objective' => ['nullable', 'string', 'max:2000'],
            'manager_name' => ['nullable', 'string', 'max:255'],
            'priority' => ['required', 'in:Alta,Média,Baixa'],
            'location' => ['nullable', 'in:'.implode(',', config('paa.locations'))],
            'planned_start' => ['required', 'date', "after_or_equal:{$y}-01-01", "before_or_equal:{$y}-12-31"],
            'planned_end' => ['required', 'date', 'after_or_equal:planned_start', "before_or_equal:{$y}-12-31"],
            'quarters' => ['required', 'array', 'min:1'],
            'quarters.*' => ['in:1,2,3,4'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.class_id' => ['required', 'exists:economic_classifications,id'],
            'lines.*.source_id' => ['nullable', 'exists:funding_sources,id'],
            'lines.*.indicator' => ['nullable', 'integer', 'min:0'],
            'lines.*.amount' => ['required', 'numeric', 'min:0.01', 'max:999999999999'],
            'indicators' => ['required', 'array', 'min:1'],
            'indicators.*.name' => ['required', 'string', 'max:255'],
            'indicators.*.type' => ['required', 'in:Output,Outcome'],
            'indicators.*.unit' => ['required', 'string', 'max:100'],
            'indicators.*.measure' => ['nullable', 'in:cumulative,level'],
            'indicators.*.target' => ['required', 'numeric', 'min:0'],
            'indicators.*.baseline' => ['nullable', 'string', 'max:500'],
            'indicators.*.means' => ['nullable', 'string', 'max:1000'],
            'indicators.*.actions' => ['nullable', 'array', 'max:20'],
            'indicators.*.actions.*.text' => ['required', 'string', 'max:1000'],
            'indicators.*.actions.*.months' => ['nullable', 'array'],
            'indicators.*.actions.*.months.*' => ['integer', 'between:1,12'],
            'indicators.*.quarters' => ['nullable', 'array'],
            'indicators.*.quarters.*' => ['in:1,2,3,4'],
        ];
    }

    private function nextCode(Directorate $unit): string
    {
        $prefix = $unit->code.'-'.substr((string) $this->year, -2).'-';
        $n = Activity::where('fiscal_year', $this->year)->where('directorate_id', $unit->id)->where('code', 'like', $prefix.'%')->count() + 1;

        return $prefix.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }

    public function save()
    {
        // First of all: a year whose plan has ended takes no new activity, whatever was typed.
        abort_if(! $this->activity && ! FiscalEnvelope::planningOpenFor($this->year, $this->directorate_id !== '' ? (int) $this->directorate_id : null), 422);

        // and outside the submission window of the annual plan nobody but an administrator creates, edits or submits
        if ($reason = app(\App\Services\ReportingWindows::class)->planClosedReason(auth()->user(), $this->year)) {
            $this->dispatch('toast', message: $reason, type: 'error');

            return;
        }

        $this->validate($this->planRules(), [], [
            'lines.*.class_id' => __('economic category'), 'lines.*.source_id' => __('funding source'), 'lines.*.amount' => __('amount'),
            'indicators.*.name' => __('indicator name'), 'indicators.*.unit' => __('unit of measure'), 'indicators.*.target' => __('target'),
            'indicators.*.actions.*.text' => __('action'),
        ]);

        // the months of an indicator must fall in the quarters the activity runs in
        $activityQuarters = collect($this->quarters)->map(fn ($n) => (int) $n);
        foreach ($this->indicators as $n => $ind) {
            foreach ((array) ($ind['actions'] ?? []) as $k => $action) {
                foreach ((array) ($action['months'] ?? []) as $month) {
                    if (! $activityQuarters->contains((int) ceil((int) $month / 3))) {
                        $this->addError("indicators.$n.actions.$k.months", __('A month must fall in a quarter the activity runs in.'));

                        return;
                    }
                }
            }
            if (isset($ind['actions']) === false) {
                continue;
            }
        }
        foreach ($this->lines as $n => $l) {
            if (($l['indicator'] ?? '') !== '' && ! isset($this->indicators[(int) $l['indicator']])) {
                $this->addError("lines.$n.indicator", __('Choose one of the indicators of this activity.'));

                return;
            }
        }

        // A category may be paid from two sources, but not twice from the same one.
        $pairs = collect($this->lines)->map(fn ($l) => $l['class_id'].'-'.(($l['source_id'] ?? '') ?: \App\Models\FundingSource::defaultId()).'-'.($l['indicator'] ?? ''));
        if ($pairs->count() !== $pairs->unique()->count()) {
            $this->addError('lines', __('A category appears twice with the same funding source (and indicator): join them in one line.'));

            return;
        }

        // A programme that is divided into subprogrammes needs one chosen.
        if (blank($this->subprogram_id) && Subprogram::where('program_id', (int) $this->program_id)->exists()) {
            $this->addError('subprogram_id', __('Choose the subprogram this activity belongs to.'));

            return;
        }

        $unit = Directorate::findOrFail($this->directorate_id);
        abort_unless(app(\App\Policies\ActivityPolicy::class)->inScope(auth()->user(), $unit->id), 403);
        if ($this->activity) {
            $this->authorize('update', $this->activity);
        }
        abort_if(FiscalEnvelope::where('fiscal_year', $this->year)->value('status') === FiscalEnvelope::STATUS_CLOSED, 422);

        $q = collect($this->quarters)->map(fn ($n) => (int) $n)->sort()->values();
        $data = [
            'program_id' => $this->program_id,
            'subprogram_id' => $this->subprogram_id ?: null,
            'directorate_id' => $unit->id,
            'description' => trim($this->description),
            'objective' => trim($this->objective) ?: null,
            'manager_name' => trim($this->manager_name) ?: null,
            'priority' => $this->priority,
            'location' => $this->location ?: null,
            'planned_start' => $this->planned_start,
            'planned_end' => $this->planned_end,
            'quarter' => $q->first(),
            'execution_quarters' => $q->all(),
            'period_type' => match (true) {
                $q->count() === 4 => 'annual',
                $q->count() === 1 => 'quarterly',
                $q->all() === [1, 2] || $q->all() === [3, 4] => 'semi_annual',
                default => 'custom',
            },
            'procurement_required' => $this->procurement_required,
            'gender_responsive' => $this->gender_responsive,
        ];

        $activity = DB::transaction(function () use ($data, $unit) {
            $activity = $this->activity
                ? tap($this->activity)->update($data)
                : Activity::create($data + ['fiscal_year' => $this->year, 'status' => Activity::STATUS_DRAFT, 'code' => $this->nextCode($unit), 'is_test_data' => false]);

            $keep = [];
            foreach ($this->indicators as $i) {
                $actions = collect($i['actions'] ?? [])->map(fn ($a) => ['text' => trim((string) ($a['text'] ?? '')), 'months' => collect($a['months'] ?? [])->map(fn ($m) => (int) $m)->unique()->sort()->values()->all()])->filter(fn ($a) => $a['text'] !== '')->values();
                $months = $actions->flatMap(fn ($a) => $a['months'])->unique()->sort()->values()->all();
                // the quarters of an indicator follow from the months of its actions when they have any
                $quarters = $months
                    ? collect($months)->map(fn ($m) => (int) ceil($m / 3))->unique()->sort()->values()->all()
                    : collect($i['quarters'] ?? [])->map(fn ($q) => (int) $q)->unique()->sort()->values()->all();
                $attrs = [
                    'name' => trim($i['name']), 'type' => $i['type'], 'unit' => trim($i['unit']), 'measure' => $i['measure'] ?? Indicator::defaultMeasure($i['unit']),
                    'annual_target' => $i['target'], 'baseline' => trim((string) ($i['baseline'] ?? '')) ?: null,
                    'means_of_verification' => trim((string) ($i['means'] ?? '')) ?: null,
                    // every quarter is the default: store nothing for it
                    'due_quarters' => ($quarters && $quarters !== [1, 2, 3, 4]) ? $quarters : null,
                ];
                $existing = ! empty($i['id']) ? $activity->indicators()->find($i['id']) : null;
                if ($existing) {
                    $existing->update($attrs); // updated in place, so its quarterly progress is kept
                    $indicator = $existing;
                } else {
                    $indicator = Indicator::create($attrs + ['activity_id' => $activity->id]);
                }
                $keep[] = $indicator->id;
                $indicator->actions()->delete();
                foreach ($actions as $pos => $a) {
                    $indicator->actions()->create(['position' => $pos + 1, 'text' => $a['text'], 'months' => $a['months'] ?: null]);
                }
            }
            $activity->indicators()->whereNotIn('id', $keep)->delete();

            $activity->lineItems()->delete();
            foreach ($this->lines as $l) {
                LineItem::create([
                    'activity_id' => $activity->id, 'economic_classification_id' => $l['class_id'], 'funding_source_id' => ($l['source_id'] ?? '') ?: null,
                    'indicator_id' => ($l['indicator'] ?? '') !== '' ? ($keep[(int) $l['indicator']] ?? null) : null,
                    'estimated_amount' => round((float) $l['amount'], 2), 'is_test_data' => false,
                ]);
            }

            return $activity;
        });

        session()->flash('success', __('Activity saved.'));

        return $this->redirect(route('activities.show', $activity), navigate: true);
    }

    public function with(): array
    {
        $total = collect($this->lines)->sum(fn ($l) => is_numeric($l['amount'] ?? null) ? (float) $l['amount'] : 0);

        return [
            'units' => $this->units(),
            'programs' => Program::forYear($this->year)->orderBy('oge_code')->get(['id', 'oge_code', 'name']),
            'subprograms' => $this->program_id ? Subprogram::where('program_id', (int) $this->program_id)->orderBy('code')->get(['id', 'code', 'name']) : collect(),
            'classes' => EconomicClassification::orderBy('code')->get(['id', 'code', 'name']),
            'sources' => \App\Models\FundingSource::where('active', true)->orWhereIn('id', collect($this->lines)->pluck('source_id')->filter())->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
            'total' => $total,
            'locations' => config('paa.locations'),
            'title' => $this->activity ? __('Edit activity') : __('New activity'),
            'endedBy' => $this->activity ? null : FiscalEnvelope::planningEndedByFor($this->year, $this->directorate_id !== '' ? (int) $this->directorate_id : null),
            'windowReason' => app(\App\Services\ReportingWindows::class)->planClosedReason(auth()->user(), $this->year),
            'nextYear' => $this->directorate_id !== '' ? app(\App\Services\PlanningWindow::class)->nextOpenYearFor((int) $this->directorate_id) : FiscalEnvelope::nextPlanningYear(),
        ];
    }
}; ?>

<div class="space-y-6 max-w-5xl">
    <div>
        <h1 class="page-title">{{ $title }}</h1>
        <p class="page-subtitle">
            {{ __('Annual Activity Plan :year', ['year' => $year]) }}@if($activity) · {{ $activity->code }}@endif
            — {{ __('saved as a draft; submit it for review from the activity page once it is complete.') }}
        </p>
    </div>

    @if($endedBy)
        <x-ui.card>
            <div class="flex flex-col items-start gap-3">
                <h2 class="text-base font-semibold text-zinc-900">{{ __('Planning for :year has ended', ['year' => $year]) }}</h2>
                <p class="max-w-2xl text-sm text-zinc-600">
                    @if($endedBy === 'Closed')
                        {{ __('The planning cycle for :year is closed, so activities can no longer be added.', ['year' => $year]) }}
                    @elseif($endedBy === 'Approved')
                        {{ __('The budget for :year has been received, so its plan is final: only the distribution of the envelope can still change.', ['year' => $year]) }}
                    @else
                        {{ __('The plan for :year has been finalized, so activities can no longer be added. Only the final approver or an administrator can reopen planning, with a remark.', ['year' => $year]) }}
                    @endif
                </p>
                <div class="flex flex-wrap gap-2">
                    <x-ui.button href="{{ route('fiscal-year.switch', $nextYear) }}">{{ __('Plan for :year', ['year' => $nextYear]) }}</x-ui.button>
                    <x-ui.button variant="secondary" shadow="none" href="{{ route('activities.index') }}" wire:navigate>{{ __('Back to activities') }}</x-ui.button>
                </div>
            </div>
        </x-ui.card>
    @else
    @if($windowReason)
        <div role="status" class="rounded-lg border border-amber-200 bg-amber-50 px-5 py-3 text-sm text-amber-900">{{ $windowReason }} {{ __('You can look at the activity but not save changes.') }}</div>
    @endif
    <form wire:submit="save" class="space-y-6">
        {{-- 1. What and why --}}
        <x-ui.card>
            <h2 class="text-sm font-semibold text-zinc-900">{{ __('1. The activity') }}</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="directorate_id" class="block text-sm font-medium text-zinc-700 mb-1">{{ __('Responsible unit') }}</label>
                    <select id="directorate_id" wire:model="directorate_id" class="flux-input">
                        <option value="">{{ __('Select a unit') }}</option>
                        @foreach($units as $u)<option value="{{ $u->id }}">{{ $u->code }} — {{ $u->name }}</option>@endforeach
                    </select>
                    @error('directorate_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="program_id" class="block text-sm font-medium text-zinc-700 mb-1">{{ __('Programme') }}</label>
                    <select id="program_id" wire:model.live="program_id" class="flux-input">
                        <option value="">{{ __('Select a programme') }}</option>
                        @foreach($programs as $p)<option value="{{ $p->id }}">{{ $p->oge_code }} — {{ $p->name }}</option>@endforeach
                    </select>
                    @if($programs->isEmpty())<p class="mt-1 text-xs text-amber-700">{{ __('No programs are registered for :year yet. An administrator can register them at Management > Entities.', ['year' => $year]) }}</p>@endif
                    @error('program_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    @if($subprograms->isNotEmpty())
                        <label for="subprogram_id" class="mt-3 block text-sm font-medium text-zinc-700 mb-1">{{ __('Subprogram') }}</label>
                        <select id="subprogram_id" wire:model="subprogram_id" class="flux-input">
                            <option value="">{{ __('Select a subprogram') }}</option>
                            @foreach($subprograms as $s)<option value="{{ $s->id }}">{{ $s->code }} — {{ $s->name }}</option>@endforeach
                        </select>
                        @error('subprogram_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    @endif
                </div>
                <div class="sm:col-span-2">
                    <label for="description" class="block text-sm font-medium text-zinc-700 mb-1">{{ __('Activity') }}</label>
                    <input id="description" type="text" wire:model="description" class="flux-input" maxlength="1000" placeholder="{{ __('What will be done, in one line') }}">
                    @error('description')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="objective" class="block text-sm font-medium text-zinc-700 mb-1">{{ __('Objective / expected result') }} <span class="font-normal text-zinc-400">({{ __('optional') }})</span></label>
                    <textarea id="objective" wire:model="objective" rows="2" class="flux-input" placeholder="{{ __('What should be different once it is done?') }}"></textarea>
                    @error('objective')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="manager_name" class="block text-sm font-medium text-zinc-700 mb-1">{{ __('Person responsible') }} <span class="font-normal text-zinc-400">({{ __('optional') }})</span></label>
                    <input id="manager_name" type="text" wire:model="manager_name" class="flux-input">
                </div>
                <div>
                    <label for="priority" class="block text-sm font-medium text-zinc-700 mb-1">{{ __('Priority') }}</label>
                    <select id="priority" wire:model="priority" class="flux-input">
                        <option value="Alta">{{ __('High') }}</option>
                        <option value="Média">{{ __('Medium') }}</option>
                        <option value="Baixa">{{ __('Low') }}</option>
                    </select>
                </div>
            </div>
        </x-ui.card>

        {{-- 2. When and where --}}
        <x-ui.card>
            <h2 class="text-sm font-semibold text-zinc-900">{{ __('2. When and where') }}</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <div>
                    <label for="planned_start" class="block text-sm font-medium text-zinc-700 mb-1">{{ __('Start') }}</label>
                    <input id="planned_start" type="date" wire:model="planned_start" min="{{ $year }}-01-01" max="{{ $year }}-12-31" class="flux-input">
                    @error('planned_start')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="planned_end" class="block text-sm font-medium text-zinc-700 mb-1">{{ __('End') }}</label>
                    <input id="planned_end" type="date" wire:model="planned_end" min="{{ $year }}-01-01" max="{{ $year }}-12-31" class="flux-input">
                    @error('planned_end')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="location" class="block text-sm font-medium text-zinc-700 mb-1">{{ __('Municipality / region') }}</label>
                    <select id="location" wire:model="location" class="flux-input">
                        <option value="">{{ __('Not specified') }}</option>
                        @foreach($locations as $loc)<option value="{{ $loc }}">{{ $loc === 'Nationwide' ? __('Nationwide') : $loc }}</option>@endforeach
                    </select>
                </div>
            </div>
            <fieldset class="mt-4">
                <legend class="block text-sm font-medium text-zinc-700 mb-2">{{ __('Quarters in which it is carried out') }}</legend>
                <div class="flex flex-wrap gap-2">
                    @foreach(['1', '2', '3', '4'] as $n)
                        <label class="inline-flex items-center gap-2 rounded-lg ring-1 ring-zinc-200 px-3 py-2 text-sm cursor-pointer has-[:checked]:bg-indigo-50 has-[:checked]:ring-indigo-300">
                            <input type="checkbox" value="{{ $n }}" wire:model="quarters" class="rounded border-zinc-300 text-indigo-600">
                            Q{{ $n }}
                        </label>
                    @endforeach
                </div>
                @error('quarters')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
            </fieldset>
            <div class="mt-4 flex flex-wrap gap-x-8 gap-y-2">
                <label class="inline-flex items-center gap-2 text-sm text-zinc-700">
                    <input type="checkbox" wire:model="procurement_required" class="rounded border-zinc-300 text-indigo-600">
                    {{ __('Needs procurement (goods, works or services to be contracted)') }}
                </label>
                <label class="inline-flex items-center gap-2 text-sm text-zinc-700">
                    <input type="checkbox" wire:model="gender_responsive" class="rounded border-zinc-300 text-indigo-600">
                    {{ __('Contributes to gender equality (gender-responsive budgeting)') }}
                </label>
            </div>
        </x-ui.card>

        {{-- 3. Budget --}}
        <x-ui.card>
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-zinc-900">{{ __('3. Budget by economic category') }}</h2>
                <p class="text-sm text-zinc-500">{{ __('Total') }} <span class="font-semibold text-zinc-900 tabular-nums">$ {{ number_format($total, 2) }}</span></p>
            </div>
            <div class="mt-4 space-y-2">
                @foreach($lines as $i => $line)
                    <div class="flex items-start gap-2" wire:key="line-{{ $i }}">
                        <div class="flex-1">
                            <select wire:model="lines.{{ $i }}.class_id" class="flux-input" aria-label="{{ __('Economic category') }}">
                                <option value="">{{ __('Select a category') }}</option>
                                @foreach($classes as $c)<option value="{{ $c->id }}">{{ $c->code }} — {{ $c->name }}</option>@endforeach
                            </select>
                            @error("lines.$i.class_id")<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                        </div>
                        <div class="w-48">
                            <select wire:model="lines.{{ $i }}.source_id" class="flux-input" aria-label="{{ __('Funding source') }}">
                                @foreach($sources as $s)<option value="{{ $s->id }}">{{ __($s->name) }}</option>@endforeach
                            </select>
                            @error("lines.$i.source_id")<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                        </div>
                        <div class="w-48">
                            <select wire:model="lines.{{ $i }}.indicator" class="flux-input" aria-label="{{ __('For which indicator') }}">
                                <option value="">{{ __('Whole activity') }}</option>
                                @foreach($indicators as $n => $ind)<option value="{{ $n }}">{{ $n + 1 }}. {{ \Illuminate\Support\Str::limit($ind['name'] ?: __('Indicator'), 28) }}</option>@endforeach
                            </select>
                            @error("lines.$i.indicator")<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                        </div>
                        <div class="w-44">
                            <input type="number" step="0.01" min="0" wire:model.live.debounce.400ms="lines.{{ $i }}.amount" class="flux-input text-right tabular-nums" placeholder="0.00" aria-label="{{ __('Amount') }}">
                            @error("lines.$i.amount")<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                        </div>
                        <button type="button" wire:click="removeLine({{ $i }})" class="mt-2 p-1 text-zinc-400 hover:text-rose-600" aria-label="{{ __('Remove') }}">✕</button>
                    </div>
                @endforeach
            </div>
            <x-ui.button type="button" variant="secondary" size="sm" class="mt-3" wire:click="addLine">+ {{ __('Add category') }}</x-ui.button>
            @error('lines')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
        </x-ui.card>

        {{-- 4. Indicators --}}
        <x-ui.card>
            <h2 class="text-sm font-semibold text-zinc-900">{{ __('4. How success is measured') }}</h2>
            <p class="text-sm text-zinc-500 mt-1">{{ __('An output is what the activity delivers (e.g. 12 inspections); an outcome is the change it brings.') }}</p>
            <div class="mt-4 space-y-3">
                @foreach($indicators as $i => $ind)
                    <div class="grid gap-2 sm:grid-cols-[1fr_7rem_8rem_9rem_7rem_auto] items-start" wire:key="ind-{{ $i }}">
                        <div>
                            <input type="text" wire:model="indicators.{{ $i }}.name" class="flux-input" placeholder="{{ __('Indicator, e.g. Inspections completed') }}" aria-label="{{ __('Indicator name') }}">
                            @error("indicators.$i.name")<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                        </div>
                        <select wire:model="indicators.{{ $i }}.type" class="flux-input" aria-label="{{ __('Type') }}">
                            <option value="Output">{{ __('Output') }}</option>
                            <option value="Outcome">{{ __('Outcome') }}</option>
                        </select>
                        <div>
                            <input type="text" wire:model.blur="indicators.{{ $i }}.unit" class="flux-input" placeholder="{{ __('Unit, e.g. reports') }}" aria-label="{{ __('Unit of measure') }}">
                            @error("indicators.$i.unit")<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                        </div>
                        <select wire:model="indicators.{{ $i }}.measure" class="flux-input" aria-label="{{ __('How it is measured') }}" title="{{ __('Adds up: each quarter adds to the total. Level: the latest quarter is where it stands (for rates such as %).') }}">
                            <option value="cumulative">{{ __('Adds up') }}</option>
                            <option value="level">{{ __('Level') }}</option>
                        </select>
                        <div>
                            <input type="number" step="any" min="0" wire:model="indicators.{{ $i }}.target" class="flux-input text-right" placeholder="{{ __('Target') }}" aria-label="{{ __('Annual target') }}">
                            @error("indicators.$i.target")<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                        </div>
                        <button type="button" wire:click="removeIndicator({{ $i }})" class="mt-2 p-1 text-zinc-400 hover:text-rose-600" aria-label="{{ __('Remove') }}">✕</button>

                        <div class="sm:col-span-full" x-data="{ open: false }">
                            <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" class="text-xs font-medium text-indigo-700 hover:underline">
                                <span x-show="! open">{{ __('More details: baseline, how it is verified, reporting quarters') }}</span>
                                <span x-show="open" x-cloak>{{ __('Hide details') }}</span>
                            </button>
                            <div x-show="open" x-cloak class="mt-2 grid gap-3 rounded-md border border-zinc-200 bg-zinc-50 p-3 sm:grid-cols-2">
                                <div>
                                    <label for="ind-{{ $i }}-baseline" class="field-label">{{ __('Baseline') }}</label>
                                    <input id="ind-{{ $i }}-baseline" type="text" wire:model="indicators.{{ $i }}.baseline" class="flux-input" placeholder="{{ __('Where it stands today, e.g. 4 or 0%') }}">
                                    @error("indicators.$i.baseline")<p class="field-error">{{ $message }}</p>@enderror
                                </div>
                                <div class="sm:col-span-2">
                                    <label for="ind-{{ $i }}-means" class="field-label">{{ __('Means of verification') }}</label>
                                    <textarea id="ind-{{ $i }}-means" rows="2" wire:model="indicators.{{ $i }}.means" class="flux-input" placeholder="{{ __('What proves it, e.g. signed reports, system logs') }}"></textarea>
                                    @error("indicators.$i.means")<p class="field-error">{{ $message }}</p>@enderror
                                </div>
                                <div class="sm:col-span-2">
                                    <p class="field-label">{{ __('Implementation plan') }}</p>
                                    <p class="field-hint">{{ __('The actions that carry out this indicator, each with the months it takes place in. When months are chosen, the quarters it is reported in follow from them.') }}</p>
                                    <div class="mt-2 space-y-3">
                                        @foreach($ind['actions'] ?? [] as $k => $action)
                                            <div class="rounded-md border border-zinc-200 bg-white p-3" wire:key="ind-{{ $i }}-act-{{ $k }}">
                                                <div class="flex items-start gap-2">
                                                    <span class="mt-2 w-5 text-sm tabular-nums text-zinc-400">{{ $k + 1 }}.</span>
                                                    <textarea rows="2" wire:model="indicators.{{ $i }}.actions.{{ $k }}.text" class="flux-input flex-1" aria-label="{{ __('Action') }} {{ $k + 1 }}" placeholder="{{ __('What will be done') }}"></textarea>
                                                    <button type="button" wire:click="removeAction({{ $i }}, {{ $k }})" class="mt-2 p-1 text-zinc-400 hover:text-rose-600" aria-label="{{ __('Remove') }}">✕</button>
                                                </div>
                                                @error("indicators.$i.actions.$k.text")<p class="field-error">{{ $message }}</p>@enderror
                                                <div class="mt-2 flex flex-wrap gap-1.5 pl-7">
                                                    @foreach(range(1, 12) as $mo)
                                                        <label class="inline-flex items-center gap-1 rounded border border-zinc-300 bg-white px-2 py-0.5 text-xs text-zinc-700">
                                                            <input type="checkbox" value="{{ $mo }}" wire:model="indicators.{{ $i }}.actions.{{ $k }}.months" class="rounded border-zinc-300 text-indigo-600"> {{ \Carbon\Carbon::create(2000, $mo, 1)->translatedFormat('M') }}
                                                        </label>
                                                    @endforeach
                                                </div>
                                                @error("indicators.$i.actions.$k.months")<p class="field-error pl-7">{{ $message }}</p>@enderror
                                            </div>
                                        @endforeach
                                    </div>
                                    <x-ui.button type="button" variant="secondary" size="sm" class="mt-2" wire:click="addAction({{ $i }})">+ {{ __('Add action') }}</x-ui.button>
                                </div>
                                <fieldset class="sm:col-span-2">
                                    <legend class="field-label">{{ __('Reported in quarters') }}</legend>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach([1, 2, 3, 4] as $q)
                                            <label class="inline-flex items-center gap-1.5 rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm text-zinc-700">
                                                <input type="checkbox" value="{{ $q }}" wire:model="indicators.{{ $i }}.quarters" class="rounded border-zinc-300 text-indigo-600"> Q{{ $q }}
                                            </label>
                                        @endforeach
                                    </div>
                                </fieldset>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            <x-ui.button type="button" variant="secondary" size="sm" class="mt-3" wire:click="addIndicator">+ {{ __('Add indicator') }}</x-ui.button>
        </x-ui.card>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ $activity ? route('activities.show', $activity) : route('activities.index') }}" wire:navigate class="text-sm font-medium text-zinc-600 hover:text-zinc-900">{{ __('Cancel') }}</a>
            <x-ui.button type="submit" wire:loading.attr="disabled">{{ __('Save draft') }}</x-ui.button>
        </div>
    </form>
    @endif
</div>
