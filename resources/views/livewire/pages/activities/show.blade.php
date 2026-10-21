<?php

use Livewire\Volt\Component;
use App\Models\Activity;
use App\Models\Indicator;
use App\Models\LineItem;
use App\Models\EconomicClassification;

new class extends Component {
    public Activity $activity;

    // Indicator form fields
    public string $iName   = '';
    public string $iType   = 'Output';
    public string $iUnit   = '';
    public        $iTarget = '';

    // Budget form fields
    public $bClassId = '';
    public $bAmount  = '';
    public $bSourceId = '';
    public $bIndicatorId = '';
    public $moveUnitId = '';
    public $moveRemark = '';

    // Workflow dialogs: the note an approver or reopener may leave, and the reason a return must give
    public string $note   = '';
    public string $reason = '';
    public string $externalReference = '';

    public function with(): array
    {
        $workflow = app(\App\Services\ActivityWorkflow::class);
        $user = auth()->user();

        return [
            'activeYear'              => \App\Support\FiscalYear::current(),
            'economicClassifications' => EconomicClassification::orderBy('code')->get(),
            'canEdit'                 => $user->can('update', $this->activity),
            'canDelete'               => $user->can('delete', $this->activity),
            'nextStatus'              => $workflow->next($user, $this->activity),
            'canReturn'               => $workflow->canReturn($user, $this->activity),
            'canApprove'              => $workflow->canApprove($user, $this->activity),
            'canReopen'               => $workflow->canReopen($user, $this->activity),
            'problems'                => $workflow->problems($this->activity),
            'trail'                   => app(\App\Services\ApprovalTrail::class)->for($this->activity),
            'approval'                => app(\App\Services\ApprovalTrail::class)->approval($this->activity),
            'awaiting'                => $workflow->awaiting($this->activity),
            // the resource plan: where the institution asks for the budget to be confirmed, say whether it has been
            'budgetRule'              => \App\Support\ApprovalRules::current()->budgetConfirmation,
            'budgetBy'                => $this->activity->budget_confirmed_by ? \App\Models\User::whereKey($this->activity->budget_confirmed_by)->value('name') : null,
            // once approved, how the activity is doing: verified spending, indicators, the latest words
            'execution'               => (int) $this->activity->version > 0 ? app(\App\Services\ActivityExecutionSummary::class)->for($this->activity) : null,
            // the approved versions, and what a revision in progress changes against the version in force
            'versions'                => app(\App\Services\ActivityVersions::class)->of($this->activity),
            'revising'                => \App\Services\ActivityVersions::revising($this->activity),
            'changes'                 => \App\Services\ActivityVersions::revising($this->activity) ? app(\App\Services\ActivityVersions::class)->changes($this->activity) : null,
            // an optional rule: a revision may need an approval outside the institution, recorded by its reference
            'externalRule'            => \App\Support\PlanOptions::current()->externalReference,
            'mayMarkExternal'         => $user->isAdministrator() || $user->can('update', $this->activity),
            'mayRecordExternal'       => $user->isAdministrator() || $workflow->isFinalApprover($user, $this->activity),
            'externalMissing'         => $workflow->externalApprovalMissing($this->activity),
        ];
    }

    /** Every request starts from the stored id: bring back what the page reads, so typing into a field never lazy-loads. */
    public function hydrate(): void
    {
        $this->activity->loadMissing([
            'program.alignments', 'subprogram.alignments', 'directorate', 'indicators.actions',
            'lineItems.economicClassification', 'lineItems.fundingSource', 'lineItems.adjustedBudget',
            'approvedBy', 'reviewedBy',
        ]);
    }

    public function mount(Activity $activity): void
    {
        $this->authorize('view', $activity);

        $this->activity = $activity->load([
            'program.alignments', 'subprogram.alignments', 'directorate', 'indicators.actions',
            'lineItems.economicClassification', 'lineItems.fundingSource', 'lineItems.adjustedBudget',
            'approvedBy', 'reviewedBy',
        ]);
    }

    // ── Indicator management ────────────────────────────────────────────

    public function addIndicator(): void
    {
        $this->authorize('update', $this->activity);
        $this->validate([
            'iName'   => 'required|string|max:255',
            'iType'   => 'required|in:Output,Outcome',
            'iUnit'   => 'required|string|max:100',
            'iTarget' => 'required|numeric|min:0',
        ]);

        Indicator::create([
            'activity_id'   => $this->activity->id,
            'name'          => $this->iName,
            'type'          => $this->iType,
            'unit'          => $this->iUnit,
            'annual_target' => $this->iTarget,
        ]);

        $this->reset(['iName', 'iUnit', 'iTarget']);
        $this->iType = 'Output';
        $this->refresh();
        $this->dispatch('indicator-added');
        $this->dispatch('toast', message: __('Indicator added.'), type: 'success');
    }

    public function deleteIndicator($id): void
    {
        $this->authorize('update', $this->activity);
        $this->activity->indicators()->findOrFail($id)->delete();
        $this->refresh();
    }

    // ── Budget management ───────────────────────────────────────────────

    public function addLineItem(): void
    {
        $this->authorize('update', $this->activity);
        $this->validate([
            'bClassId' => 'required|exists:economic_classifications,id',
            'bSourceId' => 'nullable|exists:funding_sources,id',
            'bIndicatorId' => 'nullable|integer',
            'bAmount'  => 'required|numeric|min:0.01|max:999999999999',
        ]);
        $sourceId = $this->bSourceId ?: \App\Models\FundingSource::defaultId();
        $indicatorId = $this->bIndicatorId ? $this->activity->indicators()->whereKey((int) $this->bIndicatorId)->value('id') : null; // an indicator of this activity, or the whole activity

        $existing = LineItem::where('activity_id', $this->activity->id)
            ->where('economic_classification_id', $this->bClassId)
            ->where('funding_source_id', $sourceId)
            ->where('indicator_id', $indicatorId)
            ->first();

        if ($existing) {
            $existing->increment('estimated_amount', $this->bAmount);
        } else {
            LineItem::create([
                'activity_id'                => $this->activity->id,
                'economic_classification_id' => $this->bClassId,
                'funding_source_id'          => $sourceId,
                'indicator_id'               => $indicatorId,
                'estimated_amount'           => $this->bAmount,
            ]);
        }

        $this->reset(['bClassId', 'bAmount', 'bSourceId', 'bIndicatorId']);
        $this->refresh();
        $this->dispatch('lineitem-added');
        $this->dispatch('toast', message: __('Budget item added.'), type: 'success');
    }

    public function deleteLineItem($id): void
    {
        $this->authorize('update', $this->activity);
        $this->activity->lineItems()->findOrFail($id)->delete();
        $this->refresh();
    }

    // ── Workflow actions (rules live in ActivityWorkflow) ───────────────

    public function submit(): void
    {
        $before = $this->activity->status;
        try {
            $to = app(\App\Services\ActivityWorkflow::class)->advance(auth()->user(), $this->activity);
        } catch (\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');
            return;
        }
        $this->refresh();
        if ($to === $before) {
            $this->dispatch('toast', message: __('Your signature is recorded. It moves on after the others on the list have signed.'), type: 'success');

            return;
        }
        $this->dispatch('toast', message: __('Activity updated to :status.', ['status' => __($to)]), type: 'success');
    }

    public function approve(): void
    {
        app(\App\Services\ActivityWorkflow::class)->approve(auth()->user(), $this->activity, $this->note);
        $this->reset('note');
        $this->refresh();
        if ($this->activity->status !== \App\Models\Activity::STATUS_APPROVED) {
            $this->dispatch('toast', message: __('Your signature is recorded. It moves on after the others on the list have signed.'), type: 'success');

            return;
        }
        $this->dispatch('toast', message: __('Activity approved successfully.'), type: 'success');
    }

    public function reject(): void
    {
        try {
            app(\App\Services\ActivityWorkflow::class)->sendBack(auth()->user(), $this->activity, $this->reason);
        } catch (\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }
        $this->reset('reason');
        $this->refresh();
        $this->dispatch('toast', message: __('Activity returned to department for correction.'), type: 'success');
    }

    /** After a restructuring: an administrator moves the activity (with its budget and indicators) to another unit, saying why. */
    public function moveToUnit(): void
    {
        abort_unless(auth()->user()->isAdministrator(), 403);
        $this->validate(['moveUnitId' => 'required|integer|exists:directorates,id', 'moveRemark' => 'required|string|min:3|max:500']);
        $to = \App\Models\Directorate::findOrFail((int) $this->moveUnitId);
        if ($to->id === $this->activity->directorate_id) {
            $this->addError('moveUnitId', __('The activity is already in that unit.'));

            return;
        }
        $from = $this->activity->directorate?->code;
        $this->activity->auditMetadata = ['by' => auth()->id(), 'moved' => true, 'from' => $from, 'to' => $to->code, 'remark' => trim($this->moveRemark)];
        $this->activity->update(['directorate_id' => $to->id]);
        $this->reset('moveUnitId', 'moveRemark');
        $this->refresh();
        $this->dispatch('toast', message: __('Activity moved to :unit.', ['unit' => $to->name]), type: 'success');
    }

    /** Mark the revision as needing an approval outside the institution, or not (an optional rule). */
    public function markExternal(bool $required): void
    {
        abort_unless(\App\Support\PlanOptions::current()->externalReference && \App\Services\ActivityVersions::revising($this->activity), 403);
        abort_unless(auth()->user()->isAdministrator() || auth()->user()->can('update', $this->activity), 403);
        $this->activity->auditMetadata = ['by' => auth()->id(), 'external_approval' => $required ? 'required' : 'not required'];
        $this->activity->update(['external_approval_required' => $required, 'external_approval_reference' => $required ? $this->activity->external_approval_reference : null]);
        $this->refresh();
    }

    /** Record the reference of the outside approval (by whoever gives the final approval, or an administrator). */
    public function saveExternalReference(): void
    {
        abort_unless(\App\Support\PlanOptions::current()->externalReference && $this->activity->external_approval_required, 403);
        abort_unless(auth()->user()->isAdministrator() || app(\App\Services\ActivityWorkflow::class)->isFinalApprover(auth()->user(), $this->activity), 403);
        $this->validate(['externalReference' => 'required|string|min:3|max:255']);
        $this->activity->auditMetadata = ['by' => auth()->id(), 'external_approval' => 'reference recorded'];
        $this->activity->update(['external_approval_reference' => trim($this->externalReference)]);
        $this->reset('externalReference');
        $this->refresh();
        $this->dispatch('toast', message: __('Reference of the outside approval recorded.'), type: 'success');
    }

    /** Start a revision: a new version, with its reason, through the whole approval chain again. */
    public function reopen(): void
    {
        try {
            app(\App\Services\ActivityWorkflow::class)->reopen(auth()->user(), $this->activity, $this->note);
        } catch (\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }
        $this->reset('note');
        $this->refresh();
        $this->dispatch('toast', message: __('Revision started. Version :n stays in force until the revision is approved.', ['n' => $this->activity->version]), type: 'success');
    }

    private function refresh(): void
    {
        $this->activity = $this->activity->fresh([
            'program.alignments', 'subprogram.alignments', 'directorate', 'indicators.actions',
            'lineItems.economicClassification', 'lineItems.fundingSource', 'lineItems.adjustedBudget',
            'approvedBy', 'reviewedBy',
        ]);
    }
}; ?>

@php
    $isEditable = $canEdit;

    $priorityLabel = match ($activity->priority) {
        'Alta', 'High'    => __('High'),
        'Média', 'Medium' => __('Medium'),
        default           => __('Low'),
    };

    $totalEstimated = $activity->lineItems->sum('estimated_amount');
    $totalApproved  = $activity->lineItems->sum(fn($i) => $i->adjustedBudget->approved_amount ?? 0);

@endphp

<div
    x-data="{ tab: '{{ $execution ? 'execution' : 'budget' }}', addingBudget: false, addingIndicator: false }"
    @indicator-added.window="addingIndicator = false"
    @lineitem-added.window="addingBudget = false"
    class="space-y-4"
>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 text-xs text-zinc-400">
        @if($activity->fiscal_year > $activeYear)
            <a href="{{ route('activities.create') }}" wire:navigate class="hover:text-zinc-600 transition-colors">{{ __('PAA Activities') }}</a>
        @else
            <a href="{{ route('activities.index', ['year' => $activity->fiscal_year]) }}" wire:navigate class="hover:text-zinc-600 transition-colors">{{ __('PAA Activities') }}</a>
        @endif
        <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
        <span class="font-semibold text-zinc-700">{{ $activity->code ?? 'ACT-'.$activity->fiscal_year.'-'.str_pad($activity->id, 2, '0', STR_PAD_LEFT) }}</span>
    </div>

    {{-- Two-column layout --}}
    <div class="grid grid-cols-1 lg:grid-cols-[1fr_288px] gap-5 items-start">

        {{-- ── LEFT COLUMN ─────────────────────────────────────────────── --}}
        <div class="space-y-4 min-w-0">

            {{-- Header card --}}
            <div class="bg-white rounded-xl ring-1 ring-zinc-950/5 shadow-sm p-6">
                <div class="flex items-center justify-between mb-4">
                    <span class="inline-flex items-center text-xs font-semibold text-indigo-700 bg-indigo-50 px-2.5 py-1 rounded-md ring-1 ring-indigo-200">
                        {{ $activity->program->oge_code }}@if($activity->subprogram)<span class="opacity-60">/</span>{{ $activity->subprogram->code }}@endif
                    </span>
                    <span class="text-xs font-semibold text-zinc-400">
                        {{ __('Fiscal Year') }} {{ $activity->fiscal_year }}
                    </span>
                </div>

                <h1 class="text-2xl font-bold text-zinc-900 leading-snug mb-3">
                    {{ $activity->description }}
                </h1>

                @if($activity->objective)
                    <p class="text-sm text-zinc-600 mb-4">{{ $activity->objective }}</p>
                @endif
                <div class="flex flex-wrap gap-2 mb-4">
                    @if($activity->location)<x-ui.badge variant="gray">{{ $activity->location === 'Nationwide' ? __('Nationwide') : $activity->location }}</x-ui.badge>@endif
                    <x-ui.badge variant="gray">{{ collect($activity->execution_quarters ?: [$activity->quarter])->map(fn ($q) => 'Q'.$q)->implode(' ') }}</x-ui.badge>
                    @if($activity->procurement_required)<x-ui.badge variant="gray">{{ __('Procurement') }}</x-ui.badge>@endif
                    @if($activity->gender_responsive)<x-ui.badge variant="indigo">{{ __('Gender-responsive') }}</x-ui.badge>@endif
                </div>

                <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-zinc-500 mb-5">
                    @if($activity->directorate)
                        <div class="flex items-center gap-1.5">
                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-zinc-300"><rect width="16" height="20" x="4" y="2" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/></svg>
                            {{ $activity->directorate->name }}
                        </div>
                    @endif
                    @if($activity->manager_name)
                        <div class="flex items-center gap-1.5">
                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-zinc-300"><circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 1 0-16 0"/></svg>
                            {{ $activity->manager_name }}
                        </div>
                    @endif
                </div>

                <div class="flex flex-wrap items-end gap-6 pt-4 border-t border-zinc-100">
                    <div>
                        <p class="text-xs font-semibold text-zinc-400 mb-0.5">{{ __('Priority') }}</p>
                        <p class="text-sm font-semibold text-zinc-800">{{ $priorityLabel }}</p>
                    </div>
                    @if($activity->planned_start || $activity->planned_end)
                        <div>
                            <p class="text-xs font-semibold text-zinc-400 mb-0.5">{{ __('Timeline') }}</p>
                            <p class="text-sm font-semibold text-zinc-800">
                                {{ $activity->planned_start ? \Carbon\Carbon::parse($activity->planned_start)->translatedFormat('M Y') : '—' }}
                                —
                                {{ $activity->planned_end ? \Carbon\Carbon::parse($activity->planned_end)->translatedFormat('M Y') : '—' }}
                            </p>
                        </div>
                    @endif
                    <div class="ml-auto">
                        <x-ui.status :status="$activity->status" />
                    </div>
                </div>
            </div>

            {{-- Tabs card --}}
            <div class="bg-white rounded-xl ring-1 ring-zinc-950/5 shadow-sm overflow-hidden">

                {{-- Tab navigation --}}
                <div class="flex border-b border-zinc-100 overflow-x-auto">
                    <button @click="tab = 'budget'"
                        class="flex-shrink-0 px-6 py-4 text-xs font-semibold tracking-wide border-b-2 transition-colors"
                        :class="tab === 'budget' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-zinc-400 hover:text-zinc-700'">
                        {{ __('Budget Line Items') }}
                        @if($activity->lineItems->isNotEmpty())
                            <span class="ml-1.5 text-xs font-bold bg-zinc-100 text-zinc-500 px-1.5 py-0.5 rounded-full">{{ $activity->lineItems->count() }}</span>
                        @endif
                    </button>
                    <button @click="tab = 'indicators'"
                        class="flex-shrink-0 px-6 py-4 text-xs font-semibold tracking-wide border-b-2 transition-colors"
                        :class="tab === 'indicators' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-zinc-400 hover:text-zinc-700'">
                        {{ __('Performance Indicators') }}
                        @if($activity->indicators->isNotEmpty())
                            <span class="ml-1.5 text-xs font-bold bg-zinc-100 text-zinc-500 px-1.5 py-0.5 rounded-full">{{ $activity->indicators->count() }}</span>
                        @endif
                    </button>
                    @if($execution)
                        <button @click="tab = 'execution'"
                            class="flex-shrink-0 px-6 py-4 text-xs font-semibold tracking-wide border-b-2 transition-colors"
                            :class="tab === 'execution' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-zinc-400 hover:text-zinc-700'">
                            {{ __('Execution') }}
                        </button>
                    @endif
                    <button @click="tab = 'history'"
                        class="flex-shrink-0 px-6 py-4 text-xs font-semibold tracking-wide border-b-2 transition-colors"
                        :class="tab === 'history' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-zinc-400 hover:text-zinc-700'">
                        {{ __('Workflow History') }}
                    </button>
                </div>

                {{-- ── BUDGET TAB ─────────────────────────────────────── --}}
                <div x-show="tab === 'budget'" x-transition>

                    {{-- Section heading + add button --}}
                    <div class="px-6 py-4 flex items-center justify-between">
                        <p class="text-sm font-semibold text-zinc-900">{{ __('Estimated Budget Items') }}</p>
                        @if($isEditable)
                            <button @click="addingBudget = !addingBudget"
                                class="inline-flex items-center gap-1 text-xs font-semibold transition-colors"
                                :class="addingBudget ? 'text-zinc-500' : 'text-indigo-600 hover:text-indigo-800'">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                                    :class="addingBudget ? 'rotate-45' : ''" class="transition-transform">
                                    <path d="M12 5v14"/><path d="M5 12h14"/>
                                </svg>
                                <span x-text="addingBudget ? '{{ __('Cancel') }}' : '{{ __('Add Item') }}'"></span>
                            </button>
                        @endif
                    </div>

                    {{-- Inline add form --}}
                    @if($isEditable)
                        <div x-show="addingBudget" x-transition class="overflow-hidden">
                            <form wire:submit="addLineItem"
                                class="px-6 pb-5 grid grid-cols-1 sm:grid-cols-[1fr_auto_auto_auto_auto] gap-3 items-end">
                                <div>
                                    <label class="block text-xs font-semibold text-zinc-400 mb-1">{{ __('Economic Classification') }}</label>
                                    <select wire:model="bClassId" class="flux-input w-full text-sm">
                                        <option value="">{{ __('Select...') }}</option>
                                        @foreach($economicClassifications as $ec)
                                            <option value="{{ $ec->id }}">{{ $ec->code }} – {{ $ec->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('bClassId') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                                </div>
                                <div class="sm:w-48">
                                    <label class="block text-xs font-semibold text-zinc-400 mb-1">{{ __('Funding source') }}</label>
                                    <select wire:model="bSourceId" class="flux-input w-full text-sm">
                                        @foreach(\App\Models\FundingSource::choices() as $fs)<option value="{{ $fs->id }}">{{ __($fs->name) }}</option>@endforeach
                                    </select>
                                </div>
                                <div class="sm:w-48">
                                    <label class="block text-xs font-semibold text-zinc-400 mb-1">{{ __('For which indicator') }}</label>
                                    <select wire:model="bIndicatorId" class="flux-input w-full text-sm">
                                        <option value="">{{ __('Whole activity') }}</option>
                                        @foreach($activity->indicators as $n => $ind)<option value="{{ $ind->id }}">{{ $n + 1 }}. {{ \Illuminate\Support\Str::limit($ind->name, 28) }}</option>@endforeach
                                    </select>
                                </div>
                                <div class="sm:w-44">
                                    <label class="block text-xs font-semibold text-zinc-400 mb-1">{{ __('Amount ($)') }}</label>
                                    <input type="number" step="0.01" wire:model="bAmount"
                                        class="flux-input w-full text-sm" placeholder="0.00">
                                    @error('bAmount') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                                </div>
                                <button type="submit"
                                    class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg transition-colors whitespace-nowrap">
                                    {{ __('Add Item') }}
                                </button>
                            </form>
                        </div>
                    @endif

                    {{-- Budget table --}}
                    @if($activity->lineItems->isEmpty())
                        <div class="px-6 py-12 text-center border-t border-zinc-50">
                            <p class="text-sm text-zinc-400">{{ __('No budget items defined yet.') }}</p>
                        </div>
                    @else
                        <div class="overflow-x-auto" tabindex="0">
                            <table class="w-full">
                                <thead>
                                    <tr class="border-t border-zinc-100">
                                        <th class="px-6 py-3 text-left text-xs font-semibold text-zinc-400">{{ __('Classification') }}</th>
                                        <th class="px-6 py-3 text-right text-xs font-semibold text-zinc-400">{{ __('Estimated Amount') }}</th>
                                        <th class="px-6 py-3 text-center text-xs font-semibold text-zinc-400">{{ __('Status') }}</th>
                                        @if($isEditable)
                                            <th class="px-6 py-3 w-10"></th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-50">
                                    @foreach($activity->lineItems as $item)
                                        <tr class="hover:bg-zinc-50/50 transition-colors group">
                                            <td class="px-6 py-4">
                                                <p class="text-sm font-semibold text-zinc-900">{{ $item->economicClassification->name }}</p>
                                                <p class="text-xs text-zinc-400 mt-0.5">{{ __('Account') }}: {{ $item->economicClassification->code }}@if($item->fundingSource) · {{ __('Funding') }}: {{ __($item->fundingSource->name) }}@endif @if($item->indicator_id) · {{ __('For') }}: {{ \Illuminate\Support\Str::limit($activity->indicators->firstWhere('id', $item->indicator_id)?->name, 40) }}@endif</p>
                                            </td>
                                            <td class="px-6 py-4 text-right font-mono text-sm font-semibold text-zinc-700">
                                                {{ number_format($item->estimated_amount, 2) }}
                                            </td>
                                            <td class="px-6 py-4 text-center">
                                                @if($item->adjustedBudget && $item->adjustedBudget->approved_amount)
                                                    <span class="text-xs font-bold tracking-wide px-2 py-1 rounded-full bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200">{{ __('Approved') }}</span>
                                                @else
                                                    <span class="text-xs font-bold tracking-wide px-2 py-1 rounded-full bg-zinc-100 text-zinc-500 ring-1 ring-zinc-200">{{ __('Planned') }}</span>
                                                @endif
                                            </td>
                                            @if($isEditable)
                                                <td class="px-4 py-4 text-center">
                                                    <button wire:click="deleteLineItem({{ $item->id }})"
                                                        wire:confirm="{{ __('Delete this budget item?') }}"
                                                        class="opacity-0 group-hover:opacity-100 p-1.5 text-zinc-300 hover:text-rose-500 hover:bg-rose-50 rounded-md transition-all">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                            <path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>
                                                        </svg>
                                                    </button>
                                                </td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="border-t-2 border-zinc-200 bg-zinc-50/50">
                                        <td class="px-6 py-4 text-xs font-bold tracking-wide text-zinc-700">{{ __('Total Estimated Budget') }}</td>
                                        <td class="px-6 py-4 text-right font-mono text-sm font-bold text-zinc-900">$ {{ number_format($totalEstimated, 2) }}</td>
                                        <td @if($isEditable) colspan="2" @endif class="px-6 py-4 text-center text-xs text-zinc-400">
                                            @if($totalApproved > 0)
                                                <span class="font-semibold text-emerald-700">{{ __('Approved') }}: $ {{ number_format($totalApproved, 2) }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    @endif
                </div>

                {{-- ── INDICATORS TAB ─────────────────────────────────── --}}
                <div x-show="tab === 'indicators'" x-transition>

                    {{-- Section heading + add button --}}
                    <div class="px-6 py-4 flex items-center justify-between">
                        <p class="text-sm font-semibold text-zinc-900">{{ __('Performance Indicators') }}</p>
                        @if($isEditable)
                            <button @click="addingIndicator = !addingIndicator"
                                class="inline-flex items-center gap-1 text-xs font-semibold transition-colors"
                                :class="addingIndicator ? 'text-zinc-500' : 'text-indigo-600 hover:text-indigo-800'">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                                    :class="addingIndicator ? 'rotate-45' : ''" class="transition-transform">
                                    <path d="M12 5v14"/><path d="M5 12h14"/>
                                </svg>
                                <span x-text="addingIndicator ? '{{ __('Cancel') }}' : '{{ __('Add Indicator') }}'"></span>
                            </button>
                        @endif
                    </div>

                    {{-- Inline add form --}}
                    @if($isEditable)
                        <div x-show="addingIndicator" x-transition class="overflow-hidden">
                            <form wire:submit="addIndicator"
                                class="px-6 pb-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
                                <div class="sm:col-span-2 lg:col-span-2">
                                    <label class="block text-xs font-semibold text-zinc-400 mb-1">{{ __('Indicator Name') }}</label>
                                    <input type="text" wire:model="iName"
                                        placeholder="{{ __('e.g. Km of road rehabilitated') }}"
                                        class="flux-input w-full text-sm">
                                    @error('iName') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-zinc-400 mb-1">{{ __('Type') }}</label>
                                    <select wire:model="iType" class="flux-input w-full text-sm">
                                        <option value="Output">Output</option>
                                        <option value="Outcome">Outcome</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-zinc-400 mb-1">{{ __('Unit') }}</label>
                                    <input type="text" wire:model="iUnit" placeholder="{{ __('km, units, %') }}"
                                        class="flux-input w-full text-sm">
                                    @error('iUnit') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                                </div>
                                <div class="sm:col-span-2 lg:col-span-3">
                                    <label class="block text-xs font-semibold text-zinc-400 mb-1">{{ __('Annual Target') }}</label>
                                    <input type="number" step="0.01" wire:model="iTarget"
                                        class="flux-input w-full text-sm" placeholder="0">
                                    @error('iTarget') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                                </div>
                                <div class="flex items-end">
                                    <button type="submit"
                                        class="w-full px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg transition-colors">
                                        {{ __('Add Indicator') }}
                                    </button>
                                </div>
                            </form>
                        </div>
                    @endif

                    {{-- Indicators table --}}
                    @if($activity->indicators->isEmpty())
                        <div class="px-6 py-12 text-center border-t border-zinc-50">
                            <p class="text-sm text-zinc-400">{{ __('No indicators defined yet.') }}</p>
                        </div>
                    @else
                        <div class="overflow-x-auto" tabindex="0">
                            <table class="w-full">
                                <thead>
                                    <tr class="border-t border-zinc-100">
                                        <th class="px-6 py-3 text-left text-xs font-semibold text-zinc-400">{{ __('Indicator') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-semibold text-zinc-400">{{ __('Type') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-semibold text-zinc-400">{{ __('Unit') }}</th>
                                        <th class="px-6 py-3 text-right text-xs font-semibold text-zinc-400">{{ __('Annual Target') }}</th>
                                        @if($isEditable)
                                            <th class="px-4 py-3 w-10"></th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-50">
                                    @foreach($activity->indicators as $indicator)
                                        <tr class="hover:bg-zinc-50/50 transition-colors group">
                                            <td class="px-6 py-4 text-sm font-medium text-zinc-900">{{ $indicator->name }}
                                                @if($indicator->baseline || $indicator->target_text)
                                                    <p class="mt-1 text-xs font-normal text-zinc-500">
                                                        @if($indicator->baseline){{ __('Starting point') }}: {{ $indicator->baseline }}@endif
                                                        @if($indicator->baseline && $indicator->target_text) · @endif
                                                        @if($indicator->target_text){{ __('Target') }}: {{ $indicator->target_text }}@endif
                                                    </p>
                                                @endif
                                                <p class="mt-1 text-xs font-normal text-zinc-500">
                                                    {{ $indicator->isLevel() ? __('Level') : __('Adds up') }}
                                                    @if($indicator->due_quarters) · {{ __('Reported in') }} {{ collect($indicator->dueQuarters())->map(fn ($q) => 'Q'.$q)->implode(', ') }}@endif
                                                    @if($indicator->hasMonthPlan()) · {{ __('Months') }}: {{ collect($indicator->plannedMonths())->map(fn ($m) => \Carbon\Carbon::create(2000, $m, 1)->translatedFormat('M'))->implode(', ') }}@endif
                                                </p>
                                                @if($indicator->means_of_verification)
                                                    <p class="mt-0.5 text-xs font-normal text-zinc-500">{{ __('Means of verification') }}: {{ $indicator->means_of_verification }}</p>
                                                @endif
                                                @if($indicator->actions->isNotEmpty())
                                                    <ol class="mt-0.5 list-decimal space-y-0.5 pl-4 text-xs font-normal text-zinc-500">
                                                        @foreach($indicator->actions as $action)<li>{{ $action->text }}@if($action->monthList()) <span class="text-zinc-400">({{ collect($action->monthList())->map(fn ($m) => \Carbon\Carbon::create(2000, $m, 1)->translatedFormat('M'))->implode(', ') }})</span>@endif</li>@endforeach
                                                    </ol>
                                                @endif
                                            </td>
                                            <td class="px-6 py-4">
                                                <span class="text-xs font-bold tracking-wide px-2 py-1 rounded-full ring-1
                                                    {{ $indicator->type === 'Output' ? 'bg-blue-50 text-blue-700 ring-blue-200' : 'bg-purple-50 text-purple-700 ring-purple-200' }}">
                                                    {{ $indicator->type }}
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-zinc-500">{{ $indicator->unit }}</td>
                                            <td class="px-6 py-4 text-right text-sm font-bold text-zinc-800">{{ number_format($indicator->annual_target, 0) }}</td>
                                            @if($isEditable)
                                                <td class="px-4 py-4 text-center">
                                                    <button wire:click="deleteIndicator({{ $indicator->id }})"
                                                        wire:confirm="{{ __('Delete this indicator?') }}"
                                                        class="opacity-0 group-hover:opacity-100 p-1.5 text-zinc-300 hover:text-rose-500 hover:bg-rose-50 rounded-md transition-all">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                            <path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>
                                                        </svg>
                                                    </button>
                                                </td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                {{-- ── EXECUTION TAB ──────────────────────────────────── --}}
                @if($execution)
                    <div x-show="tab === 'execution'" x-transition class="px-6 py-5 space-y-6">
                        <div class="grid gap-4 sm:grid-cols-4">
                            @foreach([[__('Approved'), $execution['approved']], [__('Committed'), $execution['committed']], [__('Paid'), $execution['paid']]] as [$label, $value])
                                <div><p class="text-xs font-semibold text-zinc-400">{{ $label }}</p><p class="mt-0.5 text-lg font-semibold tabular-nums text-zinc-900">$ {{ number_format($value, 2) }}</p></div>
                            @endforeach
                            <div><p class="text-xs font-semibold text-zinc-400">{{ __('Paid / approved') }}</p><p class="mt-0.5 text-lg font-semibold tabular-nums text-zinc-900">{{ $execution['rate'] === null ? '—' : number_format($execution['rate'], 1).'%' }}</p></div>
                        </div>
                        @if($execution['waiting'] > 0)
                            <p class="rounded-md bg-sky-50 px-3 py-2 text-sm text-sky-900">{{ trans_choice(':n spending figure is waiting for verification and is not counted yet.|:n spending figures are waiting for verification and are not counted yet.', $execution['waiting'], ['n' => $execution['waiting']]) }}</p>
                        @endif

                        <div class="overflow-x-auto" tabindex="0">
                            <table class="w-full text-sm">
                                <caption class="sr-only">{{ __('Month by month') }}</caption>
                                <thead><tr class="border-b border-zinc-100 text-xs font-semibold text-zinc-400">
                                    <th scope="col" class="py-2 pr-4 text-left">{{ __('Month') }}</th>
                                    <th scope="col" class="py-2 px-4 text-right">{{ __('Committed') }}</th>
                                    <th scope="col" class="py-2 px-4 text-right">{{ __('Paid') }}</th>
                                    <th scope="col" class="py-2 pl-4 text-right">{{ __('Paid so far') }}</th>
                                </tr></thead>
                                <tbody class="divide-y divide-zinc-50">
                                    @foreach($execution['months'] as $m => $row)
                                        @if($row['reported'])
                                            <tr>
                                                <td class="py-2 pr-4 text-zinc-700">{{ \Carbon\Carbon::create($activity->fiscal_year, $m, 1)->translatedFormat('F') }}</td>
                                                <td class="py-2 px-4 text-right tabular-nums">{{ number_format($row['committed'], 2) }}</td>
                                                <td class="py-2 px-4 text-right tabular-nums">{{ number_format($row['paid'], 2) }}</td>
                                                <td class="py-2 pl-4 text-right tabular-nums font-medium text-zinc-900">{{ number_format($row['cumulative'], 2) }}</td>
                                            </tr>
                                        @endif
                                    @endforeach
                                    @if(! collect($execution['months'])->contains('reported', true))
                                        <tr><td colspan="4" class="py-6 text-center text-sm text-zinc-400">{{ __('No verified spending yet.') }}</td></tr>
                                    @endif
                                </tbody>
                            </table>
                        </div>

                        @if($execution['indicators'])
                            <div>
                                <p class="mb-2 text-sm font-semibold text-zinc-900">{{ __('Indicators') }}</p>
                                <ul class="space-y-3">
                                    @foreach($execution['indicators'] as $i)
                                        <li>
                                            <div class="flex items-baseline justify-between gap-3 text-sm">
                                                <span class="min-w-0 text-zinc-700">{{ $i['name'] }}</span>
                                                <span class="flex-shrink-0 tabular-nums text-zinc-500"><span class="font-semibold text-zinc-900">{{ number_format($i['standing'], 2) }}</span> / {{ number_format($i['target'], 2) }} {{ $i['unit'] }}@if($i['pct'] !== null) · {{ number_format($i['pct'], 0) }}%@endif</span>
                                            </div>
                                            @if($i['pct'] !== null)
                                                <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-zinc-100" role="progressbar" aria-valuenow="{{ round($i['pct']) }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ $i['name'] }}"><div class="h-full rounded-full bg-indigo-600" style="width: {{ $i['pct'] }}%"></div></div>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if($execution['notes']->isNotEmpty())
                            <div>
                                <p class="mb-2 text-sm font-semibold text-zinc-900">{{ __('Latest reports') }}</p>
                                <ul class="space-y-2">
                                    @foreach($execution['notes'] as $n)
                                        <li class="rounded-md bg-zinc-50 px-3 py-2 text-sm text-zinc-700">
                                            <span class="font-medium text-zinc-900">{{ $n->period_type === 'month' ? \Carbon\Carbon::create($activity->fiscal_year, $n->period, 1)->translatedFormat('F') : 'Q'.$n->period }}</span>
                                            @if($n->rating) · {{ __($n->rating) }}@endif
                                            — {{ $n->results }}@if($n->constraints) <span class="text-zinc-500">({{ __('Constraints') }}: {{ $n->constraints }})</span>@endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @can('view_monitoring')
                            <a href="{{ route('monitoring.report') }}" wire:navigate class="inline-flex text-sm font-medium text-indigo-700 hover:underline">{{ __('Report execution') }} →</a>
                        @endcan
                    </div>
                @endif

                {{-- ── HISTORY TAB ────────────────────────────────────── --}}
                <div x-show="tab === 'history'" x-transition>
                    <div class="px-6 py-6">
                        <ol class="space-y-0" aria-label="{{ __('Approval trail') }}">
                            @foreach($trail as $step)
                                @php
                                    $tone = match ($step['kind']) {
                                        'returned' => 'bg-rose-600 ring-rose-600',
                                        'reopened' => 'bg-amber-600 ring-amber-600',
                                        'created'  => 'bg-zinc-500 ring-zinc-500',
                                        'signature' => 'bg-sky-600 ring-sky-600',
                                        default    => $step['status'] === \App\Models\Activity::STATUS_APPROVED ? 'bg-emerald-700 ring-emerald-700' : 'bg-indigo-600 ring-indigo-600',
                                    };
                                @endphp
                                <li class="flex gap-4 pb-6">
                                    <div class="flex flex-col items-center flex-shrink-0">
                                        <span class="flex h-7 w-7 items-center justify-center rounded-full text-white ring-2 {{ $tone }}" aria-hidden="true">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                                @if($step['kind'] === 'returned')<path d="M9 14 4 9l5-5"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/>@else<path d="M20 6 9 17l-5-5"/>@endif
                                            </svg>
                                        </span>
                                        <span class="mt-1 w-px flex-1 bg-zinc-200"></span>
                                    </div>
                                    <div class="min-w-0 pb-1">
                                        <p class="text-sm font-semibold text-zinc-900">{{ $step['label'] }}
                                            @if($step['bulk'])<span class="ml-1 rounded-full bg-zinc-100 px-2 py-0.5 text-[11px] font-medium text-zinc-600">{{ __('Approved in a batch') }}</span>@endif
                                        </p>
                                        <p class="mt-0.5 text-xs text-zinc-500">
                                            @if($step['at']){{ \Carbon\Carbon::parse($step['at'])->timezone('Asia/Dili')->format('d M Y, H:i') }} TLT @endif
                                            @if($step['by'])· <span class="font-medium text-zinc-700">{{ $step['by'] }}</span>@endif
                                        </p>
                                        @if($step['note'])
                                            <p class="mt-2 rounded-md px-3 py-2 text-sm {{ $step['is_reason'] ? 'bg-rose-50 text-rose-900' : 'bg-zinc-50 text-zinc-700' }}">
                                                <span class="font-medium">{{ $step['is_reason'] ? __('Reason') : __('Note') }}:</span> {{ $step['note'] }}
                                            </p>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                            @if($awaiting)
                                <li class="flex gap-4">
                                    <span class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full border-2 border-dashed border-zinc-300 bg-white" aria-hidden="true"></span>
                                    <div>
                                        <p class="text-sm font-semibold text-zinc-700">{{ __('Waiting for') }}</p>
                                        <p class="mt-0.5 text-sm text-zinc-600">{{ $awaiting }}</p>
                                    </div>
                                </li>
                            @endif
                        </ol>
                        @if($versions->isNotEmpty())
                            <h3 class="mt-6 text-sm font-semibold text-zinc-900">{{ __('Approved versions') }}</h3>
                            <ul class="mt-2 divide-y divide-zinc-100 rounded-lg ring-1 ring-zinc-200">
                                @foreach($versions as $v)
                                    <li class="px-4 py-3 text-sm" x-data="{ open: false }">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <p class="font-medium text-zinc-900">{{ __('Version :n', ['n' => $v->version]) }}
                                                @if($v->version === (int) $activity->version)<span class="ml-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-800">{{ __('In force') }}</span>@endif
                                            </p>
                                            <p class="text-xs text-zinc-500">{{ __('Approved by :name on :date', ['name' => $v->approver?->name ?? '—', 'date' => $v->approved_at?->timezone('Asia/Dili')->format('d M Y')]) }} · <span class="tabular-nums">${{ number_format((float) $v->total, 2) }}</span></p>
                                        </div>
                                        @if($v->reason)<p class="mt-1 text-xs text-zinc-600"><span class="font-medium">{{ __('Reason for the revision') }}:</span> {{ $v->reason }}</p>@endif
                                        <button type="button" class="mt-1 text-xs font-medium text-indigo-700 hover:underline" x-on:click="open = ! open" x-text="open ? @js(__('Hide')) : @js(__('Show what was approved'))"></button>
                                        <div x-show="open" x-cloak class="mt-2 grid gap-3 text-xs md:grid-cols-2">
                                            <div>
                                                <p class="font-semibold text-zinc-700">{{ __('Budget lines') }}</p>
                                                <ul class="mt-1 space-y-0.5">@foreach($v->snapshot['lines'] ?? [] as $l)<li class="flex justify-between gap-2"><span class="truncate">{{ $l['class_code'] ?? '' }} {{ $l['class_name'] ?? '' }}@if(! empty($l['source_name'])) · {{ $l['source_name'] }}@endif</span><span class="tabular-nums">${{ number_format((float) $l['estimated_amount'], 2) }}</span></li>@endforeach</ul>
                                            </div>
                                            <div>
                                                <p class="font-semibold text-zinc-700">{{ __('Indicators') }}</p>
                                                <ul class="mt-1 space-y-0.5">@foreach($v->snapshot['indicators'] ?? [] as $ind)<li class="flex justify-between gap-2"><span class="truncate">{{ $ind['name'] }}</span><span class="tabular-nums">{{ (float) $ind['annual_target'] }} {{ $ind['unit'] }}</span></li>@endforeach</ul>
                                            </div>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>

            </div>{{-- /tabs card --}}
        </div>{{-- /left column --}}

        {{-- ── RIGHT COLUMN ────────────────────────────────────────────── --}}
        <div class="space-y-4">

            {{-- Approval: where it stands, and why it came back if it did --}}
            <div class="bg-white rounded-xl ring-1 ring-zinc-950/5 shadow-sm p-5">
                <p class="text-xs font-bold text-zinc-500 mb-3">{{ __('Approval') }}</p>
                <x-ui.status :status="$activity->status" />
                @if($approval)
                    <p class="mt-3 text-sm text-zinc-700">
                        {{ __('Approved by :name', ['name' => $approval['by'] ?? '—']) }}
                        <span class="block text-xs text-zinc-500">{{ $approval['at'] ? \Carbon\Carbon::parse($approval['at'])->timezone('Asia/Dili')->format('d M Y, H:i') . ' TLT' : '' }}</span>
                    </p>
                    @if($approval['note'])<p class="mt-2 rounded-md bg-zinc-50 px-3 py-2 text-sm text-zinc-700">{{ $approval['note'] }}</p>@endif
                @elseif($awaiting)
                    <p class="mt-3 text-sm text-zinc-600"><span class="font-medium text-zinc-800">{{ __('Waiting for') }}:</span> {{ $awaiting }}</p>
                @endif
                @if($budgetRule && $activity->status !== \App\Models\Activity::STATUS_DRAFT)
                    <p class="mt-3 text-sm {{ $activity->budget_confirmed_at ? 'text-emerald-800' : 'text-zinc-600' }}">
                        <span class="font-medium">{{ __('Budget') }}:</span>
                        @if($activity->budget_confirmed_at)
                            {{ __('confirmed by :name', ['name' => $budgetBy ?? '—']) }}
                            <span class="block text-xs text-zinc-500">{{ $activity->budget_confirmed_at->timezone('Asia/Dili')->format('d M Y, H:i') }} TLT</span>
                            @if($activity->budget_justification)<span class="mt-1 block rounded-md bg-zinc-50 px-3 py-2 text-sm text-zinc-700">{{ $activity->budget_justification }}</span>@endif
                        @else
                            {{ __('not confirmed yet') }}
                        @endif
                    </p>
                @endif
                @php $lastReturn = $activity->status === \App\Models\Activity::STATUS_REJECTED ? collect($trail)->last(fn ($s) => $s['kind'] === 'returned') : null; @endphp
                @if($lastReturn && $lastReturn['note'])
                    <p class="mt-3 rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-900">
                        <span class="font-medium">{{ __('Reason') }}:</span> {{ $lastReturn['note'] }}
                        @if($lastReturn['by'])<span class="block text-xs text-rose-800">— {{ $lastReturn['by'] }}</span>@endif
                    </p>
                @endif
            </div>

            @if($revising)
                <div class="rounded-xl bg-amber-50 p-5 text-sm ring-1 ring-amber-200">
                    <p class="font-semibold text-amber-900">{{ __('Revision in progress: version :n', ['n' => $activity->version + 1]) }}</p>
                    <p class="mt-1 text-amber-900">{{ __('Version :n stays in force, and spending is reported against it, until this revision is approved.', ['n' => $activity->version]) }}</p>
                    @if($activity->revision_reason)<p class="mt-2 rounded-md bg-white/70 px-3 py-2 text-zinc-800"><span class="font-medium">{{ __('Reason') }}:</span> {{ $activity->revision_reason }}</p>@endif
                    @if($changes && ($changes['lines'] || $changes['indicators'] || abs($changes['total_from'] - $changes['total_to']) >= 0.005))
                        <p class="mt-3 text-xs font-semibold text-amber-900">{{ __('What changes against version :n', ['n' => $activity->version]) }}</p>
                        <ul class="mt-1 space-y-1 text-xs text-zinc-800">
                            @foreach($changes['lines'] as $c)
                                <li class="flex justify-between gap-2"><span class="min-w-0 break-words">{{ $c['label'] }}@if($c['source']) · {{ $c['source'] }}@endif</span>
                                    <span class="shrink-0 tabular-nums">{{ $c['from'] === null ? __('new') : '$'.number_format($c['from'], 2) }} → {{ $c['to'] === null ? __('removed') : '$'.number_format($c['to'], 2) }}</span></li>
                            @endforeach
                            @foreach($changes['indicators'] as $c)
                                <li class="flex justify-between gap-2"><span class="min-w-0 truncate">{{ $c['name'] }}</span><span class="shrink-0">{{ $c['change'] }}</span></li>
                            @endforeach
                            <li class="flex justify-between gap-2 border-t border-amber-200 pt-1 font-semibold"><span>{{ __('Total') }}</span><span class="tabular-nums">${{ number_format($changes['total_from'], 2) }} → ${{ number_format($changes['total_to'], 2) }}</span></li>
                        </ul>
                    @else
                        <p class="mt-2 text-xs text-amber-900">{{ __('Nothing has been changed yet.') }}</p>
                    @endif
                    @if($externalRule)
                        <div class="mt-3 border-t border-amber-200 pt-3 text-xs text-zinc-800">
                            @if($mayMarkExternal)
                                <label class="flex items-center gap-2"><input type="checkbox" @checked($activity->external_approval_required) wire:click="markExternal({{ $activity->external_approval_required ? 'false' : 'true' }})" class="rounded border-zinc-300 text-indigo-600"> {{ __('This revision needs an approval outside the institution') }}</label>
                            @elseif($activity->external_approval_required)
                                <p>{{ __('This revision needs an approval outside the institution') }}</p>
                            @endif
                            @if($activity->external_approval_required)
                                @if($activity->external_approval_reference)
                                    <p class="mt-1"><span class="font-medium">{{ __('Reference of the outside approval') }}:</span> {{ $activity->external_approval_reference }}</p>
                                @else
                                    <p class="mt-1 font-medium text-amber-900">{{ __('It can be approved once the reference of the outside approval is recorded.') }}</p>
                                    @if($mayRecordExternal)
                                        <div class="mt-2 flex gap-2">
                                            <input type="text" wire:model="externalReference" class="flux-input text-sm" placeholder="{{ __('Reference of the outside approval') }}" aria-label="{{ __('Reference of the outside approval') }}">
                                            <x-ui.button type="button" size="sm" variant="secondary" wire:click="saveExternalReference">{{ __('Record') }}</x-ui.button>
                                        </div>
                                        @error('externalReference')<p class="field-error">{{ $message }}</p>@enderror
                                    @endif
                                @endif
                            @endif
                        </div>
                    @endif
                </div>
            @endif

            @if(auth()->user()->isAdministrator() && ! $activity->isLocked())
                <details class="bg-white rounded-xl ring-1 ring-zinc-950/5 shadow-sm p-5 text-sm">
                    <summary class="cursor-pointer text-xs font-bold text-zinc-500">{{ __('Move to another unit') }}</summary>
                    <p class="mt-2 text-xs text-zinc-500">{{ __('For a restructuring. The budget and indicators go with it; whoever approves for the new unit takes over from here. It is recorded with your remark.') }}</p>
                    <div class="mt-3 space-y-2">
                        <select wire:model="moveUnitId" class="flux-input w-full text-sm" aria-label="{{ __('Unit') }}">
                            <option value="">{{ __('Select...') }}</option>
                            @foreach(\App\Models\Directorate::orderBy('code')->get(['id', 'code', 'name']) as $u)<option value="{{ $u->id }}">{{ $u->code }} — {{ \Illuminate\Support\Str::limit($u->name, 34) }}</option>@endforeach
                        </select>
                        @error('moveUnitId')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                        <textarea wire:model="moveRemark" rows="2" class="flux-input w-full text-sm" placeholder="{{ __('Why it is moved') }}" aria-label="{{ __('Why it is moved') }}"></textarea>
                        @error('moveRemark')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                        <x-ui.button type="button" variant="secondary" size="sm" wire:click="moveToUnit" wire:confirm="{{ __('Move this activity to the chosen unit?') }}">{{ __('Move') }}</x-ui.button>
                    </div>
                </details>
            @endif

            {{-- Governance Actions --}}
            <div class="bg-white rounded-xl ring-1 ring-zinc-950/5 shadow-sm p-5">
                <div class="flex items-center justify-between mb-4">
                    <p class="text-xs font-bold text-zinc-500">{{ __('Governance Actions') }}</p>
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-zinc-300"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                </div>

                <div class="space-y-2">
                    {{-- the final approval has its own button below (with a note for the record), so it is not offered twice --}}
                    @if($nextStatus && $nextStatus !== App\Models\Activity::STATUS_APPROVED)
                        @if($nextStatus === App\Models\Activity::STATUS_SUBMITTED && $problems)
                            <div class="rounded-lg bg-amber-50 ring-1 ring-amber-200 p-3 text-xs text-amber-800">
                                <p class="font-semibold mb-1">{{ __('Not ready to submit') }}</p>
                                <ul class="list-disc pl-4 space-y-0.5">@foreach($problems as $p)<li>{{ $p }}</li>@endforeach</ul>
                            </div>
                        @else
                            <x-ui.button wire:click="submit" class="w-full"
                                wire:confirm="{{ __('Move this activity to :status?', ['status' => __($nextStatus)]) }}">
                                {{ match($nextStatus) {
                                    App\Models\Activity::STATUS_SUBMITTED => __('Submit for Review'),
                                    App\Models\Activity::STATUS_REVIEWED => __('Mark as Reviewed'),
                                    default => __('Validate'),
                                } }}
                            </x-ui.button>
                        @endif
                    @endif

                    @if($canApprove)
                        <x-ui.button class="w-full" x-on:click="$dispatch('open-modal', 'approve-activity')">{{ __('Approve Activity') }}</x-ui.button>
                        <x-note-dialog name="approve-activity" property="note" action="approve" :title="__('Approve this activity for the annual plan?')"
                            :label="__('Note for the record')" :confirm="__('Approve Activity')" />
                    @endif
                    @if($canReturn)
                        <x-ui.button variant="secondary" class="w-full" x-on:click="$dispatch('open-modal', 'return-activity')">{{ __('Return for correction') }}</x-ui.button>
                        <x-note-dialog name="return-activity" property="reason" action="reject" :title="__('Return this activity to the department for corrections?')"
                            :message="__('The unit will see your reason, so say what should be corrected.')"
                            :label="__('What should be corrected?')" :confirm="__('Return for correction')" :required="true" />
                    @endif
                    @if($canReopen)
                        <x-ui.button variant="secondary" class="w-full" x-on:click="$dispatch('open-modal', 'reopen-activity')">{{ __('Revise') }}</x-ui.button>
                        <x-note-dialog name="reopen-activity" property="note" action="reopen" :required="true"
                            :title="__('Start a revision of this activity?')"
                            :message="__('It becomes a draft that the unit can change, and goes through preparation, certification and approval again. Version :n stays in force until the revision is approved.', ['n' => $activity->version])"
                            :label="__('Why is it revised?')" :confirm="__('Revise')" />
                    @endif
                    @if($canEdit)
                        <a href="{{ route('activities.edit', $activity) }}" wire:navigate class="block text-center text-sm font-medium text-indigo-600 hover:text-indigo-700 pt-1">{{ __('Edit details') }}</a>
                    @endif

                    {{-- Lock indicator --}}
                    @if(!$isEditable && $activity->status === App\Models\Activity::STATUS_APPROVED)
                        <div class="flex items-center gap-2 px-3 py-2.5 bg-zinc-50 rounded-lg border border-zinc-100">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="text-zinc-400"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            <span class="text-xs font-medium text-zinc-400">{{ __('Approved — read only') }}</span>
                        </div>
                    @elseif(!$isEditable)
                        <div class="flex items-center gap-2 px-3 py-2.5 bg-zinc-50 rounded-lg border border-zinc-100">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="text-zinc-400"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            <span class="text-xs font-medium text-zinc-400">{{ __('Locked for editing') }}</span>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Programmatic Logic (dark card) --}}
            <div class="bg-indigo-950 rounded-xl p-5 overflow-hidden relative">
                {{-- Background decoration --}}
                <div class="absolute -right-6 -bottom-6 w-32 h-32 rounded-full bg-indigo-900/60 pointer-events-none"></div>
                <div class="absolute -right-2 -top-6 w-20 h-20 rounded-full bg-indigo-900/30 pointer-events-none"></div>

                <p class="text-xs font-bold text-indigo-200 mb-5 relative">{{ __('Programmatic Logic') }}</p>

                <div class="space-y-5 relative">
                    <div class="flex items-start gap-3">
                        <div class="w-7 h-7 rounded-full bg-indigo-800 text-indigo-200 text-xs font-bold flex items-center justify-center flex-shrink-0">1</div>
                        <div>
                            <p class="text-xs font-semibold text-indigo-200">{{ __('OGE Program') }}</p>
                            <p class="text-sm font-semibold text-white mt-0.5 leading-snug">{{ $activity->program->name }}</p>
                            @if($activity->subprogram)<p class="text-xs text-indigo-200 mt-1">{{ __('Subprogram') }} {{ $activity->subprogram->code }} · {{ $activity->subprogram->name }}</p>@endif
                            @php $aligned = $activity->program->alignments->concat($activity->subprogram?->alignments ?? collect()); @endphp
                            @if($aligned->isNotEmpty())
                                <p class="mt-2 text-xs font-semibold text-indigo-300">{{ __('Contributes to') }}</p>
                                <ul class="mt-1 space-y-0.5">
                                    @foreach($aligned as $al)
                                        <li class="text-xs text-indigo-100"><span class="font-semibold">{{ $al->reference }}</span>@if($al->description) · {{ $al->description }}@endif</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <div class="w-7 h-7 rounded-full bg-indigo-800 text-indigo-200 text-xs font-bold flex items-center justify-center flex-shrink-0">2</div>
                        <div>
                            <p class="text-xs font-semibold text-indigo-200">{{ __('Executing Entity') }}</p>
                            <p class="text-sm font-semibold text-white mt-0.5 leading-snug">{{ $activity->directorate->name ?? __('N/A') }}</p>
                        </div>
                    </div>
                    @if($activity->quarter)
                        <div class="flex items-start gap-3">
                            <div class="w-7 h-7 rounded-full bg-indigo-800 text-indigo-200 text-xs font-bold flex items-center justify-center flex-shrink-0">3</div>
                            <div>
                                <p class="text-xs font-semibold text-indigo-200">{{ __('Quarter') }}</p>
                                <p class="text-sm font-semibold text-white mt-0.5">{{ collect($activity->execution_quarters ?: [$activity->quarter])->map(fn ($q) => 'Q'.$q)->implode(' ') }}</p>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Activity Summary --}}
            <div class="bg-white rounded-xl ring-1 ring-zinc-950/5 shadow-sm p-5">
                <p class="text-xs font-bold text-zinc-500 mb-4">{{ __('Activity Summary') }}</p>

                <div class="space-y-4">
                    <div>
                        <p class="text-xs font-semibold text-zinc-400 mb-1.5">{{ __('Procurement') }}</p>
                        @if($activity->procurement_required)
                            <div class="flex items-center gap-1.5 text-sm font-semibold text-emerald-600">
                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                {{ __('Required') }}
                            </div>
                        @else
                            <p class="text-sm text-zinc-400">{{ __('Not required') }}</p>
                        @endif
                    </div>

                    <div>
                        <p class="text-xs font-semibold text-zinc-400 mb-1.5">{{ __('Budget') }}</p>
                        <p class="text-sm font-bold text-zinc-900 font-mono">$ {{ number_format($totalEstimated, 0) }}</p>
                        <p class="text-xs text-zinc-400 mt-0.5">{{ $activity->lineItems->count() }} {{ __('line items') }}</p>
                    </div>

                    <div>
                        <p class="text-xs font-semibold text-zinc-400 mb-1.5">{{ __('Indicators') }}</p>
                        <p class="text-sm font-bold text-zinc-900">{{ $activity->indicators->count() }}</p>
                        @if($activity->indicators->isNotEmpty())
                            <p class="text-xs text-zinc-400 mt-0.5">
                                {{ $activity->indicators->where('type','Output')->count() }} Output ·
                                {{ $activity->indicators->where('type','Outcome')->count() }} Outcome
                            </p>
                        @endif
                    </div>

                    @if($activity->approvedBy)
                        <div>
                            <p class="text-xs font-semibold text-zinc-400 mb-1.5">{{ __('Approved by') }}</p>
                            <div class="flex items-center gap-2">
                                <div class="w-6 h-6 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-700 text-xs font-bold flex-shrink-0">
                                    {{ strtoupper(substr($activity->approvedBy->name, 0, 1)) }}
                                </div>
                                <div>
                                    <p class="text-xs font-semibold text-zinc-800">{{ $activity->approvedBy->name }}</p>
                                    @if($activity->approved_at)
                                        <p class="text-xs text-zinc-400">{{ \Carbon\Carbon::parse($activity->approved_at)->format('d/m/Y') }}</p>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

        </div>{{-- /right column --}}

    </div>{{-- /grid --}}
</div>
