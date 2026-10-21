<?php

use App\Support\PlanOptions;
use Livewire\Volt\Component;

/**
 * The optional rules of the planning cycle. Each one is off until the institution confirms that its own rules ask for
 * it; switching one on changes the screens and checks described next to it. Saving is audited.
 */
new class extends Component {
    public bool $obligations = false;
    public bool $financeInstitutionWide = false;
    public array $requiredFields = [];
    public bool $externalReference = false;
    public bool $correctiveActions = false;
    public bool $baselines = false;
    public bool $structureAtApproval = false;
    public bool $completeBeforeApproval = false;
    public int $year;
    public string $guidance = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        $o = PlanOptions::current();
        $this->obligations = $o->obligations;
        $this->financeInstitutionWide = $o->financeInstitutionWide;
        $this->requiredFields = $o->requiredFields;
        $this->externalReference = $o->externalReference;
        $this->correctiveActions = $o->correctiveActions;
        $this->baselines = $o->baselines;
        $this->structureAtApproval = $o->structureAtApproval;
        $this->completeBeforeApproval = $o->completeBeforeApproval;
        $this->year = \App\Support\FiscalYear::current();
        $this->guidance = (string) PlanOptions::guidance($this->year);
    }

    public function updatedYear(): void
    {
        $this->guidance = (string) PlanOptions::guidance((int) $this->year);
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        $this->validate([
            'requiredFields' => 'array',
            'requiredFields.*' => 'in:'.implode(',', array_keys(PlanOptions::FIELDS)),
            'year' => 'required|integer|min:2000|max:2100',
            'guidance' => 'nullable|string|max:4000',
        ]);

        (new PlanOptions(
            $this->obligations, $this->financeInstitutionWide, $this->requiredFields,
            $this->externalReference, $this->correctiveActions, $this->baselines, $this->structureAtApproval, $this->completeBeforeApproval,
        ))->save();
        PlanOptions::setGuidance((int) $this->year, $this->guidance);

        $this->dispatch('toast', message: __('Optional rules saved.'), type: 'success');
    }
}; ?>

<div class="space-y-6">
    <x-ui.page-header :title="__('Optional rules')" :subtitle="__('Rules of the planning cycle that are off until your institution confirms that it needs them. Each one changes only what is written next to it.')" />

    <form wire:submit="save" class="space-y-6">
        <x-ui.card :title="__('Planning')" padding="p-5">
            <div class="space-y-4 text-sm text-zinc-700">
                <label class="flex items-start gap-3">
                    <input type="checkbox" wire:model="obligations" class="mt-0.5 rounded border-zinc-300 text-indigo-600">
                    <span><span class="font-medium text-zinc-900">{{ __('Every active directorate must submit') }}</span><br>
                        <span class="text-zinc-600">{{ __('Each active top-level unit of the year is expected to finalize a plan, or to declare that it has nothing to plan. Planning for the year ends only when all of them have.') }}</span></span>
                </label>
                <div>
                    <p class="font-medium text-zinc-900">{{ __('Also required before an activity is submitted') }}</p>
                    <p class="text-zinc-600">{{ __('A description, dates, at least one indicator with a name, unit and target, and a budget line are always required.') }}</p>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        @foreach(PlanOptions::FIELDS as $key => $label)
                            <label class="flex items-center gap-2"><input type="checkbox" value="{{ $key }}" wire:model="requiredFields" class="rounded border-zinc-300 text-indigo-600"> {{ __($label) }}</label>
                        @endforeach
                    </div>
                </div>
                <div class="grid gap-3 sm:grid-cols-[8rem_1fr]">
                    <div>
                        <label for="year" class="field-label">{{ __('Fiscal year') }}</label>
                        <input id="year" type="number" wire:model.live.debounce.500ms="year" class="flux-input">
                    </div>
                    <div>
                        <label for="guidance" class="field-label">{{ __('Guidance for preparing the plan') }}</label>
                        <textarea id="guidance" rows="3" wire:model="guidance" class="flux-input" placeholder="{{ __('Shown to those who prepare activities for this year, for example the deadlines and instructions of the planning circular.') }}"></textarea>
                        @error('guidance')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card :title="__('Approval and revisions')" padding="p-5">
            <div class="space-y-4 text-sm text-zinc-700">
                <label class="flex items-start gap-3">
                    <input type="checkbox" wire:model="externalReference" class="mt-0.5 rounded border-zinc-300 text-indigo-600">
                    <span><span class="font-medium text-zinc-900">{{ __('Some revisions need an approval outside the institution') }}</span><br>
                        <span class="text-zinc-600">{{ __('Whoever opens a revision can mark it as needing an outside approval; it can then be approved only once the reference of that approval is recorded.') }}</span></span>
                </label>
                <label class="flex items-start gap-3">
                    <input type="checkbox" wire:model="completeBeforeApproval" class="mt-0.5 rounded border-zinc-300 text-indigo-600">
                    <span><span class="font-medium text-zinc-900">{{ __('The plan must be complete before approval') }}</span><br>
                        <span class="text-zinc-600">{{ __('A directorate\'s activities are certified and approved, and its plan finalized, only once every activity of the directorate has been submitted. Until then the decisions wait and the directorate sees why.') }}</span></span>
                </label>
                <label class="flex items-start gap-3">
                    <input type="checkbox" wire:model="baselines" class="mt-0.5 rounded border-zinc-300 text-indigo-600">
                    <span><span class="font-medium text-zinc-900">{{ __('Record the approved plan as a baseline') }}</span><br>
                        <span class="text-zinc-600">{{ __('An administrator can record the approved plan of a year as it stands, with a note; the final report can then be printed as it stood at that moment. Recording a baseline approves nothing.') }}</span></span>
                </label>
            </div>
        </x-ui.card>

        <x-ui.card :title="__('Finance and monitoring')" padding="p-5">
            <div class="space-y-4 text-sm text-zinc-700">
                <label class="flex items-start gap-3">
                    <input type="checkbox" wire:model="financeInstitutionWide" class="mt-0.5 rounded border-zinc-300 text-indigo-600">
                    <span><span class="font-medium text-zinc-900">{{ __('Finance officers work for the whole institution') }}</span><br>
                        <span class="text-zinc-600">{{ __('Finance officers see every unit, confirm budgets and verify executed amounts everywhere, not only in their own unit.') }}</span></span>
                </label>
                <label class="flex items-start gap-3">
                    <input type="checkbox" wire:model="correctiveActions" class="mt-0.5 rounded border-zinc-300 text-indigo-600">
                    <span><span class="font-medium text-zinc-900">{{ __('Track corrective actions') }}</span><br>
                        <span class="text-zinc-600">{{ __('Where a report is off track or partly on track, the unit records corrective actions with a person responsible and a due date, and closes them when done.') }}</span></span>
                </label>
                <label class="flex items-start gap-3">
                    <input type="checkbox" wire:model="structureAtApproval" class="mt-0.5 rounded border-zinc-300 text-indigo-600">
                    <span><span class="font-medium text-zinc-900">{{ __('Reports keep the structure at approval') }}</span><br>
                        <span class="text-zinc-600">{{ __('The final report shows each activity under the unit it belonged to when it was approved, even if the structure has changed since.') }}</span></span>
                </label>
            </div>
        </x-ui.card>

        <div class="flex justify-end"><x-ui.button type="submit">{{ __('Save') }}</x-ui.button></div>
    </form>
</div>
