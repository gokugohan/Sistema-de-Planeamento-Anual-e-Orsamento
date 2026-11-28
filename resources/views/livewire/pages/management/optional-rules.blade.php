<?php

use App\Services\ApproverLists;
use App\Support\ApprovalRules;
use App\Support\PlanOptions;
use Livewire\Volt\Component;

/**
 * Every rule of the planning cycle an institution may choose, in four sections in the order of the year (planning,
 * approval, the budget envelope, reporting and records), each folded with how many of its rules are on. A rule is a
 * plain question and a switch, the longer explanation on demand; the budget envelope is one choice (not used, recorded,
 * or recorded and shared among the directorates). What the law fixes (salaries stay with the institution) is not a
 * choice, and what changes every year (the accounts a revision may not increase) is set on the year's budget page.
 * Saving is audited.
 */
new class extends Component {
    // planning
    public bool $obligations = false;
    public array $requiredFields = [];
    // approval
    public bool $parentValidation = true;
    public bool $budgetConfirmation = false;
    public string $segregation = ApprovalRules::SEGREGATION_BLOCK;
    public bool $completeBeforeApproval = true;
    public bool $externalReference = false;
    public bool $approvalLists = false;
    public bool $financeInstitutionWide = false;
    // the budget envelope: off, record, share
    public string $envelope = 'off';
    public string $ceilingMode = PlanOptions::CEILING_WARN;
    public ?string $budgetUnitId = null;
    public string $distributionApprover = PlanOptions::APPROVER_INSTITUTION_HEAD;
    public bool $budgetChangeChecks = false;
    // reporting and records
    public bool $correctiveActions = false;
    public bool $baselines = false;
    public bool $structureAtApproval = false;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        $o = PlanOptions::current();
        $this->obligations = $o->obligations;
        $this->requiredFields = $o->requiredFields;
        $this->completeBeforeApproval = $o->completeBeforeApproval;
        $this->externalReference = $o->externalReference;
        $this->financeInstitutionWide = $o->financeInstitutionWide;
        $this->envelope = $o->envelopeUse();
        $this->ceilingMode = $o->ceilingMode;
        $this->budgetUnitId = $o->budgetUnitId ? (string) $o->budgetUnitId : null;
        $this->distributionApprover = $o->distributionApprover;
        $this->budgetChangeChecks = $o->budgetChangeChecks;
        $this->correctiveActions = $o->correctiveActions;
        $this->baselines = $o->baselines;
        $this->structureAtApproval = $o->structureAtApproval;

        $r = ApprovalRules::current();
        $this->parentValidation = $r->parentValidation;
        $this->budgetConfirmation = $r->budgetConfirmation;
        $this->segregation = $r->segregation;
        $this->approvalLists = $r->approvalLists;
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        $this->validate([
            'requiredFields' => 'array',
            'requiredFields.*' => 'in:'.implode(',', array_keys(PlanOptions::FIELDS)),
            'segregation' => 'in:'.implode(',', ApprovalRules::SEGREGATION_MODES),
            'envelope' => 'in:off,record,share',
            'ceilingMode' => 'in:'.implode(',', PlanOptions::CEILING_MODES),
            'distributionApprover' => 'in:'.implode(',', PlanOptions::APPROVERS),
            'budgetUnitId' => 'nullable|integer|exists:directorates,id',
        ]);
        // the budget unit prepares the distribution: without it only an administrator could, so ask for it
        if ($this->envelope === 'share' && ! $this->budgetUnitId) {
            $this->addError('budgetUnitId', __('Choose the unit that prepares the distribution.'));

            return;
        }

        (new PlanOptions(
            obligations: $this->obligations, financeInstitutionWide: $this->financeInstitutionWide, requiredFields: $this->requiredFields,
            externalReference: $this->externalReference, correctiveActions: $this->correctiveActions, baselines: $this->baselines,
            structureAtApproval: $this->structureAtApproval, completeBeforeApproval: $this->completeBeforeApproval,
            budgetDecisions: $this->envelope !== 'off', budgetDistribution: $this->envelope === 'share', ceilingMode: $this->ceilingMode,
            budgetUnitId: $this->budgetUnitId ? (int) $this->budgetUnitId : null, distributionApprover: $this->distributionApprover,
            budgetChangeChecks: $this->budgetChangeChecks,
        ))->save();
        // a list in force cannot be switched away: it would go on deciding with nobody able to see or empty it
        if (! $this->approvalLists && app(ApproverLists::class)->anyList()) {
            $this->approvalLists = true;
            $this->dispatch('toast', message: __('Approval lists stay on while a unit has a list. Empty the lists first.'), type: 'info');
        }
        (new ApprovalRules($this->parentValidation, $this->budgetConfirmation, $this->segregation, $this->approvalLists))->save();

        $this->dispatch('toast', message: __('Optional rules saved.'), type: 'success');
    }

    public function with(): array
    {
        return [
            // the units that can prepare a distribution: the top-level units of the institution's own budget title
            'roots' => app(\App\Services\BudgetDistributions::class)->units(),
            // how many rules of each section are on, shown on the folded section
            'on' => [
                'planning' => [(int) $this->obligations + (int) ($this->requiredFields !== []), 2],
                'approval' => [(int) $this->parentValidation + (int) $this->budgetConfirmation + (int) ($this->segregation === ApprovalRules::SEGREGATION_BLOCK)
                    + (int) $this->completeBeforeApproval + (int) $this->approvalLists + (int) $this->externalReference + (int) $this->financeInstitutionWide, 7],
                'records' => [(int) $this->correctiveActions + (int) $this->baselines + (int) $this->structureAtApproval, 3],
            ],
        ];
    }
}; ?>

<div class="space-y-4">
    <x-system-tabs />
    <x-ui.page-header :title="__('Optional rules')" :subtitle="__('How your institution plans, approves and uses its budget envelope, in the order of the year. Open a section to change its rules; each is one question, with the details on demand. Changes apply from now on.')" />

    <form wire:submit="save" class="space-y-4">
        {{-- 1 Planning --}}
        <x-ui.card padding="p-0">
            <div x-data="{ open: false }">
                <button type="button" @click="open = ! open" :aria-expanded="open.toString()" class="flex w-full items-center justify-between gap-4 px-5 py-4 text-left">
                    <span class="flex items-center gap-3">
                        <span class="flex h-6 w-6 items-center justify-center rounded-full bg-indigo-50 text-xs font-bold text-indigo-600" aria-hidden="true">1</span>
                        <span><span class="block text-sm font-semibold text-zinc-900">{{ __('Planning') }}</span>
                            <span class="block text-xs text-zinc-500">{{ __('What every directorate must plan, and what an activity must have.') }}</span></span>
                    </span>
                    <span class="flex shrink-0 items-center gap-3">
                        <span class="text-xs text-zinc-500">{{ __(':on of :all on', ['on' => $on['planning'][0], 'all' => $on['planning'][1]]) }}</span>
                        <svg class="h-4 w-4 text-zinc-400 transition-transform" :class="open && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                    </span>
                </button>
                <div x-show="open" x-cloak class="border-t border-zinc-100">
                    @include('livewire.pages.management.partials.rule-switch', ['prop' => 'obligations',
                        'question' => __('Must every directorate submit a plan, or say it has nothing to plan?'),
                        'details' => __('Each active top-level unit of the year is expected to finalize a plan, or to declare that it has nothing to plan. Planning for the year ends only when all of them have.')])
                    <div class="border-t border-zinc-100 px-5 py-4">
                        <p class="text-sm font-medium text-zinc-900">{{ __('What else must be filled in before an activity is submitted?') }}</p>
                        <p class="mt-0.5 text-xs text-zinc-600">{{ __('A description, dates, at least one indicator with a name, unit and target, and a budget line are always required.') }}</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach(PlanOptions::FIELDS as $key => $label)
                                <label class="inline-flex cursor-pointer items-center gap-2 rounded-full border border-zinc-200 px-3 py-1.5 text-sm text-zinc-700 has-[:checked]:border-indigo-300 has-[:checked]:bg-indigo-50 has-[:checked]:text-indigo-800">
                                    <input type="checkbox" value="{{ $key }}" wire:model.live="requiredFields" class="rounded border-zinc-300 text-indigo-600"> {{ __($label) }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </x-ui.card>

        {{-- 2 Approval --}}
        <x-ui.card padding="p-0">
            <div x-data="{ open: false }">
                <button type="button" @click="open = ! open" :aria-expanded="open.toString()" class="flex w-full items-center justify-between gap-4 px-5 py-4 text-left">
                    <span class="flex items-center gap-3">
                        <span class="flex h-6 w-6 items-center justify-center rounded-full bg-indigo-50 text-xs font-bold text-indigo-600" aria-hidden="true">2</span>
                        <span><span class="block text-sm font-semibold text-zinc-900">{{ __('Approval') }}</span>
                            <span class="block text-xs text-zinc-500">{{ __('Who checks and approves, and in which order.') }}</span></span>
                    </span>
                    <span class="flex shrink-0 items-center gap-3">
                        <span class="text-xs text-zinc-500">{{ __(':on of :all on', ['on' => $on['approval'][0], 'all' => $on['approval'][1]]) }}</span>
                        <svg class="h-4 w-4 text-zinc-400 transition-transform" :class="open && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                    </span>
                </button>
                <div x-show="open" x-cloak class="border-t border-zinc-100">
                    @include('livewire.pages.management.partials.rule-switch', ['prop' => 'parentValidation',
                        'question' => __('Does the head of the unit above also certify before the final approval?'),
                        'details' => __('After the head of a unit certifies an activity, the head of the unit above it (for example the national director) certifies it too before the head of the directorate approves the plan. Where the same person would act twice in a row, one click covers both.')])
                    @include('livewire.pages.management.partials.rule-switch', ['prop' => 'budgetConfirmation',
                        'question' => __('Does a finance officer confirm the budget before an activity is certified?'),
                        'details' => __('A submitted activity can be certified only once a finance officer has confirmed its budget lines.')])
                    @include('livewire.pages.management.partials.rule-switch', ['prop' => 'financeInstitutionWide',
                        'question' => __('Do finance officers work for the whole institution?'),
                        'details' => __('Finance officers see every unit, confirm budgets and verify executed amounts everywhere, not only in their own unit.')])
                    <div class="flex flex-wrap items-start justify-between gap-4 border-t border-zinc-100 px-5 py-4">
                        <div class="min-w-0">
                            <label for="segregation" class="text-sm font-medium text-zinc-900">{{ __('May the person who prepared an activity also certify or approve it?') }}</label>
                            <p class="mt-0.5 text-xs text-zinc-600">{{ __('Best practice: no. The one who prepares is not the one who checks.') }}</p>
                        </div>
                        <select id="segregation" wire:model.live="segregation" class="flux-input !w-auto">
                            <option value="block">{{ __('No (an administrator may, and it is marked)') }}</option>
                            <option value="flag">{{ __('Yes, but mark it in the record') }}</option>
                            <option value="off">{{ __('Yes, without any mark') }}</option>
                        </select>
                    </div>
                    @include('livewire.pages.management.partials.rule-switch', ['prop' => 'completeBeforeApproval',
                        'question' => __('Must every activity of a directorate be submitted before its plan is approved?'),
                        'details' => __('The head approves a directorate\'s plan (and so finalizes it) only once every activity of the directorate has been submitted. Certifying goes on as the activities arrive; the approval waits, and the directorate sees why.')])
                    @include('livewire.pages.management.partials.rule-switch', ['prop' => 'approvalLists',
                        'question' => __('Do some units name who prepares, certifies or approves, instead of the usual heads?'),
                        'details' => __('Opens the Approval lists page. Use it only where a step is done by someone other than the head, or where several people sign in order. Without it, the technical officer prepares, the head of the unit certifies and the head of the directorate approves. It stays on while any unit has a list.')])
                    @include('livewire.pages.management.partials.rule-switch', ['prop' => 'externalReference',
                        'question' => __('Can a revision need an approval from outside the institution?'),
                        'details' => __('Whoever opens a revision can mark it as needing an outside approval; it can then be approved only once the reference of that approval is recorded.')])
                </div>
            </div>
        </x-ui.card>

        {{-- 3 The budget envelope --}}
        <x-ui.card padding="p-0">
            <div x-data="{ open: {{ $errors->has('budgetUnitId') ? 'true' : 'false' }} }">
                <button type="button" @click="open = ! open" :aria-expanded="open.toString()" class="flex w-full items-center justify-between gap-4 px-5 py-4 text-left">
                    <span class="flex items-center gap-3">
                        <span class="flex h-6 w-6 items-center justify-center rounded-full bg-indigo-50 text-xs font-bold text-indigo-600" aria-hidden="true">3</span>
                        <span><span class="block text-sm font-semibold text-zinc-900">{{ __('Budget envelope') }}</span>
                            <span class="block text-xs text-zinc-500">{{ __('The ceiling, the State Budget law and the supplementary budgets, and how they reach the directorates.') }}</span></span>
                    </span>
                    <span class="flex shrink-0 items-center gap-3">
                        <span class="text-xs text-zinc-500">{{ ['off' => __('Not used'), 'record' => __('Recorded'), 'share' => __('Recorded and shared')][$envelope] ?? '' }}</span>
                        <svg class="h-4 w-4 text-zinc-400 transition-transform" :class="open && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                    </span>
                </button>
                <div x-show="open" x-cloak class="space-y-4 border-t border-zinc-100 px-5 py-4">
                    <fieldset>
                        <legend class="text-sm font-medium text-zinc-900">{{ __('How does the institution use its budget envelope?') }}</legend>
                        <div class="mt-2 grid gap-2 lg:grid-cols-3">
                            @foreach([
                                'off' => [__('Not used'), __('The plan is not compared with any budget figure.')],
                                'record' => [__('Record it'), __('The ceiling, the law and the supplementary budgets are recorded by category, and the plan is compared with the latest one. Nothing is shared among the directorates.')],
                                'share' => [__('Record it and share it among the directorates'), __('The budget unit also shares each envelope among the directorates by category; the head of the institution approves it, and each directorate plans within its share (Decreto-Lei n.º 42/2025, art. 18-19: the split inside the institution is its own decision).')],
                            ] as $key => [$label, $text])
                                <label wire:key="env-{{ $key }}" @class(['flex cursor-pointer gap-3 rounded-lg border p-3 text-sm',
                                    'border-indigo-500 bg-indigo-50 ring-1 ring-indigo-500' => $envelope === $key, 'border-zinc-200 hover:border-zinc-300' => $envelope !== $key])>
                                    <input type="radio" name="envelope" value="{{ $key }}" wire:model.live="envelope" class="mt-0.5 border-zinc-300 text-indigo-600">
                                    <span><span class="block font-medium text-zinc-900">{{ $label }}</span><span class="mt-0.5 block text-xs text-zinc-600">{{ $text }}</span></span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    @if($envelope === 'share')
                        <div class="grid gap-4 rounded-lg bg-zinc-50 p-4 sm:grid-cols-3">
                            <div>
                                <label for="budget-unit" class="text-sm font-medium text-zinc-900">{{ __('Which unit prepares the distribution?') }}</label>
                                <p class="mt-0.5 text-xs text-zinc-600">{{ __('In a ministry, its finance and administration directorate. Its head, technical and finance officers prepare and submit.') }}</p>
                                <select id="budget-unit" wire:model="budgetUnitId" class="flux-input mt-2">
                                    <option value="">{{ __('Choose a unit') }}</option>
                                    @foreach($roots as $r)<option value="{{ $r->id }}">{{ $r->code }} — {{ $r->name }}</option>@endforeach
                                </select>
                                @error('budgetUnitId')<p class="field-error">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="dist-approver" class="text-sm font-medium text-zinc-900">{{ __('Who approves the distribution?') }}</label>
                                <p class="mt-0.5 text-xs text-zinc-600">{{ __('It decides how the institution\'s money is shared, so normally the head of the institution, by despacho.') }}</p>
                                <select id="dist-approver" wire:model="distributionApprover" class="flux-input mt-2">
                                    <option value="institution_head">{{ __('The head of the institution (Minister role)') }}</option>
                                    <option value="budget_unit_head">{{ __('The head of the budget unit, by delegation') }}</option>
                                </select>
                            </div>
                            <div>
                                <label for="ceiling-mode" class="text-sm font-medium text-zinc-900">{{ __('When a directorate\'s plan is above its share?') }}</label>
                                <p class="mt-0.5 text-xs text-zinc-600">{{ __('Checked when the head approves the plan. Only the final envelope (the law) can stop it; a provisional ceiling only warns.') }}</p>
                                <select id="ceiling-mode" wire:model="ceilingMode" class="flux-input mt-2">
                                    <option value="warn">{{ __('Warn: the plan is approved only with a remark') }}</option>
                                    <option value="block">{{ __('Stop: the plan cannot be approved until it is within its share') }}</option>
                                    <option value="off">{{ __('Only show it') }}</option>
                                </select>
                            </div>
                            <p class="text-xs text-zinc-600 sm:col-span-3">{{ __('Salaries and wages always stay with the institution: they are paid centrally from the payroll, and money may not leave them (Lei n.º 3/2025, art. 48.3).') }}</p>
                        </div>
                    @endif

                    <div class="-mx-5 border-t border-zinc-100">
                        @include('livewire.pages.management.partials.rule-switch', ['prop' => 'budgetChangeChecks',
                            'question' => __('Check revisions that move money against the rules on budget changes?'),
                            'details' => __('A revision that moves money between categories or programs is a budget change (Decreto-Lei n.º 42/2025, art. 35). It is warned when money leaves salaries or development capital, or when more than 20 % of what the plan has in a category moves (not between programs of the Infrastructure Fund); it must say what it does to the targets; and its budget change can be printed for the request to the Ministry of Finance. The accounts the year\'s execution decree does not let grow are set on the budget page of each year. Warnings only: the Ministry of Finance makes the binding check.')])
                    </div>
                </div>
            </div>
        </x-ui.card>

        {{-- 4 Reporting and records --}}
        <x-ui.card padding="p-0">
            <div x-data="{ open: false }">
                <button type="button" @click="open = ! open" :aria-expanded="open.toString()" class="flex w-full items-center justify-between gap-4 px-5 py-4 text-left">
                    <span class="flex items-center gap-3">
                        <span class="flex h-6 w-6 items-center justify-center rounded-full bg-indigo-50 text-xs font-bold text-indigo-600" aria-hidden="true">4</span>
                        <span><span class="block text-sm font-semibold text-zinc-900">{{ __('Reporting and records') }}</span>
                            <span class="block text-xs text-zinc-500">{{ __('What happens when a report is off track, and how the approved plan is kept.') }}</span></span>
                    </span>
                    <span class="flex shrink-0 items-center gap-3">
                        <span class="text-xs text-zinc-500">{{ __(':on of :all on', ['on' => $on['records'][0], 'all' => $on['records'][1]]) }}</span>
                        <svg class="h-4 w-4 text-zinc-400 transition-transform" :class="open && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                    </span>
                </button>
                <div x-show="open" x-cloak class="border-t border-zinc-100">
                    @include('livewire.pages.management.partials.rule-switch', ['prop' => 'correctiveActions',
                        'question' => __('Track corrective actions when a report is off track?'),
                        'details' => __('Where a report is off track or partly on track, the unit records corrective actions with a person responsible and a due date, and closes them when done.')])
                    @include('livewire.pages.management.partials.rule-switch', ['prop' => 'baselines',
                        'question' => __('Can the approved plan be recorded as a baseline?'),
                        'details' => __('An administrator can record the approved plan of a year as it stands, with a note; the final report can then be printed as it stood at that moment. Recording a baseline approves nothing.')])
                    @include('livewire.pages.management.partials.rule-switch', ['prop' => 'structureAtApproval',
                        'question' => __('Do reports keep the structure the plan had when it was approved?'),
                        'details' => __('The final report shows each activity under the unit it belonged to when it was approved, even if the structure has changed since.')])
                </div>
            </div>
        </x-ui.card>

        <div class="flex justify-end"><x-ui.button type="submit">{{ __('Save') }}</x-ui.button></div>
    </form>
</div>
