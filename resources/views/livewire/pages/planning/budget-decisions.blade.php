<?php

use App\Models\BudgetDecision;
use App\Models\EconomicClassification;
use App\Services\BudgetDecisions;
use App\Support\PlanOptions;
use Livewire\Volt\Component;

/**
 * The decisions on the institution's budget for the year (budget review committee, State Budget law, supplementary
 * budget) by economic category, and the plan against the latest one. An optional rule. A decision limits nothing:
 * where the plan has to follow it, the units revise their activities. Only an administrator records a decision.
 */
new class extends Component {
    public string $stage = 'committee';
    public string $decidedOn = '';
    public string $reference = '';
    public string $note = '';
    public array $amounts = [];
    public bool $showForm = false;

    public function mount(): void
    {
        abort_unless(PlanOptions::current()->budgetDecisions, 404);
        abort_unless(auth()->user()->isAdministrator() || auth()->user()->can('view_all_paa'), 403);
        $this->decidedOn = now()->toDateString();
    }

    /** Open the form with the amounts of the latest decision, which usually change only in some categories. */
    public function openForm(): void
    {
        abort_unless(app(BudgetDecisions::class)->canRecord(auth()->user()), 403);
        $latest = app(BudgetDecisions::class)->latest(\App\Support\FiscalYear::current());
        $this->amounts = array_map(fn ($v) => (string) $v, $latest?->amounts ?? []);
        $this->showForm = true;
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
        ]);
        try {
            $decisions->record(auth()->user(), \App\Support\FiscalYear::current(), $this->stage, $this->decidedOn, $this->amounts, $this->reference, $this->note);
        } catch (\DomainException $e) {
            $this->addError('amounts', $e->getMessage());

            return;
        }
        $this->reset('reference', 'note', 'amounts', 'showForm');
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
        ];
    }
}; ?>

@php
    $money = fn ($v) => '$ '.number_format((float) $v, 0);
    $signed = fn ($v) => ($v > 0.5 ? '+' : ($v < -0.5 ? '−' : '')).'$ '.number_format(abs((float) $v), 0);
    $tone = fn ($v) => $v > 0.5 ? 'text-rose-700' : ($v < -0.5 ? 'text-emerald-700' : 'text-zinc-700');
@endphp

<div class="space-y-6">
    <x-ui.page-header :title="__('Budget decisions')" :subtitle="__('What was decided about the budget for :year, by category, and the plan against it. A decision limits nothing: where the plan has to follow it, the units revise their activities.', ['year' => $year])">
        @if($gap)
            <x-ui.button variant="secondary" size="md" shadow="none" href="{{ route('planning.adjustment') }}" wire:navigate>{{ __('Adjustment round') }}</x-ui.button>
        @endif
        @if($canRecord && ! $showForm)
            <x-ui.button size="md" wire:click="openForm">{{ __('Record a decision') }}</x-ui.button>
        @endif
    </x-ui.page-header>

    @if($showForm)
        <x-ui.card :title="__('Record a decision')" :description="__('The plan in force at this moment is kept with the decision, so that it can be printed later as it stood.')" padding="p-5">
            <form wire:submit="record" class="space-y-4">
                <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <label for="bd-stage" class="field-label">{{ __('Decided by') }}</label>
                        <select id="bd-stage" wire:model="stage" class="flux-input">
                            @foreach(BudgetDecision::STAGES as $key => $label)
                                <option value="{{ $key }}">{{ __($label) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="bd-date" class="field-label">{{ __('Date of the decision') }}</label>
                        <input id="bd-date" type="date" wire:model="decidedOn" class="flux-input">
                        @error('decidedOn')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="bd-ref" class="field-label">{{ __('Reference') }}</label>
                        <input id="bd-ref" type="text" wire:model="reference" class="flux-input" placeholder="{{ __('For example the number of the law or of the minutes') }}">
                    </div>
                </div>
                <div>
                    <p class="field-label">{{ __('Amount decided by category') }}</p>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach($classes as $c)
                            <div wire:key="bd-amt-{{ $c->id }}">
                                <label for="bd-amt-{{ $c->id }}" class="text-xs text-zinc-600">{{ $c->code }} {{ $c->name }}</label>
                                <input id="bd-amt-{{ $c->id }}" type="number" min="0" step="0.01" wire:model="amounts.{{ $c->id }}" class="flux-input tabular-nums">
                            </div>
                        @endforeach
                    </div>
                    @error('amounts')<p class="field-error">{{ $message }}</p>@enderror
                    @error('amounts.*')<p class="field-error">{{ $message }}</p>@enderror
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

    @if(! $gap)
        <x-ui.card padding="p-0"><x-ui.empty-state :title="__('No budget decision for :year yet', ['year' => $year])" :description="__('When the budget review committee, the State Budget law or a supplementary budget sets the amount for the institution, record it here to compare it with the plan.')" /></x-ui.card>
    @else
        @php $d = $gap['decision']; @endphp
        <div class="grid gap-3 sm:grid-cols-4">
            <x-ui.card padding="p-4"><p class="text-xs text-zinc-500">{{ __('Decided') }}</p><p class="mt-1 text-xl font-semibold tabular-nums text-zinc-900">{{ $money($gap['decided']) }}</p><p class="text-xs text-zinc-500">{{ $d->stageLabel() }} · {{ $d->decided_on->format('d/m/Y') }}</p></x-ui.card>
            <x-ui.card padding="p-4"><p class="text-xs text-zinc-500">{{ __('Approved plan amount') }}</p><p class="mt-1 text-xl font-semibold tabular-nums text-zinc-900">{{ $money($gap['in_force']) }}</p></x-ui.card>
            <x-ui.card padding="p-4"><p class="text-xs text-zinc-500">{{ __('Plan against the decision') }}</p><p class="mt-1 text-xl font-semibold tabular-nums {{ $tone($gap['difference']) }}">{{ $signed($gap['difference']) }}</p>
                <p class="text-xs text-zinc-500">{{ $gap['difference'] > 0.5 ? __('The plan is above the decision') : ($gap['difference'] < -0.5 ? __('The plan is below the decision') : __('The plan matches the decision')) }}</p></x-ui.card>
            <x-ui.card padding="p-4"><p class="text-xs text-zinc-500">{{ __('Still being prepared') }}</p><p class="mt-1 text-xl font-semibold tabular-nums text-zinc-900">{{ $money($gap['preparing']) }}</p><p class="text-xs text-zinc-500">{{ __('Not approved yet, not in the plan amount') }}</p></x-ui.card>
        </div>

        <x-ui.card :title="__('By category')" :description="__('A positive difference is what the plan has above the decision in that category: the activities concerned are revised to reduce it.')" padding="p-0">
            <x-ui.table :headers="[__('Category'), __('Decided'), __('Approved plan amount'), __('Difference'), __('Still being prepared')]" :right="[1, 2, 3, 4]" class="text-sm">
                @foreach($gap['rows'] as $r)
                    <tr wire:key="bd-row-{{ $r['id'] }}">
                        <td class="px-5 py-2.5 font-medium text-zinc-900">{{ $r['code'] }} {{ $r['name'] }}</td>
                        <td class="px-5 py-2.5 num">{{ $money($r['decided']) }}</td>
                        <td class="px-5 py-2.5 num">{{ $money($r['in_force']) }}</td>
                        <td class="px-5 py-2.5 num font-medium {{ $tone($r['difference']) }}">{{ $signed($r['difference']) }}</td>
                        <td class="px-5 py-2.5 num text-zinc-500">{{ $r['preparing'] > 0 ? $money($r['preparing']) : '—' }}</td>
                    </tr>
                @endforeach
                <tr class="bg-zinc-50 font-semibold">
                    <td class="px-5 py-2.5">{{ __('Total') }}</td>
                    <td class="px-5 py-2.5 num">{{ $money($gap['decided']) }}</td>
                    <td class="px-5 py-2.5 num">{{ $money($gap['in_force']) }}</td>
                    <td class="px-5 py-2.5 num {{ $tone($gap['difference']) }}">{{ $signed($gap['difference']) }}</td>
                    <td class="px-5 py-2.5 num">{{ $gap['preparing'] > 0 ? $money($gap['preparing']) : '—' }}</td>
                </tr>
            </x-ui.table>
        </x-ui.card>

        @if(count($units))
            <x-ui.card :title="__('Plan by directorate')" :description="__('Where the plan in force sits. The decision is not divided among the directorates.')" padding="p-0">
                <x-ui.table :headers="[__('Directorate'), __('Approved plan amount'), __('Share of the plan')]" :right="[1, 2]" class="text-sm">
                    @foreach($units as $u)
                        <tr wire:key="bd-unit-{{ $u['code'] }}">
                            <td class="px-5 py-2.5"><span class="font-medium text-zinc-900">{{ $u['code'] }}</span> {{ $u['name'] }}</td>
                            <td class="px-5 py-2.5 num">{{ $money($u['in_force']) }}</td>
                            <td class="px-5 py-2.5 num">{{ number_format($u['share'] * 100, 1) }}%</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        @endif
    @endif

    @if($history->isNotEmpty())
        <x-ui.card :title="__('Decisions of the year')" :description="__('Each decision keeps the plan as it stood when it was recorded.')" padding="p-0">
            <x-ui.table :headers="[__('Date'), __('Decided by'), __('Reference'), __('Decided'), __('Plan then'), __('Recorded by')]" :right="[3, 4]" class="text-sm">
                @foreach($history as $h)
                    <tr wire:key="bd-h-{{ $h->id }}">
                        <td class="px-5 py-2.5 tabular-nums">{{ $h->decided_on->format('d/m/Y') }}</td>
                        <td class="px-5 py-2.5 font-medium text-zinc-900">{{ $h->stageLabel() }}</td>
                        <td class="px-5 py-2.5 text-zinc-600">{{ $h->reference ?? '—' }}@if($h->note)<span class="block text-xs text-zinc-500">{{ $h->note }}</span>@endif</td>
                        <td class="px-5 py-2.5 num">{{ $money($h->total) }}</td>
                        <td class="px-5 py-2.5 num">
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
</div>
