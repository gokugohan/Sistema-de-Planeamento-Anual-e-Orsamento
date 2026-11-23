<?php

use App\Models\BudgetDistribution;
use App\Services\BudgetDecisions;
use App\Services\BudgetDistributions;
use App\Support\PlanOptions;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

/**
 * The distribution of the budget among the institution's top-level units, by category (an optional rule, on the
 * Budget decisions page). The budget unit prepares the shares of the latest decision, in amounts or in percentages of
 * each category, and submits them; the head of the institution approves them with the reference of the act, or
 * returns them with a reason. What is not given to a unit stays with the institution. Every action is checked again
 * in App\Services\BudgetDistributions.
 */
new class extends Component {
    public int $year;

    /** @var array<int|string, array<int|string, string>> unit id => classification id => amount, while preparing */
    public array $amounts = [];

    /** @var array<int|string, array<int|string, string>> unit id => classification id => percentage of the category, when typed in percentages */
    public array $percents = [];

    /** @var array<int|string, string> unit id => one percentage for every category of the unit */
    public array $rowPercents = [];

    /** How the shares are typed: 'amount' or 'percent'. */
    public string $entry = 'amount';

    /** The amounts and percentages when percentages were chosen: a percentage left as it was keeps its exact amount. */
    #[Locked]
    public array $switched = [];

    public string $note = '';
    public bool $editing = false;
    public string $method = 'plan';
    public string $returnReason = '';
    public string $actReference = '';
    public string $actDate = '';

    public function mount(): void
    {
        abort_unless(BudgetDistributions::on(), 404);
        $this->actDate = now()->toDateString();
        $open = app(BudgetDistributions::class)->openFor($this->year);
        if ($open && $open->status === BudgetDistribution::DRAFT && app(BudgetDistributions::class)->canPrepare(auth()->user())) {
            $this->load($open);
            $this->editing = true;
        }
    }

    private function load(?BudgetDistribution $d): void
    {
        $this->amounts = [];
        foreach ($d?->matrix() ?? [] as $unit => $byClass) {
            if ($unit === 0) {
                continue; // the reserve is what is left, never typed
            }
            foreach ($byClass as $class => $amount) {
                $this->amounts[$unit][$class] = (string) round($amount, 2);
            }
        }
        $this->note = (string) ($d?->note ?? '');
    }

    /** @param array<int, array<int, float>> $amounts */
    private function setAmounts(array $amounts): void
    {
        $this->amounts = array_map(fn ($byClass) => array_map(fn ($v) => (string) $v, $byClass), $amounts);
        if ($this->entry === 'percent') {
            $this->takePercents();
        }
    }

    /**
     * Start preparing. When the distribution in force shares the latest decision, from its amounts; when a new decision
     * came since, from the same percentages applied to it; without one, from the plan.
     */
    public function start(BudgetDistributions $service): void
    {
        abort_unless($service->canPrepare(auth()->user()), 403);
        $inForce = $service->inForce($this->year);
        if ($inForce && ! $service->isCurrent($this->year)) {
            $this->method = 'same_percent';
            $this->setAmounts($service->suggest($this->year, 'same_percent'));
            $this->note = '';
            $this->dispatch('toast', message: __('Each unit keeps the percentage it had in each category, applied to the new decision.'), type: 'info');
        } elseif ($inForce) {
            $this->load($inForce);
            $this->note = '';
        } else {
            $this->setAmounts($service->suggest($this->year, 'plan'));
        }
        $this->editing = true;
    }

    public function suggest(BudgetDistributions $service): void
    {
        abort_unless($service->canPrepare(auth()->user()), 403);
        $this->validate(['method' => 'in:'.implode(',', array_keys(BudgetDistributions::SUGGESTIONS))]);
        $this->setAmounts($service->suggest($this->year, $this->method));
        $this->dispatch('toast', message: __('Shares filled in. Change any of them, then save.'), type: 'info');
    }

    /** Switching between amounts and percentages converts what was typed. */
    public function updatedEntry(string $value): void
    {
        if (! in_array($value, ['amount', 'percent'], true)) {
            $this->entry = 'amount';

            return;
        }
        if ($value === 'percent') {
            $this->takePercents();
        } else {
            $this->syncAmounts();
        }
    }

    /** The percentages of the amounts typed so far. */
    private function takePercents(): void
    {
        $decision = app(BudgetDecisions::class)->latest($this->year);
        $this->percents = array_map(fn ($byClass) => array_map(fn ($v) => (string) $v, $byClass),
            app(BudgetDistributions::class)->toPercents($decision, $this->amounts));
        $this->switched = ['amounts' => $this->amounts, 'percents' => $this->percents];
    }

    /** The amounts of the percentages typed (a percentage not changed keeps the amount it came from). */
    private function syncAmounts(): void
    {
        $this->validate(['percents' => 'array', 'percents.*' => 'array', 'percents.*.*' => 'nullable|numeric|min:0|max:100']);
        if (! $decision = app(BudgetDecisions::class)->latest($this->year)) {
            return;
        }
        $amounts = app(BudgetDistributions::class)->fromPercents($decision, $this->percents);
        foreach ($this->percents as $unit => $byClass) {
            foreach ($byClass as $class => $pct) {
                $before = $this->switched['percents'][$unit][$class] ?? null;
                if ($before !== null && is_numeric($pct) && abs((float) $pct - (float) $before) < 0.00001 && isset($this->switched['amounts'][$unit][$class])) {
                    $amounts[$unit][$class] = (float) $this->switched['amounts'][$unit][$class];
                }
            }
        }
        $this->amounts = array_map(fn ($byClass) => array_map(fn ($v) => (string) $v, $byClass), $amounts);
        $this->switched = ['amounts' => $this->amounts, 'percents' => $this->percents];
    }

    /** One percentage typed for a unit gives it that percentage of every category. */
    public function updatedRowPercents(mixed $value, string $unit): void
    {
        unset($this->rowPercents[$unit]);
        if ($value === null || $value === '') {
            return;
        }
        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
            $this->addError('amounts', __('A percentage is between 0 and 100.'));

            return;
        }
        $service = app(BudgetDistributions::class);
        abort_unless($service->canPrepare(auth()->user()) && $service->units()->contains('id', (int) $unit), 403);
        $decision = app(BudgetDecisions::class)->latest($this->year);
        foreach ($service->sharedClassIds() as $class) {
            $decided = (float) ($decision?->amountFor($class) ?? 0);
            if ($decided <= 0) {
                continue;
            }
            if ($this->entry === 'percent') {
                $this->percents[$unit][$class] = (string) round((float) $value, 4);
            } else {
                $this->amounts[$unit][$class] = (string) round($decided * (float) $value / 100, 2);
            }
        }
    }

    public function save(BudgetDistributions $service): ?BudgetDistribution
    {
        abort_unless($service->canPrepare(auth()->user()), 403);
        if ($this->entry === 'percent') {
            $this->syncAmounts();
        }
        $this->validate(['amounts' => 'array', 'amounts.*' => 'array', 'amounts.*.*' => 'nullable|numeric|min:0', 'note' => 'nullable|string|max:2000']);
        try {
            $d = $service->save(auth()->user(), $this->year, $this->amounts, $this->note);
        } catch (\DomainException $e) {
            $this->addError('amounts', $e->getMessage());

            return null;
        }
        $this->dispatch('toast', message: __('Distribution saved as a draft.'), type: 'success');

        return $d;
    }

    public function submit(BudgetDistributions $service): void
    {
        if (! $d = $this->save($service)) {
            return;
        }
        try {
            $service->submit(auth()->user(), $d);
        } catch (\DomainException $e) {
            $this->addError('amounts', $e->getMessage());

            return;
        }
        $this->editing = false;
        $this->dispatch('toast', message: __('Distribution sent for approval.'), type: 'success');
    }

    public function approve(BudgetDistributions $service, int $id): void
    {
        $d = BudgetDistribution::where('fiscal_year', $this->year)->findOrFail($id);
        abort_unless($service->canApprove(auth()->user(), $d), 403);
        $this->validate(['actReference' => 'required|string|min:3|max:255', 'actDate' => 'required|date']);
        try {
            $service->approve(auth()->user(), $d, $this->actReference, $this->actDate);
        } catch (\DomainException $e) {
            $this->addError('actReference', $e->getMessage());

            return;
        }
        $this->reset('actReference');
        $this->dispatch('toast', message: __('Distribution approved: it is in force and the units have been told their share.'), type: 'success');
    }

    public function sendBack(BudgetDistributions $service, int $id): void
    {
        $d = BudgetDistribution::where('fiscal_year', $this->year)->findOrFail($id);
        abort_unless($service->canApprove(auth()->user(), $d), 403);
        try {
            $service->sendBack(auth()->user(), $d, $this->returnReason);
        } catch (\DomainException $e) {
            $this->addError('returnReason', $e->getMessage());

            return;
        }
        $this->reset('returnReason');
        $this->dispatch('toast', message: __('Distribution returned to the budget unit.'), type: 'success');
    }

    public function with(BudgetDistributions $service, BudgetDecisions $decisions): array
    {
        $user = auth()->user();
        $open = $service->openFor($this->year);
        $inForce = $service->inForce($this->year);
        $shared = $service->sharedClassIds();
        $options = PlanOptions::current();
        $decision = $decisions->latest($this->year);
        $plan = $service->plan($this->year);
        $units = $service->units();
        $shown = $open ?? $inForce;

        // the figures of the dashboard: what the decision has in each shared category, and the plan of the units in it
        $decided = [];
        $planByClass = [];
        foreach ($shared as $id) {
            $decided[$id] = (float) ($decision?->amountFor($id) ?? 0);
            $planByClass[$id] = $units->sum(fn ($u) => ($plan[$u->id][$id]['in_force'] ?? 0) + ($plan[$u->id][$id]['preparing'] ?? 0));
        }
        $matrix = $shown?->matrix() ?? [];
        $given = $units->sum(fn ($u) => array_sum(array_intersect_key($matrix[$u->id] ?? [], array_flip($shared))));
        // what the shown distribution shares: its own decision, which may be older than the latest
        $givenOf = $shown ? array_sum(array_map(fn ($id) => (float) ($shown->decision?->amountFor($id) ?? 0), $shared)) : 0.0;

        return [
            'decision' => $decision,
            'open' => $open,
            'inForce' => $inForce,
            'current' => $service->isCurrent($this->year),
            'shown' => $shown,
            'units' => $units,
            'ownTitle' => $service->ownTitleUnits(),
            'classes' => $service->classes()->filter(fn ($c) => in_array((int) $c->id, $shared, true))->values(),
            'salaryClass' => PlanOptions::SALARIES_CENTRAL ? $service->classes()->firstWhere('id', $service->salaryClassId()) : null,
            'plan' => $plan,
            'decided' => $decided,
            'planByClass' => $planByClass,
            'toShare' => array_sum($decided),
            'planShared' => array_sum($planByClass),
            'given' => $shown ? $given : null,
            'givenOf' => $givenOf,
            'problems' => $open ? $service->problems($open) : [],
            'canPrepare' => $service->canPrepare($user),
            'canApprove' => $open ? $service->canApprove($user, $open) : false,
            'budgetUnit' => $service->budgetUnit(),
            'approverNames' => $service->approvers()->pluck('name')->all(),
            'byDelegation' => $options->distributionApprover === PlanOptions::APPROVER_BUDGET_UNIT_HEAD,
            'mode' => $options->ceilingMode,
            'history' => $service->history($this->year),
        ];
    }
}; ?>

@php
    $money = fn ($v) => ($v < -0.5 ? '−' : '').'$ '.number_format(abs((float) $v), 0);
    $pct = fn ($part, $whole) => $whole > 0 ? number_format(100 * $part / $whole, 1).'%' : '—';
    // number helpers for the figures that follow what is typed (each cell keeps its own Alpine scope: Livewire re-renders the table)
    $js = "m(x) { return (x < -0.5 ? '−$ ' : '$ ') + new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(Math.abs(x)) }, p(x) { return new Intl.NumberFormat('en-US', { maximumFractionDigits: 1 }).format(x) + '%' }, n(v) { return parseFloat(v) || 0 }";
    $byPercent = $entry === 'percent';
    $source = $byPercent ? 'percents' : 'amounts';
@endphp

<div class="space-y-6">
    @if(! $decision)
        <x-ui.card padding="p-0"><x-ui.empty-state :title="__('No budget decision for :year yet', ['year' => $year])" :text="__('Record the budget ceiling (or another decision) on the Decisions tab first: a distribution shares the latest decision among the units.')" /></x-ui.card>
    @else
        {{-- the dashboard: what there is to share, what the units plan, what is given --}}
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.card padding="p-4">
                <p class="text-xs text-zinc-500">{{ __('Decided') }}</p>
                <p class="mt-1 text-xl font-semibold tabular-nums text-zinc-900">{{ $money($decision->total) }}</p>
                <p class="text-xs text-zinc-500">{{ $decision->stageLabel() }} · {{ $decision->decided_on->format('d/m/Y') }}</p>
            </x-ui.card>
            <x-ui.card padding="p-4">
                <p class="text-xs text-zinc-500">{{ __('To share among the units') }}</p>
                <p class="mt-1 text-xl font-semibold tabular-nums text-zinc-900">{{ $money($toShare) }}</p>
                <p class="text-xs text-zinc-500">{{ __(':pct of the decision', ['pct' => $pct($toShare, (float) $decision->total)]) }}@if($salaryClass) · {{ __(':category stays with the institution', ['category' => $salaryClass->code]) }}@endif</p>
            </x-ui.card>
            <x-ui.card padding="p-4">
                <p class="text-xs text-zinc-500">{{ __('Plan of the units') }}</p>
                <p @class(['mt-1 text-xl font-semibold tabular-nums', 'text-rose-700' => $planShared > $toShare + 0.5, 'text-zinc-900' => $planShared <= $toShare + 0.5])>{{ $money($planShared) }}</p>
                <p class="text-xs text-zinc-500">{{ __(':pct of what there is to share', ['pct' => $pct($planShared, $toShare)]) }}</p>
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-zinc-100" aria-hidden="true">
                    <div @class(['h-full rounded-full', 'bg-rose-500' => $planShared > $toShare + 0.5, 'bg-indigo-500' => $planShared <= $toShare + 0.5]) style="width: {{ $toShare > 0 ? min(100, round(100 * $planShared / $toShare, 1)) : 0 }}%"></div>
                </div>
            </x-ui.card>
            <x-ui.card padding="p-4">
                <p class="text-xs text-zinc-500">{{ __('Given to the units') }}</p>
                @if($given !== null)
                    <p class="mt-1 text-xl font-semibold tabular-nums text-zinc-900">{{ $money($given) }}</p>
                    <p class="text-xs text-zinc-500">{{ __(':pct given; :amount kept as a reserve', ['pct' => $pct($given, $givenOf), 'amount' => $money($givenOf - $given)]) }}</p>
                    <p class="text-xs text-zinc-500">{{ __('Distribution :n', ['n' => $shown->version]) }} · {{ $shown->statusWord() }}@if($shown->decision && $shown->budget_decision_id !== $decision->id) · {{ $shown->decision->stageLabel() }}@endif</p>
                @else
                    <p class="mt-1 text-xl font-semibold text-zinc-400">—</p>
                    <p class="text-xs text-zinc-500">{{ __('Not distributed yet') }}</p>
                @endif
            </x-ui.card>
        </div>

        {{-- who prepares and who approves, so nobody has to guess --}}
        <x-ui.card padding="p-4">
            <div class="grid gap-3 text-sm sm:grid-cols-3">
                <div><p class="text-xs text-zinc-500">{{ __('Decision being shared') }}</p>
                    <p class="font-medium text-zinc-900">{{ $decision->stageLabel() }} · {{ $decision->decided_on->format('d/m/Y') }}</p>
                    <p class="tabular-nums text-zinc-700">{{ $money($decision->total) }}</p></div>
                <div><p class="text-xs text-zinc-500">{{ __('Prepared by') }}</p>
                    <p class="font-medium text-zinc-900">{{ $budgetUnit ? $budgetUnit->code.' '.$budgetUnit->name : __('An administrator (no budget unit named in the optional rules)') }}</p></div>
                <div><p class="text-xs text-zinc-500">{{ __('Approved by') }}</p>
                    <p class="font-medium text-zinc-900">{{ $byDelegation ? __('The head of the budget unit (by delegation)') : __('The head of the institution') }}</p>
                    <p class="text-zinc-700">{{ $approverNames ? implode(', ', $approverNames) : __('Nobody has this role yet: an administrator approves on their behalf.') }}</p></div>
            </div>
        </x-ui.card>

        @if($inForce && ! $current)
            <div role="status" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-900">
                {{ __('A newer budget decision was recorded (:stage, :date). The distribution in force still shares the earlier one: prepare a new distribution.', ['stage' => $decision->stageLabel(), 'date' => $decision->decided_on->format('d/m/Y')]) }}
            </div>
        @endif

        @if($open && $open->return_note && $open->status === BudgetDistribution::DRAFT)
            <div class="rounded-lg bg-rose-50 px-4 py-2.5 text-sm text-rose-900"><span class="font-medium">{{ __('Returned') }}:</span> {{ $open->return_note }}</div>
        @endif

        @if($editing && $canPrepare && (! $open || $open->status === BudgetDistribution::DRAFT))
            {{-- preparing: one amount (or percentage of the category) per unit and category; the reserve is what the decision leaves --}}
            <x-ui.card :title="__('Prepare the distribution')" :description="__('Give each unit its share of each category, in dollars or as a percentage of what the decision has in the category. What is not given stays with the institution as a reserve. Save as often as you like; submit when it is complete.')" padding="p-0">
                <div class="flex flex-wrap items-end justify-between gap-3 border-b border-zinc-100 px-5 py-3">
                    <div class="flex flex-wrap items-end gap-2">
                        <div>
                            <label for="dist-method" class="field-label">{{ __('Fill in the shares') }}</label>
                            <select id="dist-method" wire:model="method" class="flux-input !w-auto">
                                @foreach(BudgetDistributions::SUGGESTIONS as $key => $label)
                                    @if(! in_array($key, ['in_force', 'same_percent'], true) || $inForce)<option value="{{ $key }}">{{ __($label) }}</option>@endif
                                @endforeach
                            </select>
                        </div>
                        <x-ui.button variant="secondary" size="md" shadow="none" wire:click="suggest" wire:confirm="{{ __('Replace the shares typed so far?') }}">{{ __('Fill in') }}</x-ui.button>
                    </div>
                    <div>
                        <p class="field-label">{{ __('Type the shares in') }}</p>
                        <div class="inline-flex rounded-lg border border-zinc-200 p-0.5" role="radiogroup" aria-label="{{ __('Type the shares in') }}">
                            @foreach(['amount' => __('Dollars'), 'percent' => __('Percentages')] as $key => $label)
                                <button type="button" role="radio" aria-checked="{{ $entry === $key ? 'true' : 'false' }}" wire:click="$set('entry', '{{ $key }}')"
                                    @class(['rounded-md px-3 py-1.5 text-sm font-medium', 'bg-indigo-600 text-white' => $entry === $key, 'text-zinc-700 hover:bg-zinc-50' => $entry !== $key])>{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-zinc-50 text-xs text-zinc-600">
                            <tr>
                                <th scope="col" class="px-5 py-2 text-left font-medium">{{ __('Unit') }}</th>
                                @foreach($classes as $c)<th scope="col" class="px-3 py-2 text-right font-medium">{{ $c->code }} {{ $c->name }}</th>@endforeach
                                <th scope="col" class="px-3 py-2 text-right font-medium">{{ __('Total') }}</th>
                                <th scope="col" class="px-5 py-2 text-right font-medium">{{ __('Same % in every category') }}</th>
                            </tr>
                            <tr class="text-zinc-800">
                                <th scope="row" class="px-5 py-1.5 text-left font-semibold">{{ __('Decided') }}</th>
                                @foreach($classes as $c)
                                    <td class="whitespace-nowrap px-3 py-1.5 text-right font-semibold tabular-nums">{{ $money($decided[$c->id]) }}
                                        <span class="block text-xs font-normal text-zinc-500">{{ __(':pct of the decision', ['pct' => $pct($decided[$c->id], (float) $decision->total)]) }}</span></td>
                                @endforeach
                                <td class="whitespace-nowrap px-3 py-1.5 text-right font-semibold tabular-nums">{{ $money($toShare) }}
                                    <span class="block text-xs font-normal text-zinc-500">{{ __(':pct of the decision', ['pct' => $pct($toShare, (float) $decision->total)]) }}</span></td>
                                <td></td>
                            </tr>
                            <tr class="text-zinc-700">
                                <th scope="row" class="px-5 py-1.5 text-left font-medium">{{ __('Plan of the units') }}</th>
                                @foreach($classes as $c)
                                    <td class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums">{{ $money($planByClass[$c->id]) }}
                                        <span @class(['block text-xs', 'font-medium text-rose-700' => $planByClass[$c->id] > $decided[$c->id] + 0.5, 'text-zinc-500' => $planByClass[$c->id] <= $decided[$c->id] + 0.5])>{{ $decided[$c->id] > 0 ? __(':pct of the decided', ['pct' => $pct($planByClass[$c->id], $decided[$c->id])]) : '—' }}</span></td>
                                @endforeach
                                <td class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums">{{ $money($planShared) }}
                                    <span class="block text-xs text-zinc-500">{{ __(':pct of the decided', ['pct' => $pct($planShared, $toShare)]) }}</span></td>
                                <td></td>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100">
                            @foreach($units as $u)
                                <tr wire:key="dist-u-{{ $u->id }}-{{ $entry }}">
                                    <th scope="row" class="px-5 py-2 text-left font-normal"><span class="font-medium text-zinc-900">{{ $u->code }}</span> <span class="hidden text-zinc-600 lg:inline">{{ \Illuminate\Support\Str::limit($u->name, 48) }}</span></th>
                                    @foreach($classes as $c)
                                        @php $p = ($plan[$u->id][$c->id]['in_force'] ?? 0) + ($plan[$u->id][$c->id]['preparing'] ?? 0); $dec = $decided[$c->id]; @endphp
                                        <td class="px-3 py-2 text-right align-top"
                                            x-data="{ {!! $js !!}, d: {{ $dec }}, get v() { return this.n((($wire.{{ $source }} || {})[{{ $u->id }}] || {})[{{ $c->id }}]) } }">
                                            @if($byPercent)
                                                <div class="relative ml-auto w-28">
                                                    <input type="number" min="0" max="100" step="0.01" wire:model="percents.{{ $u->id }}.{{ $c->id }}" @disabled($dec <= 0)
                                                           class="flux-input w-28 pr-7 text-right tabular-nums"
                                                           aria-label="{{ __('Percentage of :category for :unit', ['unit' => $u->code, 'category' => $c->code.' '.$c->name]) }}">
                                                    <span class="pointer-events-none absolute inset-y-0 right-2.5 flex items-center text-xs text-zinc-500">%</span>
                                                </div>
                                                <span class="mt-0.5 block text-xs font-medium tabular-nums text-zinc-800" x-text="m(v * d / 100)"></span>
                                            @else
                                                <input type="number" min="0" step="1" wire:model="amounts.{{ $u->id }}.{{ $c->id }}" class="flux-input w-32 text-right tabular-nums"
                                                       aria-label="{{ __('Share of :unit in :category', ['unit' => $u->code, 'category' => $c->code.' '.$c->name]) }}">
                                                <span class="mt-0.5 block text-xs font-medium tabular-nums text-zinc-800" x-text="d > 0 ? p(100 * v / d) : '—'"></span>
                                            @endif
                                            <span @class(['block text-xs tabular-nums', 'text-zinc-500'])>{{ __('plan') }} {{ $money($p) }}</span>
                                        </td>
                                    @endforeach
                                    <td class="whitespace-nowrap px-3 py-2 text-right align-top font-medium tabular-nums"
                                        x-data="{ {!! $js !!}, ds: @js((object) $decided), get s() { const r = ($wire.{{ $source }} || {})[{{ $u->id }}] || {}; let s = 0; for (const c in this.ds) { const x = this.n(r[c]); s += {{ $byPercent ? 'x * this.ds[c] / 100' : 'x' }} } return s } }">
                                        <span x-text="m(s)"></span>
                                        <span class="block text-xs font-normal text-zinc-500" x-text="{{ $toShare > 0 ? 'p(100 * s / '.$toShare.')' : "'—'" }}"></span>
                                    </td>
                                    <td class="px-5 py-2 text-right align-top">
                                        <div class="relative ml-auto w-24">
                                            <input type="number" min="0" max="100" step="0.01" wire:model.blur="rowPercents.{{ $u->id }}" placeholder="—"
                                                   class="flux-input w-24 pr-7 text-right tabular-nums"
                                                   aria-label="{{ __('One percentage of every category for :unit', ['unit' => $u->code]) }}">
                                            <span class="pointer-events-none absolute inset-y-0 right-2.5 flex items-center text-xs text-zinc-500">%</span>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                            <tr class="bg-zinc-50">
                                <th scope="row" class="px-5 py-2 text-left font-semibold text-zinc-900">{{ __('Kept by the institution') }}</th>
                                @foreach($classes as $c)
                                    <td wire:key="dist-r-{{ $c->id }}-{{ $entry }}" class="whitespace-nowrap px-3 py-2 text-right font-semibold tabular-nums"
                                        x-data="{ {!! $js !!}, d: {{ $decided[$c->id] }}, get r() { let s = 0; const a = $wire.{{ $source }} || {}; for (const u in a) { const x = this.n((a[u] || {})[{{ $c->id }}]); s += {{ $byPercent ? 'x * this.d / 100' : 'x' }} } return this.d - s } }"
                                        :class="r < -0.005 ? 'text-rose-700' : 'text-zinc-900'">
                                        <span x-text="m(r)"></span>
                                        <span class="block text-xs font-normal" x-text="d > 0 ? p(100 * r / d) : '—'"></span>
                                    </td>
                                @endforeach
                                <td wire:key="dist-rt-{{ $entry }}" class="whitespace-nowrap px-3 py-2 text-right font-semibold tabular-nums"
                                    x-data="{ {!! $js !!}, ds: @js((object) $decided), get r() { let s = 0; const a = $wire.{{ $source }} || {}; for (const u in a) { for (const c in this.ds) { const x = this.n((a[u] || {})[c]); s += {{ $byPercent ? 'x * this.ds[c] / 100' : 'x' }} } } return {{ $toShare }} - s } }"
                                    :class="r < -0.005 ? 'text-rose-700' : 'text-zinc-900'">
                                    <span x-text="m(r)"></span>
                                    <span class="block text-xs font-normal" x-text="{{ $toShare > 0 ? 'p(100 * r / '.$toShare.')' : "'—'" }}"></span>
                                </td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="space-y-3 border-t border-zinc-100 px-5 py-4">
                    <p class="text-xs text-zinc-600">{{ $byPercent
                        ? __('Each percentage is of what the decision has in the category; the dollars follow. A percentage you do not change keeps its exact amount.')
                        : __('Under each amount, its percentage of what the decision has in the category.') }}
                        {{ __('Type one percentage in the last column to give a unit that percentage of every category.') }}</p>
                    @if($salaryClass)
                        <p class="text-xs text-zinc-600">{{ __(':category (:amount) stays with the institution: it is not shared among the units.', ['category' => $salaryClass->code.' '.$salaryClass->name, 'amount' => $money($decision->amountFor($salaryClass->id))]) }}</p>
                    @endif
                    <div>
                        <label for="dist-note" class="field-label">{{ __('Note (optional)') }}</label>
                        <textarea id="dist-note" rows="2" wire:model="note" class="flux-input" placeholder="{{ __('For example the criteria used to share the budget') }}"></textarea>
                    </div>
                    @error('amounts')<p class="field-error">{{ $message }}</p>@enderror
                    @error('amounts.*.*')<p class="field-error">{{ $message }}</p>@enderror
                    @error('percents.*.*')<p class="field-error">{{ __('A percentage is between 0 and 100.') }}</p>@enderror
                    <div class="flex flex-wrap justify-end gap-2">
                        <x-ui.button variant="secondary" wire:click="save" wire:loading.attr="disabled">{{ __('Save draft') }}</x-ui.button>
                        <x-ui.button wire:click="submit" wire:loading.attr="disabled" wire:confirm="{{ __('Send the distribution for approval? It cannot be changed while it waits, unless it is returned.') }}">{{ __('Submit for approval') }}</x-ui.button>
                    </div>
                </div>
            </x-ui.card>
        @elseif($shown)
            @php $m = $shown->matrix(); @endphp
            <x-ui.card padding="p-0">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 px-5 py-3">
                    <div>
                        <p class="text-sm font-semibold text-zinc-900">{{ __('Distribution :n', ['n' => $shown->version]) }}
                            <span @class(['ml-1 rounded-full px-2 py-0.5 text-xs font-medium',
                                'bg-emerald-50 text-emerald-800' => $shown->status === BudgetDistribution::APPROVED,
                                'bg-amber-50 text-amber-800' => $shown->status === BudgetDistribution::SUBMITTED,
                                'bg-zinc-100 text-zinc-700' => $shown->status === BudgetDistribution::DRAFT])>{{ $shown->statusWord() }}</span>
                            @if($shown->decision && ! $shown->decision->isFinal())
                                <span class="ml-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800" title="{{ __('It shares a provisional amount: the distribution of the State Budget law replaces it.') }}">{{ __('Provisional') }}</span>
                            @endif</p>
                        <p class="text-xs text-zinc-600">{{ $shown->decision?->stageLabel() }}
                            @if($shown->act_reference) · {{ $shown->act_reference }}@if($shown->act_date), {{ $shown->act_date->format('d/m/Y') }}@endif @endif
                            @if($shown->approver) · {{ __('approved by :name', ['name' => $shown->approver->name]) }}@if($shown->on_behalf) ({{ __('on behalf of the approver') }})@endif @endif
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-ui.button variant="secondary" size="md" shadow="none" href="{{ route('planning.budget-distribution.print', $shown) }}" target="_blank">{{ __('Print') }}</x-ui.button>
                        @if($canPrepare && ! $open)
                            <x-ui.button size="md" wire:click="start">{{ $inForce ? __('Prepare a new distribution') : __('Prepare the distribution') }}</x-ui.button>
                        @endif
                    </div>
                </div>
                @php
                    // the percentages are of what the shared decision has in each category
                    $base = [];
                    foreach ($classes as $c) { $base[$c->id] = (float) ($shown->decision?->amountFor($c->id) ?? 0); }
                    $baseTotal = array_sum($base);
                @endphp
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-zinc-50 text-xs text-zinc-600">
                            <tr>
                                <th scope="col" class="px-5 py-2 text-left font-medium">{{ __('Unit') }}</th>
                                @foreach($classes as $c)<th scope="col" class="px-3 py-2 text-right font-medium">{{ $c->code }} {{ $c->name }}</th>@endforeach
                                <th scope="col" class="px-5 py-2 text-right font-medium">{{ __('Total') }}</th>
                            </tr>
                            <tr class="text-zinc-800">
                                <th scope="row" class="px-5 py-1.5 text-left font-semibold">{{ __('Decided') }}</th>
                                @foreach($classes as $c)
                                    <td class="whitespace-nowrap px-3 py-1.5 text-right font-semibold tabular-nums">{{ $money($base[$c->id]) }}
                                        <span class="block text-xs font-normal text-zinc-500">{{ __(':pct of the decision', ['pct' => $pct($base[$c->id], (float) $shown->decision?->total)]) }}</span></td>
                                @endforeach
                                <td class="whitespace-nowrap px-5 py-1.5 text-right font-semibold tabular-nums">{{ $money($baseTotal) }}
                                    <span class="block text-xs font-normal text-zinc-500">{{ __(':pct of the decision', ['pct' => $pct($baseTotal, (float) $shown->decision?->total)]) }}</span></td>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100">
                            @foreach($units as $u)
                                @php $rowShare = 0; $rowPlan = 0; @endphp
                                <tr wire:key="dist-v-{{ $u->id }}">
                                    <th scope="row" class="px-5 py-2 text-left font-normal"><span class="font-medium text-zinc-900">{{ $u->code }}</span> <span class="hidden text-zinc-600 lg:inline">{{ \Illuminate\Support\Str::limit($u->name, 48) }}</span></th>
                                    @foreach($classes as $c)
                                        @php
                                            $share = (float) ($m[$u->id][$c->id] ?? 0);
                                            $p = ($plan[$u->id][$c->id]['in_force'] ?? 0) + ($plan[$u->id][$c->id]['preparing'] ?? 0);
                                            $rowShare += $share; $rowPlan += $p;
                                        @endphp
                                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">
                                            <span class="text-zinc-900">{{ $share > 0 ? $money($share) : '—' }}</span>
                                            @if($share > 0)<span class="block text-xs font-medium text-zinc-700">{{ $pct($share, $base[$c->id]) }}</span>@endif
                                            @if($p > 0)<span @class(['block text-xs', 'font-medium text-rose-700' => $p > $share + 0.5, 'text-zinc-500' => $p <= $share + 0.5])>{{ __('plan') }} {{ $money($p) }}</span>@endif
                                        </td>
                                    @endforeach
                                    <td class="whitespace-nowrap px-5 py-2 text-right font-medium tabular-nums">{{ $money($rowShare) }}
                                        <span class="block text-xs font-medium text-zinc-700">{{ $pct($rowShare, $baseTotal) }}</span>
                                        <span class="block text-xs font-normal text-zinc-500">{{ __('plan') }} {{ $money($rowPlan) }}</span></td>
                                </tr>
                            @endforeach
                            <tr class="bg-zinc-50">
                                @php $reserveTotal = collect($m[0] ?? [])->only($classes->pluck('id')->all())->sum(); @endphp
                                <th scope="row" class="px-5 py-2 text-left font-semibold text-zinc-900">{{ __('Kept by the institution') }}</th>
                                @foreach($classes as $c)
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-semibold tabular-nums">{{ $money($m[0][$c->id] ?? 0) }}
                                        <span class="block text-xs font-normal text-zinc-600">{{ $pct($m[0][$c->id] ?? 0, $base[$c->id]) }}</span></td>
                                @endforeach
                                <td class="whitespace-nowrap px-5 py-2 text-right font-semibold tabular-nums">{{ $money($reserveTotal) }}
                                    <span class="block text-xs font-normal text-zinc-600">{{ $pct($reserveTotal, $baseTotal) }}</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="space-y-1 border-t border-zinc-100 px-5 py-3 text-xs text-zinc-600">
                    @if($salaryClass)<p>{{ __(':category (:amount) stays with the institution: it is not shared among the units.', ['category' => $salaryClass->code.' '.$salaryClass->name, 'amount' => $money($m[0][$salaryClass->id] ?? 0)]) }}</p>@endif
                    <p>{{ __('Under each share, its percentage of what the decision has in the category.') }}
                        {{ __('"Plan" is what each unit has approved and is still preparing in the category, from the institution\'s own budget title. In red: above the share.') }}
                        @if($mode === PlanOptions::CEILING_WARN) {{ __('A head approves a plan above its share only with a remark.') }}
                        @elseif($mode === PlanOptions::CEILING_BLOCK) {{ __('A plan above its share cannot be approved.') }}@endif</p>
                    @if($shown->note)<p><span class="font-medium">{{ __('Note') }}:</span> {{ $shown->note }}</p>@endif
                </div>

                @if($open && $shown->is($open))
                    @if($problems)
                        <ul class="space-y-1 border-t border-zinc-100 px-5 py-3 text-sm text-rose-800">@foreach($problems as $pr)<li>{{ $pr }}</li>@endforeach</ul>
                    @endif
                    @if($open->status === BudgetDistribution::SUBMITTED)
                        @if($canApprove)
                            <div class="grid gap-4 border-t border-zinc-100 px-5 py-4 lg:grid-cols-2">
                                <div class="space-y-2">
                                    <p class="text-sm font-semibold text-zinc-900">{{ __('Approve') }}</p>
                                    <div class="grid gap-2 sm:grid-cols-3">
                                        <div class="sm:col-span-2">
                                            <label for="dist-act" class="field-label">{{ __('Reference of the act') }}</label>
                                            <input id="dist-act" type="text" wire:model="actReference" class="flux-input" placeholder="{{ __('For example Despacho n.º 12/2027') }}">
                                        </div>
                                        <div>
                                            <label for="dist-act-date" class="field-label">{{ __('Date') }}</label>
                                            <input id="dist-act-date" type="date" wire:model="actDate" class="flux-input">
                                        </div>
                                    </div>
                                    @error('actReference')<p class="field-error">{{ $message }}</p>@enderror
                                    @error('actDate')<p class="field-error">{{ $message }}</p>@enderror
                                    <x-ui.button wire:click="approve({{ $open->id }})" wire:loading.attr="disabled" wire:confirm="{{ __('Approve this distribution? It comes into force and every unit is told its share.') }}">{{ __('Approve the distribution') }}</x-ui.button>
                                </div>
                                <div class="space-y-2">
                                    <p class="text-sm font-semibold text-zinc-900">{{ __('Return') }}</p>
                                    <label for="dist-return" class="field-label">{{ __('Why is it returned?') }}</label>
                                    <textarea id="dist-return" rows="2" wire:model="returnReason" class="flux-input"></textarea>
                                    @error('returnReason')<p class="field-error">{{ $message }}</p>@enderror
                                    <x-ui.button variant="secondary" wire:click="sendBack({{ $open->id }})" wire:loading.attr="disabled">{{ __('Return to the budget unit') }}</x-ui.button>
                                </div>
                            </div>
                        @else
                            <p class="border-t border-zinc-100 px-5 py-3 text-sm text-zinc-700">{{ __('Sent for approval by :name on :date.', ['name' => $open->submitter?->name ?? '—', 'date' => $open->submitted_at?->timezone('Asia/Dili')->format('d/m/Y')]) }}</p>
                        @endif
                    @elseif($canPrepare)
                        <div class="flex justify-end border-t border-zinc-100 px-5 py-3"><x-ui.button wire:click="$set('editing', true)">{{ __('Continue preparing') }}</x-ui.button></div>
                    @endif
                @elseif($open)
                    <p class="border-t border-zinc-100 px-5 py-3 text-sm text-zinc-700">{{ __('A new distribution is being prepared: this one stays in force until it is approved.') }}</p>
                @endif
            </x-ui.card>
        @else
            <x-ui.card padding="p-0">
                <x-ui.empty-state :title="__('The budget of :year is not distributed yet', ['year' => $year])" :text="__('The budget unit shares the latest decision among the units by category; the head of the institution approves it.')">
                    @if($canPrepare)<x-ui.button wire:click="start">{{ __('Prepare the distribution') }}</x-ui.button>@endif
                </x-ui.empty-state>
            </x-ui.card>
        @endif

        @if($ownTitle->isNotEmpty())
            <p class="text-xs text-zinc-600">{{ __('Not part of the distribution, because they have a budget title of their own: :units.', ['units' => $ownTitle->pluck('code')->implode(', ')]) }}</p>
        @endif

        @if($history->count() > 1 || ($history->count() === 1 && ! $shown?->is($history->first())))
            <x-ui.card :title="__('Distributions of the year')" padding="p-0">
                <x-ui.table :headers="[__('Version'), __('Decision'), __('Status'), __('Given to the units'), __('Act'), '']" :right="[3]" class="text-sm">
                    @foreach($history as $h)
                        <tr wire:key="dist-h-{{ $h->id }}">
                            <td class="px-5 py-2.5 tabular-nums">{{ $h->version }}</td>
                            <td class="px-5 py-2.5">{{ $h->decision?->stageLabel() }}</td>
                            <td class="px-5 py-2.5">{{ $h->statusWord() }}</td>
                            <td class="px-5 py-2.5 num">{{ $money($h->given) }}</td>
                            <td class="px-5 py-2.5 text-zinc-600">{{ $h->act_reference ?? '—' }}@if($h->approver)<span class="block text-xs">{{ $h->approver->name }}, {{ $h->approved_at?->timezone('Asia/Dili')->format('d/m/Y') }}</span>@endif</td>
                            <td class="px-5 py-2.5 text-right"><a href="{{ route('planning.budget-distribution.print', $h) }}" target="_blank" class="text-xs font-medium text-indigo-700 hover:underline">{{ __('Print') }}</a></td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        @endif
    @endif
</div>
