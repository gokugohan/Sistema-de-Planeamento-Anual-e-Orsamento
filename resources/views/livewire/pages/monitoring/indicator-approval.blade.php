<?php

use Livewire\Volt\Component;
use App\Models\IndicatorProgress;
use App\Models\Activity;
use App\Models\Indicator;

new class extends Component {
    public $activeYear;
    public $selectedDirectorate = null;
    public $quarter = 1;
    public $pendingProgress = [];

    public function mount()
    {
        $activeEnvelope = App\Models\FiscalEnvelope::getActiveEnvelope();
        $this->activeYear = $activeEnvelope?->fiscal_year;

        $month = date('n');
        $this->quarter = ceil($month / 3);

        if (!auth()->user()->can('view_all_paa')) {
            $this->selectedDirectorate = auth()->user()->directorate_id;
        }

        $this->loadPending();
    }

    public function loadPending()
    {
        $query = IndicatorProgress::where('status', 'Submitted')
            ->where('quarter', $this->quarter)
            ->whereHas('indicator.activity', function ($q) {
                $q->where('fiscal_year', $this->activeYear);
                if ($this->selectedDirectorate) {
                    $q->where('directorate_id', $this->selectedDirectorate);
                }
            })
            ->with(['indicator.activity.directorate', 'submittedBy']);

        $this->pendingProgress = $query->get();
    }

    public function updatedQuarter()
    {
        $this->loadPending();
    }
    public function updatedSelectedDirectorate()
    {
        $this->loadPending();
    }

    /** In the user's scope, and not something they reported themselves. @return string|null why it cannot be decided, null if it can */
    private function whyNot(IndicatorProgress $progress): ?string
    {
        $progress->loadMissing('indicator.activity');
        if (! app(\App\Policies\ActivityPolicy::class)->inScope(auth()->user(), $progress->indicator?->activity?->directorate_id)) {
            return 'scope';
        }

        return $progress->submitted_by === auth()->id() ? 'own' : null;
    }

    public function approve($id)
    {
        abort_unless(auth()->user()->can('approve_progress_report'), 403);
        $progress = IndicatorProgress::findOrFail($id);
        $why = $this->whyNot($progress);
        abort_if($why === 'scope', 403);
        if ($why === 'own') {
            $this->dispatch('toast', message: __('Someone other than the person who reported it must verify it.'), type: 'error');

            return;
        }
        $progress->update([
            'status' => 'Verified',
            'verified_by' => auth()->id(),
            'verified_at' => now()
        ]);
        $this->loadPending();
        $this->dispatch('toast', message: __('Progress verified successfully.'), type: 'success');
    }

    public function reject($id)
    {
        abort_unless(auth()->user()->can('approve_progress_report'), 403);
        $progress = IndicatorProgress::findOrFail($id);
        $why = $this->whyNot($progress);
        abort_if($why === 'scope', 403);
        if ($why === 'own') {
            $this->dispatch('toast', message: __('Someone other than the person who reported it must verify it.'), type: 'error');

            return;
        }
        $progress->update(['status' => 'Rejected']);
        $this->loadPending();
        $this->dispatch('toast', message: __('Progress rejected.'), type: 'success');
    }

    public function approveAll()
    {
        abort_unless(auth()->user()->can('approve_progress_report'), 403);
        $done = $left = 0;
        foreach ($this->pendingProgress as $progress) {
            if ($this->whyNot($progress) !== null) {
                $left++; // their own reports, or outside their scope, wait for someone else

                continue;
            }
            $progress->update([
                'status' => 'Verified',
                'verified_by' => auth()->id(),
                'verified_at' => now()
            ]);
            $done++;
        }
        $this->loadPending();
        $this->dispatch('toast', message: $left
            ? __(':done verified; :left left for someone else (your own reports cannot be verified by you).', ['done' => $done, 'left' => $left])
            : __('All pending progress verified.'), type: $left ? 'info' : 'success');
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="page-title">{{ __('Progress Approval') }}
            </h1>
            <p class="page-subtitle">
                {{ __('Review and verify physical execution submissions') }}
            </p>
        </div>

        <div class="flex gap-4 items-center">
            <select wire:model.live="quarter" aria-label="{{ __('Select Trimestre') }}"
                class="flux-input !w-auto">
                <option value="1">{{ __('Q1 (Jan-Mar)') }}</option>
                <option value="2">{{ __('Q2 (Apr-Jun)') }}</option>
                <option value="3">{{ __('Q3 (Jul-Sep)') }}</option>
                <option value="4">{{ __('Q4 (Oct-Dec)') }}</option>
            </select>

            @if(auth()->user()->can('view_all_paa'))
                <select wire:model.live="selectedDirectorate" aria-label="{{ __('Filter by Directorate') }}"
                    class="flux-input !w-auto">
                    <option value="">{{ __('All Directorates') }}</option>
                    @foreach(\App\Models\Directorate::all() as $dir)
                        <option value="{{ $dir->id }}">{{ $dir->name }}</option>
                    @endforeach
                </select>
            @endif
        </div>
    </div>

    @if (session()->has('message'))
        <div class="bg-emerald-50 border border-emerald-100 text-emerald-700 px-4 py-3 rounded-xl relative animate-in fade-in"
            role="alert">
            <span class="block sm:inline font-bold">{{ session('message') }}</span>
        </div>
    @endif

    <x-ui.card padding="p-0" class="overflow-hidden">
        <x-slot name="header">
            <div class="flex items-center justify-between px-6 py-4">
                <h3 class="text-sm font-semibold text-zinc-900 ">
                    {{ __('Pending Verifications') }} ({{ count($pendingProgress) }})
                </h3>
                @if(count($pendingProgress) > 0)
                    <button wire:click="approveAll"
                        wire:confirm="{{ __('Are you sure you want to verify all pending records?') }}"
                        class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-xl transition-all shadow-sm ">
                        {{ __('Verify All') }}
                    </button>
                @endif
            </div>
        </x-slot>

        <div class="overflow-x-auto" tabindex="0">
            <table class="min-w-full divide-y divide-zinc-100">
                <thead class="bg-zinc-50/50">
                    <tr>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-zinc-400">
                            {{ __('Directorate / Activity') }}</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-zinc-400">
                            {{ __('Indicator') }}</th>
                        <th
                            class="px-6 py-4 text-right text-xs font-semibold text-zinc-400">
                            {{ __('Target') }}</th>
                        <th
                            class="px-6 py-4 text-right text-xs font-semibold text-zinc-400">
                            {{ __('Submitted Value') }}</th>
                        <th
                            class="px-6 py-4 text-right text-xs font-semibold text-zinc-400">
                            {{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50">
                    @forelse($pendingProgress as $progress)
                        <tr class="hover:bg-zinc-50/50 transition-colors group">
                            <td class="px-6 py-4">
                                <div class="text-xs font-semibold text-indigo-500 mb-1">
                                    {{ $progress->indicator->activity->directorate->name ?? '—' }}</div>
                                <div class="text-xs font-bold text-zinc-700 leading-tight">
                                    {{ $progress->indicator->activity->description }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-xs font-bold text-zinc-900">{{ $progress->indicator->name }}</div>
                                <div class="text-xs font-medium text-zinc-400 ">
                                    {{ $progress->indicator->unit }}</div>
                            </td>
                            <td class="px-6 py-4 text-right text-xs font-semibold text-zinc-900">
                                {{ number_format($progress->indicator->annual_target, 2) }}
                            </td>
                            <td class="px-6 py-4 text-right">
                                <span
                                    class="inline-flex px-3 py-1 bg-indigo-50 text-indigo-700 text-xs font-semibold rounded-lg border border-indigo-100">
                                    {{ number_format($progress->achieved_value, 2) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex justify-end gap-2">
                                    <button wire:click="approve({{ $progress->id }})"
                                        class="p-2 text-emerald-600 hover:bg-emerald-50 rounded-xl transition-all"
                                        title="{{ __('Verify') }}">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                                            fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"
                                            stroke-linejoin="round">
                                            <path d="M20 6 9 17l-5-5" />
                                        </svg>
                                    </button>
                                    <button wire:click="reject({{ $progress->id }})"
                                        class="p-2 text-rose-600 hover:bg-rose-50 rounded-xl transition-all"
                                        title="{{ __('Reject') }}">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                                            fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"
                                            stroke-linejoin="round">
                                            <path d="m18 6-12 12" />
                                            <path d="m6 6 12 12" />
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center">
                                <div class="flex flex-col items-center">
                                    <svg class="h-12 w-12 text-zinc-200 mb-4" fill="none" viewBox="0 0 24 24"
                                        stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <p class="text-zinc-400 italic text-sm font-medium">
                                        {{ __('No pending progress submissions found.') }}</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>