<?php

use Livewire\Volt\Component;
use App\Models\Activity;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
use Livewire\Attributes\Validate;

new class extends Component {
    public $selectedActivityId = null;
    public $selectedDirectorate = null;
    public $selectedCategory = null;
    public $indicators = [];
    public $quarter = 1;
    public $activeYear;

    public function mount(): void
    {
        $activeEnvelope = App\Models\FiscalEnvelope::getActiveEnvelope();
        $this->activeYear = $activeEnvelope?->fiscal_year;

        $month = date('n');
        $this->quarter = ceil($month / 3);
    }

    public function getActivitiesProperty()
    {
        if (!$this->activeYear) return collect();

        $user = auth()->user();
        $query = Activity::where('fiscal_year', $this->activeYear)
            ->with(['directorate', 'lineItems.economicClassification']);

        if (!$user->hasAnyRole(['Admin', 'Planning', 'Budget'])) {
            $query->where('directorate_id', $user->directorate_id);
        } elseif ($this->selectedDirectorate) {
            $query->where('directorate_id', $this->selectedDirectorate);
        }

        if ($this->selectedCategory) {
            $query->whereHas('lineItems', fn($q) => $q->where('economic_classification_id', $this->selectedCategory));
        }

        return $query->get();
    }

    public function updatedSelectedActivityId(): void
    {
        if ($this->selectedActivityId) {
            $this->indicators = Indicator::where('activity_id', $this->selectedActivityId)
                ->with([
                    'progress' => function ($query) {
                        $query->where('quarter', $this->quarter);
                    }
                ])
                ->get();
        } else {
            $this->indicators = [];
        }
    }

    public function updatedQuarter(): void
    {
        $this->selectedActivityId = null;
        $this->indicators = [];
    }

    public function updatedSelectedDirectorate(): void
    {
        $this->selectedActivityId = null;
        $this->indicators = [];
    }

    public function updatedSelectedCategory(): void
    {
        $this->selectedActivityId = null;
        $this->indicators = [];
    }

    public function saveProgress($indicatorId, $value): void
    {
        $progress = IndicatorProgress::where('indicator_id', $indicatorId)
            ->where('quarter', $this->quarter)
            ->first();

        // Strict Protection: Prevents overriding Submitted or Verified data from the entry form
        if ($progress && !in_array($progress->status, ['Draft', 'Rejected'])) {
            $this->dispatch('toast', message: __('Cannot edit progress that is already :status.', ['status' => __($progress->status)]), type: 'error');
            return;
        }

        IndicatorProgress::updateOrCreate(
            ['indicator_id' => $indicatorId, 'quarter' => $this->quarter],
            [
                'achieved_value' => $value,
                'status' => 'Draft' // New or updated values from this form always start as Draft
            ]
        );

        $this->dispatch('toast', message: __('Progress saved as Draft. It will reflect in charts once Verified by a Director.'), type: 'success');
    }

    public function submitProgress(): void
    {
        $indicatorIds = collect($this->indicators)->pluck('id');

        IndicatorProgress::whereIn('indicator_id', $indicatorIds)
            ->where('quarter', $this->quarter)
            ->where(function ($q) {
                $q->where('status', 'Draft')->orWhere('status', 'Rejected');
            })
            ->update([
                'status' => 'Submitted',
                'submitted_by' => auth()->id()
            ]);

        $this->updatedSelectedActivityId();
        $this->dispatch('toast', message: __('Progress submitted for verification.'), type: 'success');
    }

    public function verifyProgress($indicatorId): void
    {
        if (!auth()->user()->hasRole('Director')) {
            $this->dispatch('toast', message: 'Only Directors can verify progress.', type: 'error');
            return;
        }

        $progress = IndicatorProgress::where('indicator_id', $indicatorId)
            ->where('quarter', $this->quarter)
            ->first();

        if ($progress) {
            $progress->update([
                'status' => 'Verified',
                'verified_by' => auth()->id(),
                'verified_at' => now(),
            ]);
        }

        $this->updatedSelectedActivityId();
        $this->dispatch('toast', message: __('Progress verified successfully.'), type: 'success');
    }

    public function rejectProgress($indicatorId): void
    {
        if (!auth()->user()->hasRole('Director')) {
            $this->dispatch('toast', message: 'Only Directors can reject progress.', type: 'error');
            return;
        }

        $progress = IndicatorProgress::where('indicator_id', $indicatorId)
            ->where('quarter', $this->quarter)
            ->first();

        if ($progress) {
            $progress->update(['status' => 'Rejected']);
        }

        $this->updatedSelectedActivityId();
        $this->dispatch('toast', message: __('Progress rejected and returned to draft.'), type: 'success');
    }
}; ?>

<div class="space-y-6">
    @if(!$activeYear)
        <div class="bg-amber-50 border-2 border-dashed border-amber-200 rounded-[2rem] p-12 text-center">
            <div class="h-16 w-16 bg-amber-100 rounded-full flex items-center justify-center mx-auto mb-6">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>
            <h3 class="text-md font-black text-slate-900 uppercase tracking-tight mb-2">
                {{ __('Data Entry Disabled') }}
            </h3>
            <p class="text-slate-500 font-medium text-sm">
                {{ __('Progress reporting is only permitted for periods with an active budget authority.') }}
            </p>
        </div>
    @else
        <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-100">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6 pb-6 border-b border-slate-50">
                <div>
                    <x-input-label for="quarter" :value="__('Select Trimestre')" class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                    <select id="quarter" wire:model.live="quarter"
                        class="block w-full border-none bg-slate-50 focus:bg-white focus:ring-2 focus:ring-indigo-500/20 rounded-xl text-sm font-bold text-slate-700 transition-all">
                        <option value="1">{{ __('1st Quarter (Jan-Mar)') }}</option>
                        <option value="2">{{ __('2nd Quarter (Apr-Jun)') }}</option>
                        <option value="3">{{ __('3rd Quarter (Jul-Sep)') }}</option>
                        <option value="4">{{ __('4th Quarter (Oct-Dec)') }}</option>
                    </select>
                </div>

                @if(auth()->user()->hasAnyRole(['Admin', 'Planning', 'Budget']))
                    <div>
                        <x-input-label for="directorate" :value="__('Filter by Directorate')" class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                        <select id="directorate" wire:model.live="selectedDirectorate"
                            class="block w-full border-none bg-slate-50 focus:bg-white focus:ring-2 focus:ring-indigo-500/20 rounded-xl text-sm font-bold text-slate-700 transition-all">
                            <option value="">{{ __('All Directorates') }}</option>
                            @foreach(\App\Models\Directorate::orderBy('name')->get() as $dir)
                                <option value="{{ $dir->id }}">{{ $dir->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div>
                    <x-input-label for="category" :value="__('Filter by Category')" class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                    <select id="category" wire:model.live="selectedCategory"
                        class="block w-full border-none bg-slate-50 focus:bg-white focus:ring-2 focus:ring-indigo-500/20 rounded-xl text-sm font-bold text-slate-700 transition-all">
                        <option value="">{{ __('All Categories') }}</option>
                        @foreach(\App\Models\EconomicClassification::orderBy('name')->get() as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div>
                <x-input-label for="activity" :value="__('Select Activity for Entry')" class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                <select id="activity" wire:model.live="selectedActivityId"
                    class="block w-full border-none bg-slate-50 focus:bg-white focus:ring-2 focus:ring-indigo-500/20 rounded-xl text-sm font-bold text-slate-700 transition-all">
                    <option value="">-- {{ __('Choose an activity to update progress') }} --</option>
                    @foreach($this->activities as $activity)
                        <option value="{{ $activity->id }}">[{{ $activity->directorate->name ?? '—' }}] {{ str($activity->description)->limit(120) }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        @if (session()->has('message'))
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-xl relative animate-in fade-in" role="alert">
                <span class="block sm:inline font-bold">{{ session('message') }}</span>
            </div>
        @endif

        @if (session()->has('error'))
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-xl relative animate-in shake duration-500" role="alert">
                <span class="block sm:inline font-bold">{{ session('error') }}</span>
            </div>
        @endif

        @if($selectedActivityId)
            <div class="bg-white shadow rounded-lg overflow-hidden border">
                <div class="px-6 py-4 border-b bg-gray-50 flex justify-between items-center">
                    <h3 class="text-sm font-black uppercase tracking-widest text-gray-800">{{ __('Activity Indicators') }}</h3>

                    @php
                        $anyDrafts = collect($indicators)->some(function ($i) {
                            $p = $i->progress->first();
                            return $p && ($p->status === 'Draft' || $p->status === 'Rejected');
                        });
                    @endphp

                    @if($anyDrafts)
                        <button wire:click="submitProgress" class="inline-flex items-center gap-2 px-4 py-2 bg-black hover:bg-gray-800 text-white text-[10px] font-black uppercase tracking-widest rounded-xl transition-all shadow-lg shadow-black/10">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
                            {{ __('Submit for Verification') }}
                        </button>
                    @endif
                </div>
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="px-6 py-4 text-left text-[10px] font-black uppercase tracking-widest text-gray-500">{{ __('Indicator') }}</th>
                            <th class="px-6 py-4 text-center text-[10px] font-black uppercase tracking-widest text-gray-500">{{ __('Unit') }}</th>
                            <th class="px-6 py-4 text-right text-[10px] font-black uppercase tracking-widest text-gray-500">{{ __('Annual Target (Adj.)') }}</th>
                            <th class="px-6 py-4 text-center text-[10px] font-black uppercase tracking-widest text-gray-500">{{ __('Achieved Value') }} (Q{{ $quarter }})</th>
                            <th class="px-6 py-4 text-center text-[10px] font-black uppercase tracking-widest text-gray-500">{{ __('Status') }}</th>
                            <th class="px-6 py-4 text-right text-[10px] font-black uppercase tracking-widest text-gray-500">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse($indicators as $indicator)
                            @php
                                $progress = $indicator->progress->first();
                                $status = $progress?->status ?? 'Draft';
                                $currentValue = $progress ? $progress->achieved_value : 0;
                                $canEdit = $status === 'Draft' || $status === 'Rejected';
                            @endphp
                            <tr class="hover:bg-gray-50/50 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="text-sm font-bold text-gray-900 leading-tight">{{ $indicator->name }}</div>
                                    <div class="text-[10px] font-medium text-gray-400 uppercase mt-0.5 tracking-tighter">{{ $indicator->type }}</div>
                                </td>
                                <td class="px-6 py-4 text-center text-xs font-bold text-gray-500">{{ $indicator->unit }}</td>
                                <td class="px-6 py-4 text-right text-sm font-black text-gray-900 italic">
                                    {{ number_format($indicator->annual_target, 2) }}</td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center justify-center">
                                        <input type="number" step="0.01" value="{{ $currentValue }}"
                                            {{ $canEdit ? '' : 'disabled' }}
                                            x-on:blur="$wire.saveProgress({{ $indicator->id }}, $event.target.value)"
                                            class="w-32 !rounded-xl !bg-gray-50/50 !border-gray-100 text-sm font-black text-center focus:!ring-black focus:!bg-white disabled:opacity-50 transition-all">
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-[10px] font-black uppercase tracking-widest border
                                        @if($status === 'Verified') bg-green-50 text-green-700 border-green-100
                                        @elseif($status === 'Submitted') bg-indigo-50 text-indigo-700 border-indigo-100 animate-pulse
                                        @elseif($status === 'Rejected') bg-red-50 text-red-700 border-red-100
                                        @else bg-gray-50 text-gray-600 border-gray-100 @endif">
                                        {{ __($status) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="flex justify-end gap-2">
                                        @if($status === 'Submitted' && auth()->user()->hasRole('Director'))
                                            <button wire:click="verifyProgress({{ $indicator->id }})" class="p-2 text-green-600 hover:bg-green-50 rounded-xl transition-all" title="Verify">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                            </button>
                                            <button wire:click="rejectProgress({{ $indicator->id }})" class="p-2 text-red-600 hover:bg-red-50 rounded-xl transition-all" title="Reject">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m18 6-12 12"/><path d="m6 6 12 12"/></svg>
                                            </button>
                                        @elseif($status === 'Verified')
                                            <span class="p-2 text-green-500">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg>
                                            </span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center text-gray-400 italic text-sm font-medium">
                                    {{ __('No indicators found matching your criteria.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @else
            <div class="bg-indigo-50 border border-indigo-100 p-8 rounded-lg text-center">
                <svg class="mx-auto h-12 w-12 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                </svg>
                <h3 class="mt-2 text-sm font-medium text-gray-900">{{ __('No activity selected') }}</h3>
                <p class="mt-1 text-sm text-gray-500">{{ __('Select an activity to start recording physical execution.') }}</p>
            </div>
        @endif
    @endif
</div>