<?php

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\FiscalEnvelope;
use App\Models\Indicator;
use App\Models\LineItem;
use App\Models\Program;
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
            $this->activity = $activity->load(['lineItems', 'indicators']);
            $this->year = (int) $activity->fiscal_year;
            $this->directorate_id = (string) $activity->directorate_id;
            $this->program_id = (string) $activity->program_id;
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
            $this->lines = $activity->lineItems->map(fn ($l) => ['class_id' => (string) $l->economic_classification_id, 'amount' => (string) (float) $l->estimated_amount])->all();
            $this->indicators = $activity->indicators->map(fn ($i) => ['name' => $i->name, 'type' => $i->type, 'unit' => $i->unit, 'measure' => $i->measure, 'target' => (string) (float) $i->annual_target])->all();
        } else {
            $this->authorize('create', Activity::class);
            $this->year = FiscalEnvelope::getEffectiveYear();
            $this->directorate_id = (string) ($user->directorate_id ?? '');
            $this->planned_start = "{$this->year}-01-01";
            $this->planned_end = "{$this->year}-12-31";
            $this->quarters = ['1', '2', '3', '4'];
            $this->manager_name = '';
        }

        $this->lines = $this->lines ?: [['class_id' => '', 'amount' => '']];
        $this->indicators = $this->indicators ?: [['name' => '', 'type' => 'Output', 'unit' => '', 'measure' => 'cumulative', 'target' => '']];
    }

    public function addLine(): void { $this->lines[] = ['class_id' => '', 'amount' => '']; }

    public function removeLine(int $i): void
    {
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines) ?: [['class_id' => '', 'amount' => '']];
    }

    /** A percentage is a level, not something that adds up over the quarters. */
    public function updatedIndicators($value, $key): void
    {
        [$i, $field] = array_pad(explode('.', (string) $key, 2), 2, null);
        if ($field === 'unit' && isset($this->indicators[(int) $i]) && trim((string) $value) === '%') {
            $this->indicators[(int) $i]['measure'] = 'level';
        }
    }

    public function addIndicator(): void { $this->indicators[] = ['name' => '', 'type' => 'Output', 'unit' => '', 'measure' => 'cumulative', 'target' => '']; }

    public function removeIndicator(int $i): void
    {
        unset($this->indicators[$i]);
        $this->indicators = array_values($this->indicators) ?: [['name' => '', 'type' => 'Output', 'unit' => '', 'measure' => 'cumulative', 'target' => '']];
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
            'lines.*.class_id' => ['required', 'exists:economic_classifications,id', 'distinct'],
            'lines.*.amount' => ['required', 'numeric', 'min:0.01', 'max:999999999999'],
            'indicators' => ['required', 'array', 'min:1'],
            'indicators.*.name' => ['required', 'string', 'max:255'],
            'indicators.*.type' => ['required', 'in:Output,Outcome'],
            'indicators.*.unit' => ['required', 'string', 'max:100'],
            'indicators.*.measure' => ['nullable', 'in:cumulative,level'],
            'indicators.*.target' => ['required', 'numeric', 'min:0'],
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
        abort_if(! $this->activity && ! FiscalEnvelope::planningOpen($this->year), 422);

        $this->validate($this->planRules(), [], [
            'lines.*.class_id' => __('economic category'), 'lines.*.amount' => __('amount'),
            'indicators.*.name' => __('indicator name'), 'indicators.*.unit' => __('unit of measure'), 'indicators.*.target' => __('target'),
        ]);

        $unit = Directorate::findOrFail($this->directorate_id);
        abort_unless(app(\App\Policies\ActivityPolicy::class)->inScope(auth()->user(), $unit->id), 403);
        if ($this->activity) {
            $this->authorize('update', $this->activity);
        }
        abort_if(FiscalEnvelope::where('fiscal_year', $this->year)->value('status') === FiscalEnvelope::STATUS_CLOSED, 422);

        $q = collect($this->quarters)->map(fn ($n) => (int) $n)->sort()->values();
        $data = [
            'program_id' => $this->program_id,
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

            $activity->lineItems()->delete();
            foreach ($this->lines as $l) {
                LineItem::create(['activity_id' => $activity->id, 'economic_classification_id' => $l['class_id'], 'estimated_amount' => round((float) $l['amount'], 2), 'is_test_data' => false]);
            }
            $activity->indicators()->delete();
            foreach ($this->indicators as $i) {
                Indicator::create(['activity_id' => $activity->id, 'name' => trim($i['name']), 'type' => $i['type'], 'unit' => trim($i['unit']), 'measure' => $i['measure'] ?? Indicator::defaultMeasure($i['unit']), 'annual_target' => $i['target']]);
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
            'classes' => EconomicClassification::orderBy('code')->get(['id', 'code', 'name']),
            'total' => $total,
            'locations' => config('paa.locations'),
            'title' => $this->activity ? __('Edit activity') : __('New activity'),
            'endedBy' => $this->activity ? null : FiscalEnvelope::planningEndedBy($this->year),
            'nextYear' => FiscalEnvelope::nextPlanningYear(),
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
                    <select id="program_id" wire:model="program_id" class="flux-input">
                        <option value="">{{ __('Select a programme') }}</option>
                        @foreach($programs as $p)<option value="{{ $p->id }}">{{ $p->oge_code }} — {{ $p->name }}</option>@endforeach
                    </select>
                    @if($programs->isEmpty())<p class="mt-1 text-xs text-amber-700">{{ __('No programs are registered for :year yet. An administrator can register them at Management > Entities.', ['year' => $year]) }}</p>@endif
                    @error('program_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
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
