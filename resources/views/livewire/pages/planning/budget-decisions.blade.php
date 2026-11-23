<?php

use App\Models\BudgetDecision;
use App\Models\EconomicClassification;
use App\Services\BudgetDecisions;
use App\Services\BudgetDistributions;
use App\Support\PlanOptions;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * The decisions on the institution's budget for the year (budget ceiling, budget review committee, proposal, State
 * Budget law, supplementary budget) by economic category, and the plan against the latest one. An optional rule. A
 * decision limits nothing by itself: where the plan has to follow it, the units revise their activities. An
 * administrator records a decision, and so does the budget unit where the budget is distributed among the units
 * (a second optional rule, the Distribution tab).
 */
new class extends Component {
    #[Url]
    public string $tab = 'decisions';

    public string $stage = 'committee';
    public string $committed = '';
    public string $newPolicySpace = '';
    public string $plansDueOn = '';
    public string $decidedOn = '';
    public string $reference = '';
    public string $note = '';
    public array $amounts = [];
    public bool $showForm = false;

    public function mount(): void
    {
        abort_unless(PlanOptions::current()->budgetDecisions, 404);
        $user = auth()->user();
        abort_unless($user->isAdministrator() || $user->can('view_all_paa') || app(BudgetDistributions::class)->canSeeAll($user), 403);
        $this->decidedOn = now()->toDateString();
        $this->noIncrease = implode(', ', PlanOptions::noIncreaseCodesFor(\App\Support\FiscalYear::current()));
        if (! BudgetDistributions::on() || ! in_array($this->tab, ['decisions', 'distribution'], true)) {
            $this->tab = 'decisions';
        }
    }

    /**
     * Open the form at the stage that comes next in the year (only the stages not yet passed can be chosen), with the
     * amounts of the latest decision, which usually change only in some categories.
     */
    public function openForm(): void
    {
        $decisions = app(BudgetDecisions::class);
        abort_unless($decisions->canRecord(auth()->user()), 403);
        $year = \App\Support\FiscalYear::current();
        $this->stage = $decisions->nextStages($year)[0];
        $latest = $decisions->latest($year);
        $this->amounts = array_map(fn ($v) => (string) $v, $latest?->amounts ?? []);
        if ($latest && $latest->decided_on->toDateString() > $this->decidedOn) {
            $this->decidedOn = $latest->decided_on->toDateString();
        }
        $this->showForm = true;
    }

    /** A total (the ceiling of the institution's budget title, for example) to share among the categories by the plan's percentages. */
    public string $spread = '';

    /** The account codes the year's budget execution decree does not let a revision increase (per year, not a setting). */
    public string $noIncrease = '';

    public function saveNoIncrease(BudgetDecisions $decisions): void
    {
        abort_unless($decisions->canRecord(auth()->user()), 403);
        $this->validate(['noIncrease' => 'nullable|string|max:500']);
        PlanOptions::setNoIncreaseCodes(\App\Support\FiscalYear::current(), $this->noIncrease);
        $this->noIncrease = implode(', ', PlanOptions::noIncreaseCodesFor(\App\Support\FiscalYear::current()));
        $this->dispatch('toast', message: __('Saved.'), type: 'success');
    }

    /** The whole plan of each category (approved and still being prepared): a decision that follows the plan. */
    public function copyPlan(BudgetDecisions $decisions): void
    {
        abort_unless($decisions->canRecord(auth()->user()), 403);
        $this->amounts = [];
        foreach ($decisions->planByClass(\App\Support\FiscalYear::current()) as $id => $p) {
            if (($whole = round($p['in_force'] + $p['preparing'], 2)) > 0) {
                $this->amounts[$id] = (string) $whole;
            }
        }
    }

    /**
     * Share a total among the categories in the proportions of the plan, in whole dollars; what rounding leaves goes to
     * the largest category, so that the amounts add up to the total.
     */
    public function spreadTotal(BudgetDecisions $decisions): void
    {
        abort_unless($decisions->canRecord(auth()->user()), 403);
        $this->validate(['spread' => 'required|numeric|gt:0'], [], ['spread' => __('total')]);
        $plan = array_filter(array_map(fn ($p) => $p['in_force'] + $p['preparing'], $decisions->planByClass(\App\Support\FiscalYear::current())), fn ($v) => $v > 0);
        if (! $plan) {
            $this->addError('spread', __('The plan has no amounts yet: type the amount of each category.'));

            return;
        }
        $total = round((float) $this->spread, 2);
        $sum = array_sum($plan);
        $amounts = array_map(fn ($v) => floor($total * $v / $sum), $plan);
        $largest = array_search(max($amounts), $amounts, true);
        $amounts[$largest] = round($amounts[$largest] + $total - array_sum($amounts), 2);
        $this->amounts = array_map(fn ($v) => (string) $v, $amounts);
        $this->reset('spread');
    }

    public function record(BudgetDecisions $decisions): void
    {
        abort_unless($decisions->canRecord(auth()->user()), 403);
        $this->validate([
            'stage' => 'required|in:'.implode(',', array_keys(BudgetDecision::STAGES)),
            'decidedOn' => 'required|date',
            'reference' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:2000',
            'amounts' => 'array',
            'amounts.*' => 'nullable|numeric|min:0',
            'committed' => 'nullable|numeric|min:0',
            'newPolicySpace' => 'nullable|numeric|min:0',
            'plansDueOn' => 'nullable|date|after_or_equal:decidedOn',
        ]);
        try {
            $decisions->record(auth()->user(), \App\Support\FiscalYear::current(), $this->stage, $this->decidedOn, $this->amounts, $this->reference, $this->note,
                $this->stage === 'ceiling' ? $this->committed : null, $this->stage === 'ceiling' ? $this->newPolicySpace : null, $this->plansDueOn ?: null);
        } catch (\DomainException $e) {
            $this->addError('amounts', $e->getMessage());

            return;
        }
        $this->reset('reference', 'note', 'amounts', 'showForm', 'committed', 'newPolicySpace', 'spread', 'plansDueOn');
        $this->dispatch('toast', message: __('Budget decision recorded.'), type: 'success');
    }

    public function with(BudgetDecisions $decisions): array
    {
        $year = \App\Support\FiscalYear::current();

        return [
            'year' => $year,
            'gap' => $decisions->gap($year),
            'units' => $decisions->byUnit($year),
            'history' => $decisions->history($year),
            'classes' => EconomicClassification::orderBy('code')->get(['id', 'code', 'name']),
            'canRecord' => $decisions->canRecord(auth()->user()),
            'distributionOn' => BudgetDistributions::on(),
            'changeChecks' => PlanOptions::current()->budgetChangeChecks,
            'formPlan' => $this->showForm ? $decisions->planByClass($year) : [],
            'nextStages' => $decisions->nextStages($year),
            'steps' => $this->tab === 'decisions' ? $decisions->nextSteps($year) : null,
            'calendar' => $calendar = ($this->tab === 'decisions' ? $decisions->calendar($year) : null),
            // the dates the calendar gives each stage, shown beside the date of a decision being recorded
            'stageDates' => collect($calendar['rows'] ?? [])->where('kind', 'budget')->filter(fn ($r) => $r['opens'])
                ->mapWithKeys(fn ($r) => [$r['key'] => [$r['opens']->toDateString(), $r['closes']->toDateString()]])->all(),
        ];
    }
}; ?>

@php
    $money = fn ($v) => '$ '.number_format((float) $v, 0);
    $signed = fn ($v) => ($v > 0.5 ? '+' : ($v < -0.5 ? '−' : '')).'$ '.number_format(abs((float) $v), 0);
    $tone = fn ($v) => $v > 0.5 ? 'text-rose-700' : ($v < -0.5 ? 'text-emerald-700' : 'text-zinc-700');
    $percent = fn ($part, $whole) => $whole > 0 ? number_format(100 * $part / $whole, 1).'%' : '—';
@endphp

<div class="space-y-6">
    <x-ui.page-header :title="$distributionOn ? __('Budget decisions and distribution') : __('Budget decisions')" :subtitle="$distributionOn
        ? __('What was decided about the budget for :year, by category, and how it is shared among the units. Each unit plans within its share.', ['year' => $year])
        : __('What was decided about the budget for :year, by category, and the plan against it. A decision limits nothing: where the plan has to follow it, the units revise their activities.', ['year' => $year])">
        @if($gap && $tab === 'decisions')
            <x-ui.button variant="secondary" size="md" shadow="none" href="{{ route('planning.adjustment') }}" wire:navigate>{{ __('Adjustment round') }}</x-ui.button>
        @endif
        @if($canRecord && ! $showForm && $tab === 'decisions')
            <x-ui.button size="md" wire:click="openForm">{{ __('Record a decision') }}</x-ui.button>
        @endif
    </x-ui.page-header>

    @if($distributionOn)
        <nav class="flex gap-1 border-b border-zinc-200" aria-label="{{ __('Sections') }}">
            @foreach(['decisions' => __('Decisions'), 'distribution' => __('Distribution among the units')] as $key => $label)
                <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['-mb-px border-b-2 px-4 py-2 text-sm font-medium',
                    'border-indigo-600 text-indigo-700' => $tab === $key, 'border-transparent text-zinc-600 hover:text-zinc-900' => $tab !== $key])
                    aria-current="{{ $tab === $key ? 'page' : 'false' }}">{{ $label }}</button>
            @endforeach
        </nav>
    @endif

    @if($tab === 'distribution')
        <livewire:pages.planning.budget-distribution :year="$year" :key="'distribution-'.$year" />
    @else

    @if($showForm)
        <x-ui.card :title="__('Record a decision')" :description="__('The plan in force at this moment is kept with the decision, so that it can be printed later as it stood.')" padding="p-5">
            <form wire:submit="record" class="space-y-4">
                {{-- the stage: only those the year has not passed yet, the expected one chosen; one envelope a year --}}
                <fieldset>
                    <legend class="field-label">{{ __('Stage of the budget') }}</legend>
                    <p class="mb-2 text-xs text-zinc-500">{{ __('The institution has one budget envelope a year; record each time its amount is set or changed. The latest one is in force. Stages already passed are not offered.') }}</p>
                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach($nextStages as $i => $key)
                            <label wire:key="bd-stage-{{ $key }}" @class(['flex cursor-pointer gap-3 rounded-lg border p-3 text-sm',
                                'border-indigo-500 bg-indigo-50 ring-1 ring-indigo-500' => $stage === $key, 'border-zinc-200 hover:border-zinc-300' => $stage !== $key])>
                                <input type="radio" name="bd-stage" value="{{ $key }}" wire:model.live="stage" class="mt-0.5 border-zinc-300 text-indigo-600">
                                <span>
                                    <span class="block font-medium text-zinc-900">{{ __(BudgetDecision::STAGES[$key]) }}
                                        @if($i === 0 && count($nextStages) > 1)<span class="ml-1 rounded-full bg-indigo-100 px-1.5 py-0.5 text-[11px] font-medium text-indigo-700">{{ __('Next') }}</span>@endif</span>
                                    <span class="mt-0.5 block text-xs text-zinc-600">{{ __(BudgetDecision::HINTS[$key]) }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('stage')<p class="field-error">{{ $message }}</p>@enderror
                </fieldset>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="bd-date" class="field-label">{{ __('Date of the decision') }}</label>
                        <input id="bd-date" type="date" wire:model="decidedOn" class="flux-input">
                        @if($w = $stageDates[$stage] ?? null)
                            <p class="mt-1 text-xs" x-data="{ o: '{{ $w[0] }}', c: '{{ $w[1] }}' }" :class="$wire.decidedOn && ($wire.decidedOn < o || $wire.decidedOn > c) ? 'font-medium text-amber-800' : 'text-zinc-500'">
                                {{ __('The calendar expects this stage between :from and :to.', ['from' => \Carbon\Carbon::parse($w[0])->format('d/m/Y'), 'to' => \Carbon\Carbon::parse($w[1])->format('d/m/Y')]) }}
                                <span x-show="$wire.decidedOn && ($wire.decidedOn < o || $wire.decidedOn > c)" x-cloak>{{ __('This date is outside them: check it.') }}</span>
                            </p>
                        @endif
                        @error('decidedOn')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="bd-ref" class="field-label">{{ __('Reference') }}</label>
                        <input id="bd-ref" type="text" wire:model="reference" class="flux-input" placeholder="{{ __('For example the number of the law or of the minutes') }}">
                    </div>
                </div>
                <div>
                    <label for="bd-due" class="field-label">{{ __('Plans to be corrected by (optional)') }}</label>
                    <input id="bd-due" type="date" wire:model="plansDueOn" class="flux-input sm:!w-60">
                    <p class="mt-1 text-xs text-zinc-500">{{ __('The date the budget circular (or the Ministry of Finance) gives for the corrected plans. Where the plan has to change, the adjustment round closes on it.') }}</p>
                    @error('plansDueOn')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div x-show="$wire.stage === 'ceiling'" x-cloak class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="bd-committed" class="field-label">{{ __('Already committed (optional)') }}</label>
                        <input id="bd-committed" type="number" min="0" step="0.01" wire:model="committed" class="flux-input tabular-nums">
                        <p class="mt-1 text-xs text-zinc-500">{{ __('What the circular says is needed for commitments already made.') }}</p>
                        @error('committed')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="bd-space" class="field-label">{{ __('Room for new policies (optional)') }}</label>
                        <input id="bd-space" type="number" min="0" step="0.01" wire:model="newPolicySpace" class="flux-input tabular-nums">
                        <p class="mt-1 text-xs text-zinc-500">{{ __('The fiscal space for new policies and investments the circular gives.') }}</p>
                        @error('newPolicySpace')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div>
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <p class="field-label">{{ __('Amount decided by category, beside the plan') }}</p>
                        <div class="flex flex-wrap items-end gap-2">
                            <x-ui.button variant="secondary" size="md" shadow="none" type="button" wire:click="copyPlan" wire:confirm="{{ __('Replace the amounts typed so far with the plan?') }}">{{ __('Use the plan amounts') }}</x-ui.button>
                            <div>
                                <label for="bd-total" class="text-xs text-zinc-600">{{ __('A total to share by the plan\'s percentages') }}</label>
                                <div class="flex gap-2">
                                    <input id="bd-total" type="number" min="0" step="1" wire:model="spread" class="flux-input w-40 tabular-nums" placeholder="$">
                                    <x-ui.button variant="secondary" size="md" shadow="none" type="button" wire:click="spreadTotal">{{ __('Share it') }}</x-ui.button>
                                </div>
                            </div>
                        </div>
                    </div>
                    @php
                        // the plan beside each category: approved, and approved with what is still being prepared
                        $js = "m(x) { return (x < -0.5 ? '−$ ' : (x > 0.5 && this.sign ? '+$ ' : '$ ')) + new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(Math.abs(x)) }, p(x) { return new Intl.NumberFormat('en-US', { maximumFractionDigits: 1 }).format(x) + '%' }, n(v) { return parseFloat(v) || 0 }, sign: false, get total() { let s = 0; const a = \$wire.amounts || {}; for (const k in a) { s += this.n(a[k]) } return s }";
                        $planTotal = array_sum(array_map(fn ($p) => $p['in_force'] + $p['preparing'], $formPlan));
                    @endphp
                    <div class="mt-2 overflow-x-auto rounded-lg border border-zinc-200">
                        <table class="min-w-full text-sm">
                            <thead class="bg-zinc-50 text-xs text-zinc-600">
                                <tr>
                                    <th scope="col" class="px-4 py-2 text-left font-medium">{{ __('Category') }}</th>
                                    <th scope="col" class="px-4 py-2 text-right font-medium">{{ __('Approved plan amount') }}</th>
                                    <th scope="col" class="px-4 py-2 text-right font-medium">{{ __('Still being prepared') }}</th>
                                    <th scope="col" class="px-4 py-2 text-right font-medium">{{ __('Whole plan') }}</th>
                                    <th scope="col" class="px-4 py-2 text-right font-medium">{{ __('% of the plan') }}</th>
                                    <th scope="col" class="px-4 py-2 text-right font-medium">{{ __('Amount decided') }}</th>
                                    <th scope="col" class="px-4 py-2 text-right font-medium">{{ __('% of the decision') }}</th>
                                    <th scope="col" class="px-4 py-2 text-right font-medium">{{ __('Decision against the plan') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100">
                                @foreach($classes as $c)
                                    @php $fp = $formPlan[$c->id] ?? ['in_force' => 0, 'preparing' => 0]; $whole = $fp['in_force'] + $fp['preparing']; @endphp
                                    <tr wire:key="bd-amt-{{ $c->id }}">
                                        <th scope="row" class="px-4 py-2 text-left font-medium text-zinc-900"><label for="bd-amt-{{ $c->id }}">{{ $c->code }} {{ $c->name }}</label></th>
                                        <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-zinc-700">{{ $money($fp['in_force']) }}</td>
                                        <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-zinc-500">{{ $fp['preparing'] > 0 ? $money($fp['preparing']) : '—' }}</td>
                                        <td class="whitespace-nowrap px-4 py-2 text-right font-medium tabular-nums text-zinc-900">{{ $money($whole) }}</td>
                                        <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-zinc-600">{{ $percent($whole, $planTotal) }}</td>
                                        <td class="px-4 py-2 text-right">
                                            <div class="flex items-center justify-end gap-1.5">
                                                @if($whole > 0)
                                                    <button type="button" x-on:click="$wire.$set('amounts.{{ $c->id }}', '{{ round($whole, 2) }}', false)"
                                                        class="rounded-md p-1.5 text-zinc-500 hover:bg-indigo-50 hover:text-indigo-700"
                                                        title="{{ __('Copy the whole plan (:amount)', ['amount' => $money($whole)]) }}"
                                                        aria-label="{{ __('Copy the whole plan of :category into the amount decided', ['category' => $c->code.' '.$c->name]) }}">
                                                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="7" y="7" width="10" height="10" rx="2"/><path d="M13 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/></svg>
                                                    </button>
                                                @endif
                                                <input id="bd-amt-{{ $c->id }}" type="number" min="0" step="0.01" wire:model="amounts.{{ $c->id }}" class="flux-input w-40 text-right tabular-nums">
                                            </div>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-zinc-600"
                                            x-data="{ {!! $js !!}, get v() { return this.n(($wire.amounts || {})[{{ $c->id }}]) } }" x-text="total > 0 ? p(100 * v / total) : '—'"></td>
                                        <td class="whitespace-nowrap px-4 py-2 text-right font-medium tabular-nums"
                                            x-data="{ {!! $js !!}, sign: true, w: {{ $whole }}, get d() { return this.n(($wire.amounts || {})[{{ $c->id }}]) - this.w } }"
                                            :class="d < -0.5 ? 'text-rose-700' : (d > 0.5 ? 'text-emerald-700' : 'text-zinc-700')" x-text="m(d)"></td>
                                    </tr>
                                @endforeach
                                <tr class="bg-zinc-50 font-semibold">
                                    <th scope="row" class="px-4 py-2 text-left">{{ __('Total') }}</th>
                                    <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ $money(array_sum(array_column($formPlan, 'in_force'))) }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ $money(array_sum(array_column($formPlan, 'preparing'))) }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ $money($planTotal) }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ $planTotal > 0 ? '100%' : '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums" x-data="{ {!! $js !!} }" x-text="m(total)"></td>
                                    <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums" x-data="{ {!! $js !!} }" x-text="total > 0 ? '100%' : '—'"></td>
                                    <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums" x-data="{ {!! $js !!}, sign: true, get d() { return this.total - {{ $planTotal }} } }"
                                        :class="d < -0.5 ? 'text-rose-700' : (d > 0.5 ? 'text-emerald-700' : 'text-zinc-700')" x-text="m(d)"></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-1 text-xs text-zinc-500">{{ $distributionOn ? __('The plan counts only the money of the institution\'s own budget title.') : __('The plan counts every source of money.') }}
                        {{ __('In red: the decision is below the plan in that category, and the activities concerned will have to be revised.') }}</p>
                    @error('amounts')<p class="field-error">{{ $message }}</p>@enderror
                    @error('amounts.*')<p class="field-error">{{ $message }}</p>@enderror
                    @error('spread')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="bd-note" class="field-label">{{ __('Note') }}</label>
                    <textarea id="bd-note" rows="2" wire:model="note" class="flux-input"></textarea>
                </div>
                <div class="flex justify-end gap-2">
                    <x-ui.button variant="secondary" type="button" wire:click="$set('showForm', false)">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit">{{ __('Record') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    {{-- the calendar of the year's budget: when each stage is expected, what was recorded, where it stands --}}
    @if($calendar)
        @php
            $chip = [
                'recorded' => ['bg-emerald-50 text-emerald-800', __('Recorded')], 'open' => ['bg-indigo-50 text-indigo-800', __('Expected now')],
                'upcoming' => ['bg-zinc-100 text-zinc-700', __('Upcoming')], 'late' => ['bg-amber-100 text-amber-900', __('Overdue')],
                'not_recorded' => ['bg-zinc-100 text-zinc-500', __('Not recorded')], 'skipped' => ['bg-zinc-100 text-zinc-500', __('Passed over')],
                'if_needed' => ['bg-zinc-100 text-zinc-600', __('If one is approved')], 'undated' => ['bg-zinc-100 text-zinc-500', __('No dates')],
                'closed' => ['bg-zinc-100 text-zinc-600', __('Closed')],
            ];
            $final = $calendar['final'];
            $latestDecision = $calendar['latest'];
            $prevTotal = null;
        @endphp
        <x-ui.card padding="p-0">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 px-5 py-3">
                <div>
                    <h2 class="text-sm font-semibold text-zinc-900">{{ __('Calendar of the :year budget', ['year' => $year]) }}</h2>
                    <p class="text-xs text-zinc-500">{{ __('Mostly the year before: the plans, the ceiling, the review and the proposal are provisional; the State Budget law approved by Parliament is the final envelope.') }}</p>
                </div>
                @if(auth()->user()->isAdministrator())
                    <a href="{{ route('management.reporting-windows') }}" wire:navigate class="text-sm font-medium text-indigo-700 hover:underline">{{ __('Set the dates') }}</a>
                @endif
            </div>

            {{-- the envelope that counts --}}
            @if($final)
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 border-b border-emerald-100 bg-emerald-50/70 px-5 py-3">
                    <span class="rounded-full bg-emerald-600 px-2 py-0.5 text-xs font-semibold text-white">{{ __('Final envelope') }}</span>
                    <span class="text-xl font-semibold tabular-nums text-zinc-900">{{ $money($final->total) }}</span>
                    <span class="text-sm text-zinc-700">{{ $final->stageLabel() }} · {{ $final->decided_on->format('d/m/Y') }}@if($final->reference) · {{ $final->reference }}@endif</span>
                </div>
            @else
                <div class="border-b border-amber-100 bg-amber-50/70 px-5 py-3 text-sm text-amber-900">
                    <span class="font-semibold">{{ __('No final envelope yet.') }}</span>
                    @if($latestDecision)
                        {{ __('Planning works with the provisional :stage of :amount until the State Budget law is recorded.', ['stage' => $latestDecision->stageLabel(), 'amount' => $money($latestDecision->total)]) }}
                    @else
                        {{ __('Record each stage as it comes; the State Budget law approved by Parliament sets the final envelope.') }}
                    @endif
                    @if($calendar['extended'])
                        <span class="mt-1 block font-medium">{{ __('No State Budget law for :year is recorded. Since 1 January the law of :before is extended, and spending runs at one twelfth a month (Lei n.º 3/2025, art. 35 and 46). Record the law as soon as it is published.', ['year' => $year, 'before' => $year - 1]) }}</span>
                    @elseif($calendar['law_due'])
                        <span class="mt-1 block font-medium">{{ __('The State Budget law was expected by :date: record it as soon as it is published.', ['date' => $calendar['law_due']->format('d/m/Y')]) }}</span>
                    @endif
                </div>
            @endif

            <ol class="divide-y divide-zinc-100">
                @foreach($calendar['rows'] as $row)
                    <li wire:key="cal-{{ $row['kind'] }}-{{ $row['key'] }}" @class(['grid gap-2 px-5 py-2.5 sm:grid-cols-12 sm:items-center', 'bg-emerald-50/40' => $row['final'] && $row['decisions']->isNotEmpty()])>
                        <div class="sm:col-span-4">
                            <p class="text-sm font-medium {{ $row['kind'] === 'plan' ? 'text-zinc-600' : 'text-zinc-900' }}">
                                {{ $row['label'] }}
                                @if($row['final'])<span class="ml-1 rounded-full bg-emerald-50 px-1.5 py-0.5 text-[11px] font-semibold text-emerald-800">{{ __('Final envelope') }}</span>
                                @elseif($row['kind'] === 'budget')<span class="ml-1 rounded-full bg-zinc-100 px-1.5 py-0.5 text-[11px] font-medium text-zinc-600">{{ __('Provisional') }}</span>
                                @else<span class="ml-1 rounded-full bg-sky-50 px-1.5 py-0.5 text-[11px] font-medium text-sky-800">{{ __('Plan') }}</span>@endif
                            </p>
                            <p class="text-xs tabular-nums text-zinc-500">{{ $row['opens'] ? $row['opens']->format('d/m/Y').' – '.$row['closes']->format('d/m/Y') : __('No dates in the calendar') }}</p>
                            @if($row['note'] ?? null)<p class="text-xs text-zinc-500">{{ $row['note'] }}</p>@endif
                        </div>
                        <div class="text-sm sm:col-span-6">
                            @foreach($row['decisions'] as $dec)
                                @php $delta = $prevTotal !== null ? (float) $dec->total - $prevTotal : null; $prevTotal = (float) $dec->total; @endphp
                                <p class="tabular-nums">
                                    <span class="font-semibold text-zinc-900">{{ $money($dec->total) }}</span>
                                    <span class="text-zinc-500">· {{ $dec->stageLabel() }}, {{ $dec->decided_on->format('d/m/Y') }}</span>
                                    @if($delta !== null && abs($delta) >= 0.5)<span class="font-medium {{ $delta < 0 ? 'text-rose-700' : 'text-emerald-700' }}">{{ $signed($delta) }}</span>@endif
                                    @if($latestDecision && $dec->is($latestDecision))<span class="ml-1 text-xs font-medium text-indigo-700">{{ __('In force') }}</span>@endif
                                </p>
                            @endforeach
                            @if($row['late'] ?? false)<p class="text-xs text-amber-800">{{ __('Recorded after its dates.') }}</p>@endif
                        </div>
                        <div class="sm:col-span-2 sm:text-right">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $chip[$row['status']][0] }}">{{ $row['kind'] === 'plan' && $row['status'] === 'open' ? __('Open') : $chip[$row['status']][1] }}</span>
                        </div>
                    </li>
                @endforeach
            </ol>
        </x-ui.card>
    @endif

    @if(! $gap)
        <x-ui.card padding="p-0"><x-ui.empty-state :title="__('No budget decision for :year yet', ['year' => $year])" :text="__('When the budget review committee, the State Budget law or a supplementary budget sets the amount for the institution, record it here to compare it with the plan.')" /></x-ui.card>
    @else
        @php $d = $gap['decision']; @endphp

        {{-- what to do now that the envelope is this amount --}}
        @if($steps)
            @php
                $badge = [
                    'done' => ['bg-emerald-50 text-emerald-800', __('Done')], 'doing' => ['bg-indigo-50 text-indigo-800', __('In progress')],
                    'waiting' => ['bg-amber-50 text-amber-800', __('Waiting for approval')], 'todo' => ['bg-zinc-100 text-zinc-700', __('To do')],
                    'problem' => ['bg-rose-50 text-rose-800', __('To fix')], 'none' => ['bg-zinc-100 text-zinc-500', __('Not needed')],
                ];
                $n = 0;
                $ch = $steps['change'];
            @endphp
            <x-ui.card :title="match ($steps['decision']->stage) { 'supplementary' => __('After the supplementary budget'), 'law' => __('After the State Budget law: the final envelope'), default => __('After this decision') }" padding="p-0">
                @if($ch)
                    <div class="border-b border-zinc-100 px-5 py-3 text-sm text-zinc-700">
                        {{ __('The envelope went from :before (:from) to :after:', ['before' => $money($ch['previous']->total), 'from' => $ch['previous']->stageLabel(), 'after' => $money($ch['decision']->total)]) }}
                        <span class="font-semibold tabular-nums {{ $ch['difference'] < -0.5 ? 'text-rose-700' : ($ch['difference'] > 0.5 ? 'text-emerald-700' : '') }}">{{ $signed($ch['difference']) }}</span>
                        @if($ch['rows'])
                            <span class="text-zinc-500">({{ collect($ch['rows'])->map(fn ($r) => $r['code'].' '.$r['name'].' '.$signed($r['difference']))->implode('; ') }})</span>
                        @endif
                    </div>
                @endif
                <ol class="divide-y divide-zinc-100">
                    @if(isset($steps['steps']['distribute']))
                        @php $st = $steps['steps']['distribute']['status']; @endphp
                        <li class="flex flex-wrap items-start gap-3 px-5 py-3">
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-xs font-semibold text-zinc-700">{{ ++$n }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-zinc-900">{{ __('Share the new amount among the units') }} <span class="ml-1 rounded-full px-2 py-0.5 text-xs font-medium {{ $badge[$st][0] }}">{{ $badge[$st][1] }}</span></p>
                                <p class="text-xs text-zinc-600">{{ __('The shares start from the same percentages as before; the budget unit adjusts and submits them, and the head of the institution approves them.') }}</p>
                            </div>
                            @if($st !== 'done')<x-ui.button variant="secondary" size="md" shadow="none" wire:click="$set('tab', 'distribution')">{{ __('Open the distribution') }}</x-ui.button>@endif
                        </li>
                    @endif
                    @php $st = $steps['steps']['spent']['status']; @endphp
                    <li class="flex flex-wrap items-start gap-3 px-5 py-3">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-xs font-semibold text-zinc-700">{{ ++$n }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-zinc-900">{{ __('No cut below what was already spent') }} <span class="ml-1 rounded-full px-2 py-0.5 text-xs font-medium {{ $badge[$st][0] }}">{{ $badge[$st][1] }}</span></p>
                            <p class="text-xs text-zinc-600">{{ __('An amount already executed is not undone: the decision, and each unit\'s share, must be at least what was spent in each category.') }}</p>
                            @if($steps['steps']['spent']['items'])
                                <ul class="mt-1 space-y-0.5 text-xs text-rose-800">
                                    @foreach($steps['steps']['spent']['items'] as $it)
                                        <li>{{ $it['unit'] ? $it['unit'].' · ' : __('Institution').' · ' }}{{ $it['code'] }} {{ $it['name'] }}: {{ __(':limit available, :spent already spent', ['limit' => $money($it['limit']), 'spent' => $money($it['executed'])]) }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </li>
                    @php $st = $steps['steps']['adjust']['status']; $adj = $steps['steps']['adjust']; @endphp
                    <li class="flex flex-wrap items-start gap-3 px-5 py-3">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-xs font-semibold text-zinc-700">{{ ++$n }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-zinc-900">{{ __('Bring the plan to the new amount') }} <span class="ml-1 rounded-full px-2 py-0.5 text-xs font-medium {{ $badge[$st][0] }}">{{ $badge[$st][1] }}</span></p>
                            <p class="text-xs text-zinc-600">
                                @if($adj['over'] >= 0.5){{ __('The plan is :amount above the decision: the units revise their activities in an adjustment round.', ['amount' => $money($adj['over'])]) }}@endif
                                @if($adj['units_over'] > 0){{ trans_choice(':count unit is above its share.|:count units are above their share.', $adj['units_over']) }}@endif
                                @if($steps['increase']){{ __('The envelope went up: the round can take new activities.') }}@endif
                                @if($st === 'none'){{ __('The plan fits the decision: no adjustment round is needed.') }}@endif
                            </p>
                        </div>
                        @if(in_array($st, ['todo', 'doing'], true))<x-ui.button variant="secondary" size="md" shadow="none" href="{{ route('planning.adjustment') }}" wire:navigate>{{ __('Open the adjustment round') }}</x-ui.button>@endif
                    </li>
                </ol>
            </x-ui.card>
        @endif
        <div class="grid gap-3 sm:grid-cols-4">
            <x-ui.card padding="p-4"><p class="text-xs text-zinc-500">{{ $d->isFinal() ? __('Final envelope') : __('Provisional envelope') }}</p><p class="mt-1 text-xl font-semibold tabular-nums text-zinc-900">{{ $money($gap['decided']) }}</p><p class="text-xs text-zinc-500">{{ $d->stageLabel() }} · {{ $d->decided_on->format('d/m/Y') }}</p></x-ui.card>
            <x-ui.card padding="p-4"><p class="text-xs text-zinc-500">{{ __('Approved plan amount') }}</p><p class="mt-1 text-xl font-semibold tabular-nums text-zinc-900">{{ $money($gap['in_force']) }}</p></x-ui.card>
            <x-ui.card padding="p-4"><p class="text-xs text-zinc-500">{{ __('Plan against the decision') }}</p><p class="mt-1 text-xl font-semibold tabular-nums {{ $tone($gap['difference']) }}">{{ $signed($gap['difference']) }}</p>
                <p class="text-xs text-zinc-500">{{ $gap['difference'] > 0.5 ? __('The plan is above the decision') : ($gap['difference'] < -0.5 ? __('The plan is below the decision') : __('The plan matches the decision')) }}</p></x-ui.card>
            <x-ui.card padding="p-4"><p class="text-xs text-zinc-500">{{ __('Still being prepared') }}</p><p class="mt-1 text-xl font-semibold tabular-nums text-zinc-900">{{ $money($gap['preparing']) }}</p><p class="text-xs text-zinc-500">{{ __('Not approved yet, not in the plan amount') }}</p></x-ui.card>
        </div>

        <x-ui.card :title="__('By category')" :description="__('Each category\'s part of the decision and of the plan, and how much of what was decided the plan takes up. A positive difference is what the plan has above the decision in that category: the activities concerned are revised to reduce it.')" padding="p-0">
            <x-ui.table :headers="[__('Category'), __('Decided'), __('% of the decision'), __('Approved plan amount'), __('% of the plan'), __('Plan against the decided'), __('Difference'), __('Still being prepared')]" :right="[1, 2, 3, 4, 6, 7]" class="text-sm">
                @foreach($gap['rows'] as $r)
                    @php $used = $r['decided'] > 0 ? 100 * $r['in_force'] / $r['decided'] : null; @endphp
                    <tr wire:key="bd-row-{{ $r['id'] }}">
                        <td class="px-5 py-2.5 font-medium text-zinc-900">{{ $r['code'] }} {{ $r['name'] }}</td>
                        <td class="whitespace-nowrap px-5 py-2.5 num">{{ $money($r['decided']) }}</td>
                        <td class="whitespace-nowrap px-5 py-2.5 num text-zinc-600">{{ $percent($r['decided'], $gap['decided']) }}</td>
                        <td class="whitespace-nowrap px-5 py-2.5 num">{{ $money($r['in_force']) }}</td>
                        <td class="whitespace-nowrap px-5 py-2.5 num text-zinc-600">{{ $percent($r['in_force'], $gap['in_force']) }}</td>
                        <td class="min-w-36 px-5 py-2.5">
                            @if($used !== null)
                                <div class="flex items-center gap-2">
                                    <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-zinc-100" aria-hidden="true">
                                        <div @class(['h-full rounded-full', 'bg-rose-500' => $used > 100.05, 'bg-indigo-500' => $used <= 100.05]) style="width: {{ min(100, round($used, 1)) }}%"></div>
                                    </div>
                                    <span @class(['w-14 text-right text-xs tabular-nums', 'font-medium text-rose-700' => $used > 100.05, 'text-zinc-600' => $used <= 100.05])>{{ number_format($used, 1) }}%</span>
                                </div>
                            @else
                                <span class="text-xs text-zinc-500">{{ __('Nothing decided') }}</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-5 py-2.5 num font-medium {{ $tone($r['difference']) }}">{{ $signed($r['difference']) }}</td>
                        <td class="whitespace-nowrap px-5 py-2.5 num text-zinc-500">{{ $r['preparing'] > 0 ? $money($r['preparing']) : '—' }}</td>
                    </tr>
                @endforeach
                @php $usedAll = $gap['decided'] > 0 ? 100 * $gap['in_force'] / $gap['decided'] : null; @endphp
                <tr class="bg-zinc-50 font-semibold">
                    <td class="px-5 py-2.5">{{ __('Total') }}</td>
                    <td class="whitespace-nowrap px-5 py-2.5 num">{{ $money($gap['decided']) }}</td>
                    <td class="whitespace-nowrap px-5 py-2.5 num">100%</td>
                    <td class="whitespace-nowrap px-5 py-2.5 num">{{ $money($gap['in_force']) }}</td>
                    <td class="whitespace-nowrap px-5 py-2.5 num">{{ $gap['in_force'] > 0 ? '100%' : '—' }}</td>
                    <td class="px-5 py-2.5 text-right text-xs tabular-nums {{ $usedAll !== null && $usedAll > 100.05 ? 'text-rose-700' : '' }}">{{ $usedAll !== null ? number_format($usedAll, 1).'%' : '—' }}</td>
                    <td class="whitespace-nowrap px-5 py-2.5 num {{ $tone($gap['difference']) }}">{{ $signed($gap['difference']) }}</td>
                    <td class="whitespace-nowrap px-5 py-2.5 num">{{ $gap['preparing'] > 0 ? $money($gap['preparing']) : '—' }}</td>
                </tr>
            </x-ui.table>
        </x-ui.card>

        @if(count($units))
            <x-ui.card :title="__('Plan by directorate')" :description="$distributionOn ? __('Where the plan in force sits. The shares of each unit are on the Distribution tab.') : __('Where the plan in force sits. The decision is not divided among the directorates.')" padding="p-0">
                <x-ui.table :headers="[__('Directorate'), __('Approved plan amount'), __('Share of the plan')]" :right="[1, 2]" class="text-sm">
                    @foreach($units as $u)
                        <tr wire:key="bd-unit-{{ $u['code'] }}">
                            <td class="px-5 py-2.5"><span class="font-medium text-zinc-900">{{ $u['code'] }}</span> {{ $u['name'] }}</td>
                            <td class="whitespace-nowrap px-5 py-2.5 num">{{ $money($u['in_force']) }}</td>
                            <td class="whitespace-nowrap px-5 py-2.5 num">{{ number_format($u['share'] * 100, 1) }}%</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        @endif
    @endif

    {{-- what the year's budget execution decree does not let grow: it changes every year, so it is set here --}}
    @if($changeChecks)
        <x-ui.card :title="__('Execution rules of :year', ['year' => $year])" :description="__('The account codes the budget execution decree of the year does not let a revision increase (for 2026, Decreto-Lei n.º 41/2025, art. 4.3: travel, accommodation, per diem and training). A revision that increases them is warned.')" padding="p-5">
            <form wire:submit="saveNoIncrease" class="flex flex-wrap items-end gap-2">
                <div class="min-w-0 flex-1">
                    <label for="bd-no-increase" class="field-label">{{ __('Account codes that may not increase') }}</label>
                    <input id="bd-no-increase" type="text" wire:model="noIncrease" class="flux-input" placeholder="{{ __('For example 2101, 2102') }}" @disabled(! $canRecord)>
                    <p class="mt-1 text-xs text-zinc-500">{{ __('Separate them with commas; a code also covers the codes that start with it.') }}</p>
                </div>
                @if($canRecord)<x-ui.button type="submit" variant="secondary">{{ __('Save') }}</x-ui.button>@endif
            </form>
        </x-ui.card>
    @endif

    @if($history->isNotEmpty())
        <x-ui.card :title="__('Decisions of the year')" :description="__('Each decision keeps the plan as it stood when it was recorded.')" padding="p-0">
            <x-ui.table :headers="[__('Date'), __('Stage of the budget'), __('Reference'), __('Decided'), __('Plan then'), __('Recorded by')]" :right="[3, 4]" class="text-sm">
                @foreach($history as $h)
                    <tr wire:key="bd-h-{{ $h->id }}">
                        <td class="px-5 py-2.5 tabular-nums">{{ $h->decided_on->format('d/m/Y') }}</td>
                        <td class="px-5 py-2.5 font-medium text-zinc-900">{{ $h->stageLabel() }}
                            <span @class(['ml-1 rounded-full px-1.5 py-0.5 text-[11px] font-medium', 'bg-emerald-50 text-emerald-800' => $h->isFinal(), 'bg-zinc-100 text-zinc-600' => ! $h->isFinal()])>{{ $h->isFinal() ? __('Final envelope') : __('Provisional') }}</span></td>
                        <td class="px-5 py-2.5 text-zinc-600">{{ $h->reference ?? '—' }}@if($h->note)<span class="block text-xs text-zinc-500">{{ $h->note }}</span>@endif
                            @if($h->plans_due_on)<span class="block text-xs text-zinc-500">{{ __('Plans to be corrected by :date', ['date' => $h->plans_due_on->format('d/m/Y')]) }}</span>@endif
                            @if($h->committed_amount !== null || $h->new_policy_space !== null)
                                <span class="block text-xs text-zinc-500">
                                    @if($h->committed_amount !== null){{ __('Committed') }}: {{ $money($h->committed_amount) }}@endif
                                    @if($h->committed_amount !== null && $h->new_policy_space !== null) · @endif
                                    @if($h->new_policy_space !== null){{ __('Room for new policies') }}: {{ $money($h->new_policy_space) }}@endif
                                </span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-5 py-2.5 num">{{ $money($h->total) }}</td>
                        <td class="whitespace-nowrap px-5 py-2.5 num">
                            @if($h->plan_baseline_id)
                                <a href="{{ route('reports.approved-plan', ['year' => $year, 'baseline' => $h->plan_baseline_id]) }}" class="text-indigo-700 hover:underline">{{ $money($h->plan_total) }}</a>
                            @else
                                {{ $money($h->plan_total) }}
                            @endif
                        </td>
                        <td class="px-5 py-2.5 text-zinc-600">{{ $h->recorder?->name ?? '—' }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif
    @endif
</div>
