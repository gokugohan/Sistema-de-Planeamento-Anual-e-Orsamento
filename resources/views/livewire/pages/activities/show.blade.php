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

    public function with(): array
    {
        $workflow = app(\App\Services\ActivityWorkflow::class);
        $user = auth()->user();

        return [
            'activeYear'              => \App\Models\FiscalEnvelope::getEffectiveYear(),
            'economicClassifications' => EconomicClassification::orderBy('code')->get(),
            'canEdit'                 => $user->can('update', $this->activity),
            'canDelete'               => $user->can('delete', $this->activity),
            'nextStatus'              => $workflow->next($user, $this->activity),
            'canReturn'               => $workflow->canReturn($user, $this->activity),
            'canApprove'              => $workflow->canApprove($user, $this->activity),
            'canReopen'               => $workflow->canReopen($user, $this->activity),
            'problems'                => $workflow->problems($this->activity),
        ];
    }

    public function mount(Activity $activity): void
    {
        $this->authorize('view', $activity);

        $this->activity = $activity->load([
            'program', 'directorate', 'indicators',
            'lineItems.economicClassification', 'lineItems.adjustedBudget',
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
            'bAmount'  => 'required|numeric|min:0.01|max:999999999999',
        ]);

        $existing = LineItem::where('activity_id', $this->activity->id)
            ->where('economic_classification_id', $this->bClassId)
            ->first();

        if ($existing) {
            $existing->increment('estimated_amount', $this->bAmount);
        } else {
            LineItem::create([
                'activity_id'                => $this->activity->id,
                'economic_classification_id' => $this->bClassId,
                'estimated_amount'           => $this->bAmount,
            ]);
        }

        $this->reset(['bClassId', 'bAmount']);
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
        try {
            $to = app(\App\Services\ActivityWorkflow::class)->advance(auth()->user(), $this->activity);
        } catch (\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');
            return;
        }
        $this->refresh();
        $this->dispatch('toast', message: __('Activity updated to :status.', ['status' => __($to)]), type: 'success');
    }

    public function approve(): void
    {
        app(\App\Services\ActivityWorkflow::class)->approve(auth()->user(), $this->activity);
        $this->refresh();
        $this->dispatch('toast', message: __('Activity approved successfully.'), type: 'success');
    }

    public function reject(): void
    {
        app(\App\Services\ActivityWorkflow::class)->sendBack(auth()->user(), $this->activity);
        $this->refresh();
        $this->dispatch('toast', message: __('Activity returned to department for correction.'), type: 'success');
    }

    public function reopen(): void
    {
        app(\App\Services\ActivityWorkflow::class)->reopen(auth()->user(), $this->activity);
        $this->refresh();
        $this->dispatch('toast', message: __('Activity returned to draft.'), type: 'success');
    }

    private function refresh(): void
    {
        $this->activity = $this->activity->fresh([
            'program', 'directorate', 'indicators',
            'lineItems.economicClassification', 'lineItems.adjustedBudget',
            'approvedBy', 'reviewedBy',
        ]);
    }
}; ?>

@php
    $isEditable = $canEdit;

    $statusBadge = match($activity->status) {
        'Approved'  => 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200',
        'Submitted', 'Reviewed', 'Validated' => 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200',
        'Rejected'  => 'bg-rose-50 text-rose-700 ring-1 ring-rose-200',
        default     => 'bg-zinc-100 text-zinc-600 ring-1 ring-zinc-200',
    };
    $statusLabel = match($activity->status) {
        'Approved'  => __('Approved'),
        'Submitted' => __('Submitted'),
        'Reviewed'  => __('Reviewed'),
        'Validated' => __('Validated'),
        'Rejected'  => __('Rejected'),
        default     => __('Draft'),
    };

    $priorityLabel = match ($activity->priority) {
        'Alta', 'High'    => __('High'),
        'Média', 'Medium' => __('Medium'),
        default           => __('Low'),
    };

    $totalEstimated = $activity->lineItems->sum('estimated_amount');
    $totalApproved  = $activity->lineItems->sum(fn($i) => $i->adjustedBudget->approved_amount ?? 0);

    // Build workflow timeline
    $timeline = [];
    $timeline[] = ['label' => __('Created'),   'date' => $activity->created_at,   'user' => null,                         'done' => true];
    $timeline[] = ['label' => __('Submitted'),  'date' => $activity->submitted_at, 'user' => null,                         'done' => !!$activity->submitted_at];
    $timeline[] = ['label' => __('Reviewed'),   'date' => $activity->reviewed_at,  'user' => $activity->reviewedBy?->name, 'done' => !!$activity->reviewed_at];
    $timeline[] = ['label' => __('Approved'),   'date' => $activity->approved_at,  'user' => $activity->approvedBy?->name, 'done' => !!$activity->approved_at];
@endphp

<div
    x-data="{ tab: 'budget', addingBudget: false, addingIndicator: false }"
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
                        {{ $activity->program->oge_code }}
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
                        <span class="text-xs font-semibold px-3 py-1.5 rounded-full tracking-wide {{ $statusBadge }}">
                            {{ $statusLabel }}
                        </span>
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
                                class="px-6 pb-5 grid grid-cols-1 sm:grid-cols-[1fr_auto_auto] gap-3 items-end">
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
                        <div class="overflow-x-auto">
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
                                                <p class="text-xs text-zinc-400 mt-0.5">{{ __('Account') }}: {{ $item->economicClassification->code }}</p>
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
                        <div class="overflow-x-auto">
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

                {{-- ── HISTORY TAB ────────────────────────────────────── --}}
                <div x-show="tab === 'history'" x-transition>
                    <div class="px-6 py-6 space-y-0">
                        @foreach($timeline as $i => $step)
                            <div class="flex gap-4 {{ !$loop->last ? 'pb-6' : '' }}">
                                {{-- Line + dot --}}
                                <div class="flex flex-col items-center flex-shrink-0">
                                    <div class="w-7 h-7 rounded-full flex items-center justify-center ring-2 flex-shrink-0
                                        {{ $step['done'] ? 'bg-indigo-600 ring-indigo-600 text-white' : 'bg-white ring-zinc-200 text-zinc-300' }}">
                                        @if($step['done'])
                                            <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                        @else
                                            <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"/></svg>
                                        @endif
                                    </div>
                                    @if(!$loop->last)
                                        <div class="w-px flex-1 mt-1 {{ $step['done'] ? 'bg-indigo-200' : 'bg-zinc-100' }}"></div>
                                    @endif
                                </div>
                                {{-- Content --}}
                                <div class="pb-1">
                                    <p class="text-sm font-semibold {{ $step['done'] ? 'text-zinc-900' : 'text-zinc-400' }}">{{ $step['label'] }}</p>
                                    @if($step['date'])
                                        <p class="text-xs text-zinc-400 mt-0.5">
                                            {{ \Carbon\Carbon::parse($step['date'])->format('d M Y, H:i') }}
                                            @if($step['user'])
                                                · <span class="font-medium text-zinc-500">{{ $step['user'] }}</span>
                                            @endif
                                        </p>
                                    @else
                                        <p class="text-xs text-zinc-300 mt-0.5">{{ __('Pending') }}</p>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

            </div>{{-- /tabs card --}}
        </div>{{-- /left column --}}

        {{-- ── RIGHT COLUMN ────────────────────────────────────────────── --}}
        <div class="space-y-4">

            {{-- Governance Actions --}}
            <div class="bg-white rounded-xl ring-1 ring-zinc-950/5 shadow-sm p-5">
                <div class="flex items-center justify-between mb-4">
                    <p class="text-xs font-bold text-zinc-500">{{ __('Governance Actions') }}</p>
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-zinc-300"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                </div>

                <div class="space-y-2">
                    @if($nextStatus)
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
                                    App\Models\Activity::STATUS_VALIDATED => __('Validate'),
                                    default => __('Send to the Minister'),
                                } }}
                            </x-ui.button>
                        @endif
                    @endif

                    @if($canApprove)
                        <x-ui.button wire:click="approve" class="w-full"
                            wire:confirm="{{ __('Approve this activity for the annual plan?') }}">{{ __('Approve Activity') }}</x-ui.button>
                    @endif
                    @if($canReturn)
                        <x-ui.button variant="secondary" wire:click="reject" class="w-full"
                            wire:confirm="{{ __('Return this activity to the department for corrections?') }}">{{ __('Return for correction') }}</x-ui.button>
                    @endif
                    @if($canReopen)
                        <x-ui.button variant="secondary" wire:click="reopen" class="w-full"
                            wire:confirm="{{ __('Return this activity to draft? This will allow editing again.') }}">{{ __('Reopen') }}</x-ui.button>
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

                <p class="text-xs font-bold text-indigo-400 mb-5 relative">{{ __('Programmatic Logic') }}</p>

                <div class="space-y-5 relative">
                    <div class="flex items-start gap-3">
                        <div class="w-7 h-7 rounded-full bg-indigo-800 text-indigo-200 text-xs font-bold flex items-center justify-center flex-shrink-0">1</div>
                        <div>
                            <p class="text-xs font-semibold text-indigo-400">{{ __('OGE Program') }}</p>
                            <p class="text-sm font-semibold text-white mt-0.5 leading-snug">{{ $activity->program->name }}</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <div class="w-7 h-7 rounded-full bg-indigo-800 text-indigo-200 text-xs font-bold flex items-center justify-center flex-shrink-0">2</div>
                        <div>
                            <p class="text-xs font-semibold text-indigo-400">{{ __('Executing Entity') }}</p>
                            <p class="text-sm font-semibold text-white mt-0.5 leading-snug">{{ $activity->directorate->name ?? __('N/A') }}</p>
                        </div>
                    </div>
                    @if($activity->quarter)
                        <div class="flex items-start gap-3">
                            <div class="w-7 h-7 rounded-full bg-indigo-800 text-indigo-200 text-xs font-bold flex items-center justify-center flex-shrink-0">3</div>
                            <div>
                                <p class="text-xs font-semibold text-indigo-400">{{ __('Quarter') }}</p>
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
