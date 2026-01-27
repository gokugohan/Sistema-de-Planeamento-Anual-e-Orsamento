<?php

use Livewire\Volt\Component;
use App\Models\Activity;

new class extends Component {
    public Activity $activity;

    public function with(): array
    {
        return [
            'activeYear' => \App\Models\FiscalEnvelope::getEffectiveYear(),
        ];
    }

    public function mount(Activity $activity): void
    {
        $this->activity = $activity->load(['program', 'directorate', 'indicators', 'lineItems.economicClassification', 'lineItems.adjustedBudget']);
    }

}; ?>

<div class="space-y-8 animate-in fade-in duration-500">
    <div class="flex justify-between items-center">
        <h2 class="text-3xl font-black text-gray-900 tracking-tight">
            {{ __('Activity Details') }}
        </h2>

        @if($activity->fiscal_year > $activeYear)
            <x-ui.button variant="ghost" size="sm" href="{{ route('activities.create') }}" wire:navigate>
                &larr; {{ __('Back to Planning') }}
            </x-ui.button>
        @else
            <x-ui.button variant="ghost" size="sm"
                href="{{ route('activities.index', ['year' => $activity->fiscal_year]) }}" wire:navigate>
                &larr; {{ __('Back to List') }}
            </x-ui.button>
        @endif
    </div>

    @php
        $isEditable = in_array($activity->status, [App\Models\Activity::STATUS_DRAFT, App\Models\Activity::STATUS_REJECTED]);

        $statusAlert = match ($activity->status) {
            'Submitted' => [
                'type' => 'indigo',
                'title' => __('Awaiting Directorate Review'),
                'message' => __('This activity has been submitted to the National Directorate. Editing is restricted until a decision is made.'),
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>'
            ],
            'Reviewed' => [
                'type' => 'amber',
                'title' => __('Technical Validation Complete'),
                'message' => __('Pending General Directorate oversight. Final budget numbers are being verified.'),
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/><path d="m9 12 2 2 4-4"/></svg>'
            ],
            'Validated' => [
                'type' => 'indigo',
                'title' => __('Final Ministry Queue'),
                'message' => __('Activity has been validated and is awaiting final Ministerial approval.'),
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>'
            ],
            'Approved' => [
                'type' => 'emerald',
                'title' => __('PAA Strategy Approved'),
                'message' => __('This activity is officially part of the annual plan. No further technical changes allowed.'),
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/><path d="m9 12 2 2 4-4"/></svg>'
            ],
            default => null
        };
    @endphp

    @if($statusAlert)
        <div
            class="p-6 bg-{{ $statusAlert['type'] }}-50 border border-{{ $statusAlert['type'] }}-100 rounded-[2rem] flex items-start gap-5 shadow-sm shadow-{{ $statusAlert['type'] }}-500/5 animate-in slide-in-from-top duration-700">
            <div
                class="h-12 w-12 rounded-2xl bg-white border border-{{ $statusAlert['type'] }}-100 flex items-center justify-center text-{{ $statusAlert['type'] }}-600 shrink-0 shadow-sm">
                {!! $statusAlert['icon'] !!}
            </div>
            <div>
                <h4 class="text-sm font-black text-{{ $statusAlert['type'] }}-900 uppercase tracking-tight">
                    {{ $statusAlert['title'] }}
                </h4>
                <p class="text-xs text-{{ $statusAlert['type'] }}-700 font-medium leading-relaxed mt-1 opacity-80">
                    {{ $statusAlert['message'] }}
                </p>
            </div>
        </div>
    @endif
    <!-- Activity Info Card -->
    <x-ui.card class="border-l-4 border-l-indigo-500">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
            <div>
                <h3 class="text-[10px] uppercase text-gray-400 font-black tracking-widest mb-2">
                    {{ __('OGE Program') }}
                </h3>
                <div class="flex items-center gap-2">
                    <x-ui.badge variant="indigo">{{ $activity->program->oge_code }}</x-ui.badge>
                    <p class="text-lg font-black text-gray-900">{{ $activity->program->name }}</p>
                </div>
            </div>
            <div>
                <h3 class="text-[10px] uppercase text-gray-400 font-black tracking-widest mb-2">
                    {{ __('Responsible Directorate') }}
                </h3>
                <p
                    class="text-lg font-black text-gray-900 italic underline decoration-indigo-500 decoration-2 underline-offset-4">
                    {{ $activity->directorate->name ?? 'N/A' }}
                </p>
            </div>
            <div class="{{ ($activity->approvedBy == null) ? 'md:col-span-2' : '' }}">
                <h3 class="text-[10px] uppercase text-gray-400 font-black tracking-widest mb-2">
                    {{ __('Activity Description') }}
                </h3>
                <p class="text-xl font-bold text-gray-700 leading-relaxed">{{ $activity->description }}</p>
            </div>

            @if($activity->approvedBy)
                <div>
                    <h3 class="text-[10px] uppercase text-gray-400 font-black tracking-widest mb-2">
                        {{ __('Approved by') }}
                    </h3>
                    <x-ui.badge variant="indigo" size="lg">{{ $activity->approvedBy?->name }}</x-ui.badge>
                </div>
            @endif
            <div>
                <h3 class="text-[10px] uppercase text-gray-400 font-black tracking-widest mb-2">
                    {{ __('Priority') }}
                </h3>
                @php
                    $priorityVariant = match ($activity->priority) {
                        'Alta' => 'red',
                        'Média' => 'yellow',
                        default => 'green',
                    };
                    $priorityLabel = match ($activity->priority) {
                        'Alta' => __('High'),
                        'Média' => __('Medium'),
                        default => __('Low'),
                    };
                @endphp
                <x-ui.badge :variant="$priorityVariant" size="lg">{{ $priorityLabel }}</x-ui.badge>
            </div>

            <div>
                <h3 class="text-[10px] uppercase text-gray-400 font-black tracking-widest mb-2">
                    {{ __('Fiscal Year') }}
                </h3>
                <x-ui.badge variant="black">{{ $activity->fiscal_year }}</x-ui.badge>
            </div>
        </div>
    </x-ui.card>

    <!-- Indicators Section -->
    <x-ui.card padding="p-0">
        <x-slot name="header">
            <div class="flex justify-between items-center w-full">
                <h3 class="text-lg font-black text-gray-900 uppercase tracking-tight">
                    {{ __('Indicators and Goals') }}
                </h3>
                <x-ui.badge variant="blue">Total: {{ $activity->indicators->count() }}</x-ui.badge>
            </div>
        </x-slot>
        <x-ui.table :headers="[__('Indicator'), __('Unit'), __('Annual Target')]">
            <x-slot name="actions"></x-slot>
            @forelse($activity->indicators as $indicator)
                <tr class="hover:bg-gray-50/50 transition-colors group">
                    <td class="px-8 py-5 text-sm text-gray-900 font-bold">{{ $indicator->name }}</td>
                    <td class="px-8 py-5 text-sm text-gray-500 italic">{{ $indicator->unit }}</td>
                    <td class="px-8 py-5 text-rights text-lg font-black text-indigo-600">
                        {{ $indicator->annual_target }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="px-8 py-10 text-center text-sm text-gray-400 italic">
                        {{ __('No indicators defined.') }}
                    </td>
                </tr>
            @endforelse
        </x-ui.table>
    </x-ui.card>

    <!-- Budget Section -->
    <x-ui.card padding="p-0">
        <x-slot name="header">
            <div class="flex justify-between items-center w-full">
                <h3 class="text-lg font-black text-gray-900 uppercase tracking-tight">{{ __('Budget and Items') }}
                </h3>
                <div class="flex gap-2">
                    <x-ui.badge variant="indigo">{{ __('Items') }}: {{ $activity->lineItems->count() }}</x-ui.badge>
                    <x-ui.badge variant="green">Total: $
                        {{ number_format($activity->lineItems->sum('estimated_amount'), 2) }}</x-ui.badge>
                </div>
            </div>
        </x-slot>
        <x-ui.table :headers="[__('Econ. Code'), 'Rubrica (Line Item)', __('Estimated'), __('Approved')]">
            <x-slot name="actions"></x-slot>
            @forelse($activity->lineItems as $item)
                <tr class="hover:bg-gray-50/50 transition-colors group">
                    <td class="px-8 py-5 text-sm font-black text-gray-900 font-mono">
                        {{ $item->economicClassification->code }}
                    </td>
                    <td class="px-8 py-5 text-sm text-gray-900 font-bold">{{ $item->economicClassification->name }}</td>
                    <td class="px-8 py-5 text-rights text-sm font-mono font-bold text-gray-500">$
                        {{ number_format($item->estimated_amount, 2) }}
                    </td>
                    <td class="px-8 py-5 text-rights text-sm font-mono font-black text-green-700">
                        $ {{ number_format($item->adjustedBudget->approved_amount ?? 0, 2) }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-8 py-10 text-center text-sm text-gray-400 italic">
                        {{ __('No budget items budgeted.') }}
                    </td>
                </tr>
            @endforelse
        </x-ui.table>
    </x-ui.card>

    <div class="flex justify-end gap-3 pt-6 border-t border-slate-100">
        @if($isEditable)
            <x-ui.button variant="secondary" size="md" href="{{ route('activities.indicators', $activity->id) }}"
                wire:navigate>
                {{ __('Edit Indicators') }}
            </x-ui.button>
            <x-ui.button variant="primary" size="md" href="{{ route('activities.budget', $activity->id) }}" wire:navigate>
                {{ __('Edit Budget') }}
            </x-ui.button>
        @else
            <div class="flex items-center gap-2 px-6 py-2 bg-slate-50 rounded-xl border border-slate-100 text-slate-400">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                    <rect width="18" height="11" x="3" y="11" rx="2" ry="2" />
                    <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                </svg>
                <span class="text-xs font-black uppercase tracking-widest">{{ __('Locked for Review') }}</span>
            </div>
        @endif
    </div>
</div>