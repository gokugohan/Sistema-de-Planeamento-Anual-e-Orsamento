<?php

use App\Support\ApprovalRules;
use App\Support\PlanOptions;
use Livewire\Volt\Component;

/**
 * Every rule of the planning cycle an institution may choose, in one list: a plain question and a switch each, the longer
 * explanation on demand. The approval rules (validation by the unit above, budget confirmation by finance, whether the
 * preparer may also check) live here too. Saving is audited.
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
    // finance and monitoring
    public bool $financeInstitutionWide = false;
    public bool $budgetDecisions = false;
    public bool $correctiveActions = false;
    // records
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
        $this->budgetDecisions = $o->budgetDecisions;
        $this->correctiveActions = $o->correctiveActions;
        $this->baselines = $o->baselines;
        $this->structureAtApproval = $o->structureAtApproval;

        $r = ApprovalRules::current();
        $this->parentValidation = $r->parentValidation;
        $this->budgetConfirmation = $r->budgetConfirmation;
        $this->segregation = $r->segregation;
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        $this->validate([
            'requiredFields' => 'array',
            'requiredFields.*' => 'in:'.implode(',', array_keys(PlanOptions::FIELDS)),
            'segregation' => 'in:'.implode(',', ApprovalRules::SEGREGATION_MODES),
        ]);

        (new PlanOptions(
            $this->obligations, $this->financeInstitutionWide, $this->requiredFields,
            $this->externalReference, $this->correctiveActions, $this->baselines, $this->structureAtApproval, $this->completeBeforeApproval,
            $this->budgetDecisions,
        ))->save();
        (new ApprovalRules($this->parentValidation, $this->budgetConfirmation, $this->segregation))->save();

        $this->dispatch('toast', message: __('Optional rules saved.'), type: 'success');
    }
}; ?>

<div class="space-y-6">
    <x-system-tabs />
    <x-ui.page-header :title="__('Optional rules')" :subtitle="__('How your institution plans and approves. Each rule is one question: switch it on or off, and open the details to see exactly what it changes. Changes apply from now on.')" />

    <form wire:submit="save" class="space-y-6">
        <x-ui.card :title="__('Planning')" padding="p-0">
            @include('livewire.pages.management.partials.rule-switch', ['prop' => 'obligations',
                'question' => __('Must every directorate submit a plan, or say it has nothing to plan?'),
                'details' => __('Each active top-level unit of the year is expected to finalize a plan, or to declare that it has nothing to plan. Planning for the year ends only when all of them have.')])
            <div class="border-t border-zinc-100 px-5 py-4">
                <p class="text-sm font-medium text-zinc-900">{{ __('What else must be filled in before an activity is submitted?') }}</p>
                <p class="mt-0.5 text-xs text-zinc-600">{{ __('A description, dates, at least one indicator with a name, unit and target, and a budget line are always required.') }}</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach(PlanOptions::FIELDS as $key => $label)
                        <label class="inline-flex cursor-pointer items-center gap-2 rounded-full border border-zinc-200 px-3 py-1.5 text-sm text-zinc-700 has-[:checked]:border-indigo-300 has-[:checked]:bg-indigo-50 has-[:checked]:text-indigo-800">
                            <input type="checkbox" value="{{ $key }}" wire:model="requiredFields" class="rounded border-zinc-300 text-indigo-600"> {{ __($label) }}
                        </label>
                    @endforeach
                </div>
            </div>
        </x-ui.card>

        <x-ui.card :title="__('Approval')" padding="p-0">
            @include('livewire.pages.management.partials.rule-switch', ['prop' => 'parentValidation',
                'question' => __('Does the head of the unit above validate before the final approval?'),
                'details' => __('After the head of a unit certifies an activity, the head of the unit above it (for example the national director) validates it before the head of the directorate approves the plan. Where the same person would act twice in a row, one click covers both.')])
            @include('livewire.pages.management.partials.rule-switch', ['prop' => 'budgetConfirmation',
                'question' => __('Does a finance officer confirm the budget before an activity is certified?'),
                'details' => __('A submitted activity can be certified only once a finance officer has confirmed its budget lines.')])
            <div class="flex flex-wrap items-start justify-between gap-4 border-t border-zinc-100 px-5 py-4">
                <div class="min-w-0">
                    <label for="segregation" class="text-sm font-medium text-zinc-900">{{ __('May the person who prepared an activity also certify or approve it?') }}</label>
                    <p class="mt-0.5 text-xs text-zinc-600">{{ __('Best practice: no. The one who prepares is not the one who checks.') }}</p>
                </div>
                <select id="segregation" wire:model="segregation" class="flux-input !w-auto">
                    <option value="block">{{ __('No (an administrator may, and it is marked)') }}</option>
                    <option value="flag">{{ __('Yes, but mark it in the record') }}</option>
                    <option value="off">{{ __('Yes, without any mark') }}</option>
                </select>
            </div>
            @include('livewire.pages.management.partials.rule-switch', ['prop' => 'completeBeforeApproval',
                'question' => __('Must every activity of a directorate be submitted before its plan is approved?'),
                'details' => __('The head approves a directorate\'s plan (and so finalizes it) only once every activity of the directorate has been submitted. Certifying and validating go on as the activities arrive; the approval waits, and the directorate sees why.')])
            @include('livewire.pages.management.partials.rule-switch', ['prop' => 'externalReference',
                'question' => __('Can a revision need an approval from outside the institution?'),
                'details' => __('Whoever opens a revision can mark it as needing an outside approval; it can then be approved only once the reference of that approval is recorded.')])
        </x-ui.card>

        <x-ui.card :title="__('Finance and monitoring')" padding="p-0">
            @include('livewire.pages.management.partials.rule-switch', ['prop' => 'financeInstitutionWide',
                'question' => __('Do finance officers work for the whole institution?'),
                'details' => __('Finance officers see every unit, confirm budgets and verify executed amounts everywhere, not only in their own unit.')])
            @include('livewire.pages.management.partials.rule-switch', ['prop' => 'budgetDecisions',
                'question' => __('Compare the plan with the budget decisions?'),
                'details' => __('An administrator records what the budget review committee, the State Budget law or a supplementary budget decided, by category; the plan is compared with the latest decision and the dashboard shows the difference. A decision limits nothing: the units revise their activities to follow it.')])
            @include('livewire.pages.management.partials.rule-switch', ['prop' => 'correctiveActions',
                'question' => __('Track corrective actions when a report is off track?'),
                'details' => __('Where a report is off track or partly on track, the unit records corrective actions with a person responsible and a due date, and closes them when done.')])
        </x-ui.card>

        <x-ui.card :title="__('Records')" padding="p-0">
            @include('livewire.pages.management.partials.rule-switch', ['prop' => 'baselines',
                'question' => __('Can the approved plan be recorded as a baseline?'),
                'details' => __('An administrator can record the approved plan of a year as it stands, with a note; the final report can then be printed as it stood at that moment. Recording a baseline approves nothing.')])
            @include('livewire.pages.management.partials.rule-switch', ['prop' => 'structureAtApproval',
                'question' => __('Do reports keep the structure the plan had when it was approved?'),
                'details' => __('The final report shows each activity under the unit it belonged to when it was approved, even if the structure has changed since.')])
        </x-ui.card>

        <div class="flex justify-end"><x-ui.button type="submit">{{ __('Save') }}</x-ui.button></div>
    </form>
</div>
