<?php

use Livewire\Volt\Component;
use App\Models\Activity;
use App\Models\FiscalEnvelope;
use App\Models\Directorate;

new class extends Component {
    public $activeYear;
    public $activities = [];
    public $directorateGroups = null;
    public $selectedDirectorate = null;
    public $statusFilter = 'Submitted';
    public $envelope = null;
    public $isLocked = false;

    public function mount()
    {
        // Default to next year for planning, or active if settings say so
        $currentActiveYear = FiscalEnvelope::getEffectiveYear();
        $this->activeYear = (int)$currentActiveYear + 1;
        
        $this->refreshContext();
    }

    public function refreshContext()
    {
        $this->envelope = FiscalEnvelope::where('fiscal_year', $this->activeYear)->first();
        // Lock only if the entire cycle is CLOSED/FINALIZE
        $this->isLocked = $this->envelope && $this->envelope->status === FiscalEnvelope::STATUS_CLOSED;
        
        // If locked, we show Approved items by default. Otherwise, show what's pending review.
        if ($this->isLocked) {
            $this->statusFilter = Activity::STATUS_APPROVED;
        } else {
            $this->statusFilter = Activity::STATUS_SUBMITTED;
        }

        $this->loadActivities();
    }

    public function updatedActiveYear()
    {
        $this->refreshContext();
    }

    public function loadActivities()
    {
        $query = Activity::where('fiscal_year', $this->activeYear)
            ->with(['directorate', 'lineItems.economicClassification', 'program']);

        if ($this->selectedDirectorate) {
            $query->where('directorate_id', $this->selectedDirectorate);
        }

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        $this->activities = $query->get();

        // Group by Root Directorate for the Minister's view
        if ($this->statusFilter === Activity::STATUS_WAITING_APPROVAL || $this->statusFilter === Activity::STATUS_APPROVED) {
            $this->directorateGroups = $this->activities->groupBy(function($activity) {
                // Find the root parent (General Directorate)
                $root = $activity->directorate;
                while ($root->parent) {
                    $root = $root->parent;
                }
                return $root->id;
            });
        } else {
            $this->directorateGroups = null;
        }
    }

    public function updatedSelectedDirectorate()
    {
        $this->loadActivities();
    }
    public function updatedStatusFilter()
    {
        $this->loadActivities();
    }

    public function approveDirectoratePlan($rootDirectorateId)
    {
        abort_unless(auth()->user()->can('approve_paa'), 403);
        $activitiesToApprove = $this->activities->filter(function($activity) use ($rootDirectorateId) {
            $root = $activity->directorate;
            while ($root->parent) {
                $root = $root->parent;
            }
            return $root->id == $rootDirectorateId && $activity->status === Activity::STATUS_WAITING_APPROVAL;
        });

        foreach ($activitiesToApprove as $activity) {
            $activity->update([
                'status' => Activity::STATUS_APPROVED,
                'approved_at' => now(),
                'approved_by' => auth()->id()
            ]);
            
            // Notify Directorate Users
            $users = \App\Models\User::where('directorate_id', $activity->directorate_id)->get();
            foreach ($users as $user) {
                $user->notify(new \App\Notifications\SystemNotification(
                    __('Activity Approved'),
                    __('Activity ":desc" has been technically approved for :year.', ['desc' => str($activity->description)->limit(30), 'year' => $this->activeYear]),
                    route('activities.show', ['activity' => $activity->id], false)
                ));
            }
        }

        $this->loadActivities();
        $this->dispatch('toast', message: __('Plan for Directorate approved successfully.'), type: 'success');
    }

    public function approve($id)
    {
        abort_unless(auth()->user()->can('approve_paa'), 403);
        $activity = Activity::findOrFail($id);
        $activity->update(['status' => Activity::STATUS_APPROVED]);
        
        // Notify Directorate Users
        $users = \App\Models\User::where('directorate_id', $activity->directorate_id)->get();
        foreach ($users as $user) {
            $user->notify(new \App\Notifications\SystemNotification(
                __('Activity Approved'),
                __('Activity ":desc" has been technically approved for :year.', ['desc' => str($activity->description)->limit(30), 'year' => $this->activeYear]),
                route('activities.show', ['activity' => $activity->id], false)
            ));
        }

        $this->loadActivities();
        $this->dispatch('toast', message: __('Activity approved successfully.'), type: 'success');
    }

    public function review($id)
    {
        abort_unless(auth()->user()->can('approve_paa'), 403);
        $activity = Activity::findOrFail($id);
        
        $newStatus = Activity::STATUS_VALIDATED; // Default to final validation

        // Check if there is an intermediate approval step required
        if ($activity->status === Activity::STATUS_SUBMITTED) {
            $firstParent = $activity->directorate->parent;
            if ($firstParent && $firstParent->parent) {
                $newStatus = Activity::STATUS_REVIEWED;
            }
        }

        $activity->update([
            'status' => $newStatus,
            'reviewed_at' => now(),
            'reviewed_by' => auth()->id()
        ]);

        $this->loadActivities();
        $this->dispatch('toast', message: __('Activity updated to :status.', ['status' => __($newStatus)]), type: 'success');
    }

    public function reject($id)
    {
        abort_unless(auth()->user()->can('approve_paa'), 403);
        $activity = Activity::findOrFail($id);
        $activity->update(['status' => Activity::STATUS_DRAFT]); // Revert to Draft for correction
        
        // Notify Directorate Users
        $users = \App\Models\User::where('directorate_id', $activity->directorate_id)->get();
        foreach ($users as $user) {
            $user->notify(new \App\Notifications\SystemNotification(
                __('Activity Returned for Correction'),
                __('Activity ":desc" requires corrections for :year.', ['desc' => str($activity->description)->limit(30), 'year' => $this->activeYear]),
                route('activities.index', absolute: false)
            ));
        }

        $this->loadActivities();
        $this->dispatch('toast', message: __('Activity returned to department for correction.'), type: 'success');
    }

    public function getBudgetImpactProperty()
    {
        $approvedTotal = Activity::where('fiscal_year', $this->activeYear)
            ->where('status', Activity::STATUS_APPROVED)
            ->get()
            ->flatMap->lineItems->sum('estimated_amount');

        $pendingTotal = Activity::where('fiscal_year', $this->activeYear)
            ->whereIn('status', [Activity::STATUS_SUBMITTED, Activity::STATUS_REVIEWED])
            ->get()
            ->flatMap->lineItems->sum('estimated_amount');

        return [
            'approved' => $approvedTotal,
            'pending' => $pendingTotal,
            'limit' => $this->envelope->total_amount ?? 0,
        ];
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
        <div>
            <h1 class="text-3xl font-black text-slate-900 tracking-tight leading-none">{{ __('PAA Technical Review') }}</h1>
            <div class="flex items-center gap-4 mt-3">
                <p class="text-slate-400 font-bold uppercase tracking-[0.2em] text-[10px]">
                    {{ __('Phase 1: Requirement Planning for') }}
                </p>
                <select wire:model.live="activeYear" class="bg-slate-100 border-none rounded-lg text-[10px] font-black uppercase tracking-widest py-1 px-3 focus:ring-2 focus:ring-indigo-500/20 cursor-pointer">
                    @php 
                        $baseYear = (int)App\Models\SystemSetting::get('planning_active_year', date('Y'));
                    @endphp
                    <option value="{{ $baseYear }}">{{ $baseYear }} ({{ __('Active') }})</option>
                    <option value="{{ $baseYear + 1 }}">{{ $baseYear + 1 }} ({{ __('Planning') }})</option>
                    <!-- <option value="{{ $baseYear + 2 }}">{{ $baseYear + 2 }}</option> -->
                </select>
            </div>
        </div>
        
        <!-- Requirement Summary Widget -->
        <div class="flex gap-4 p-4 bg-slate-900 rounded-2xl text-white shadow-xl shadow-slate-900/20">
            <div class="px-4 border-r border-slate-800">
                <div class="text-[9px] font-black uppercase text-slate-500 tracking-widest">{{ __('Total Requested') }}</div>
                <div class="text-sm font-black">$ {{ number_format($this->budgetImpact['approved'] + $this->budgetImpact['pending'], 0) }}</div>
            </div>
            <div class="px-4 border-r border-slate-800">
                <div class="text-[9px] font-black uppercase text-slate-500 tracking-widest">{{ __('Approved PAA') }}</div>
                <div class="text-sm font-black text-emerald-400">$ {{ number_format($this->budgetImpact['approved'], 0) }}</div>
            </div>
            <div class="px-4">
                <div class="text-[9px] font-black uppercase text-slate-500 tracking-widest">{{ __('Pending Review') }}</div>
                <div class="text-sm font-black text-amber-400">
                    $ {{ number_format($this->budgetImpact['pending'], 0) }}
                </div>
            </div>
        </div>
    </div>

    @if($isLocked)
        <div class="bg-indigo-600 p-6 rounded-[2rem] shadow-xl shadow-indigo-600/20 text-white flex flex-col md:flex-row items-center gap-6 animate-in slide-in-from-top duration-500">
            <div class="h-16 w-16 bg-white/20 rounded-2xl flex items-center justify-center shrink-0">
                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </div>
            <div>
                <h3 class="text-xl font-black italic tracking-tight">{{ __('PAA Cycle Finalized') }}</h3>
                <p class="text-indigo-100 text-sm font-bold opacity-80 mt-1">
                    {{ __('The PAA for :year is officially approved and the planning cycle is locked.', ['year' => $activeYear]) }} 
                    {{ __('Updates can now be tracked in the Performance Monitoring dashboard.') }}
                </p>
            </div>
            <div class="md:ml-auto">
                <a href="{{ route('monitoring.index') }}" class="px-6 py-3 bg-white text-indigo-600 text-xs font-black uppercase tracking-widest rounded-xl hover:bg-slate-50 transition-all shadow-lg whitespace-nowrap">
                    {{ __('View Monitoring') }}
                </a>
            </div>
        </div>
    @endif

    <!-- Filters Header -->
    <div class="flex flex-col md:flex-row gap-4">
        <div class="flex-1">
            <select wire:model.live="selectedDirectorate"
                class="w-full bg-white border-none shadow-sm rounded-xl text-xs font-black text-slate-900 focus:ring-2 focus:ring-indigo-600/20 py-3 px-4">
                <option value="">{{ __('All Directorates') }}</option>
                @foreach(Directorate::activeInYear($this->activeYear)->orderBy('name')->get() as $dir)
                    <option value="{{ $dir->id }}">{{ $dir->name }}</option>
                @endforeach
            </select>
        </div>
        
        <div class="w-full md:w-64">
            <select wire:model.live="statusFilter"
                class="w-full bg-white border-none shadow-sm rounded-xl text-xs font-black text-slate-900 focus:ring-2 focus:ring-indigo-600/20 py-3 px-4">
                <option value="{{ Activity::STATUS_SUBMITTED }}">{{ __('Submitted / Pending') }}</option>
                <option value="{{ Activity::STATUS_REVIEWED }}">{{ __('Reviewed / Ready') }}</option>
                <option value="{{ Activity::STATUS_APPROVED }}">{{ __('Final Approved') }}</option>
                <option value="{{ Activity::STATUS_DRAFT }}">{{ __('Draft / Planned') }}</option>
                <option value="{{ Activity::STATUS_REJECTED }}">{{ __('Rejected') }}</option>
                <option value="">{{ __('All Statuses') }}</option>
            </select>
        </div>
    </div>

    @if (session()->has('message'))
        <div class="bg-indigo-50 border border-indigo-100 text-indigo-700 px-4 py-3 rounded-xl relative animate-in fade-in"
            role="alert">
            <span class="block sm:inline font-bold">{{ session('message') }}</span>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6">
    <div class="grid grid-cols-1 gap-6">
        @if($directorateGroups && $statusFilter === Activity::STATUS_VALIDATED)
            {{-- GROUPED VIEW FOR MINISTER APPROVAL --}}
            @foreach($directorateGroups as $rootId => $groupActivities)
                @php
                    $rootDirectorate = App\Models\Directorate::find($rootId);
                    $totalBudget = $groupActivities->sum(fn($a) => $a->lineItems->sum('estimated_amount'));
                    $totalActivities = $groupActivities->count();
                @endphp
                <div class="bg-white rounded-[2.5rem] p-8 border border-slate-100 shadow-sm relative overflow-hidden group">
                    <div class="absolute top-0 right-0 p-8 opacity-10 pointer-events-none">
                        <svg xmlns="http://www.w3.org/2000/svg" width="120" height="120" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="12" x="2" y="6" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01"/><path d="M18 12h.01"/></svg>
                    </div>

                    <div class="relative z-10">
                        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 mb-8">
                            <div>
                                <span class="px-3 py-1 bg-indigo-50 text-indigo-700 rounded-full text-[10px] font-black uppercase tracking-widest border border-indigo-100 mb-3 inline-block">
                                    {{ $rootDirectorate->type ?? 'General Directorate' }}
                                </span>
                                <h3 class="text-2xl font-black text-slate-900 leading-none">{{ $rootDirectorate->name }}</h3>
                                <p class="text-sm font-medium text-slate-400 mt-2 max-w-2xl">{{ $rootDirectorate->description ?? 'No description available for this organizational unit.' }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __('Total Budget Requested') }}</p>
                                <p class="text-3xl font-black text-slate-900 tracking-tight">$ {{ number_format($totalBudget, 0) }}</p>
                            </div>
                        </div>

                        <div class="bg-slate-50/50 rounded-2xl p-6 border border-slate-100 mb-8">
                            <h4 class="text-xs font-black text-slate-500 uppercase tracking-widest mb-4">{{ __('Included Activities') }} ({{ $totalActivities }})</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                                @foreach($groupActivities->take(6) as $activity)
                                    <div class="bg-white p-3 rounded-xl border border-slate-200/60 shadow-sm flex items-start gap-3">
                                        <div class="h-2 w-2 rounded-full bg-indigo-500 mt-1.5 shrink-0"></div>
                                        <div>
                                            <p class="text-[11px] font-bold text-slate-700 leading-tight line-clamp-2" title="{{ $activity->description }}">{{ $activity->description }}</p>
                                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-wider mt-1">{{ $activity->directorate->name }}</p>
                                        </div>
                                    </div>
                                @endforeach
                                @if($totalActivities > 6)
                                    <div class="flex items-center justify-center text-[10px] font-black text-slate-400 uppercase tracking-widest">
                                        + {{ $totalActivities - 6 }} {{ __('more') }}
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="flex items-center gap-4">
                            <button wire:click="approveDirectoratePlan({{ $rootId }})" 
                                class="px-8 py-4 bg-black text-white rounded-2xl text-xs font-black uppercase tracking-widest hover:bg-slate-800 transition-all shadow-lg shadow-slate-900/20 flex items-center gap-2">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                {{ __('Approve Entire Plan') }}
                            </button>
                            <button wire:click="$set('selectedDirectorate', {{ $rootId }})" class="px-6 py-4 bg-white border border-slate-200 text-slate-600 rounded-2xl text-xs font-black uppercase tracking-widest hover:bg-slate-50 transition-all">
                                {{ __('View Details') }}
                            </button>
                        </div>
                    </div>
                </div>
            @endforeach
        @else
            {{-- STANDARD LIST VIEW --}}
            @forelse($activities as $activity)
                <div
                    class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden group hover:shadow-md transition-all">
                    <div class="flex flex-col md:flex-row">
                        <div class="flex-1 p-6">
                            <div class="flex items-center gap-3 mb-4">
                                <span
                                    class="px-2 py-1 bg-slate-100 text-[9px] font-black text-slate-500 rounded uppercase tracking-widest">{{ $activity->program->code ?? 'N/A' }}</span>
                                <span
                                    class="text-[10px] font-black text-indigo-500 uppercase tracking-tighter">{{ $activity->directorate->name }}</span>
                                <span class="ml-auto inline-flex items-center px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-widest border
                                        @if($activity->status === Activity::STATUS_APPROVED) bg-emerald-50 text-emerald-700 border-emerald-100
                                        @elseif($activity->status === Activity::STATUS_REVIEWED) bg-indigo-50 text-indigo-700 border-indigo-100
                                        @else bg-amber-50 text-amber-700 border-amber-100 @endif">
                                    {{ __($activity->status) }}
                                </span>
                            </div>
                            <h4 class="text-sm font-bold text-slate-900 mb-2 leading-relaxed">{{ $activity->description }}</h4>

                            <div class="flex flex-wrap gap-4 mt-6">
                                @foreach($activity->lineItems as $item)
                                    <div class="px-3 py-2 bg-slate-50 rounded-lg border border-slate-100">
                                        <div class="text-[8px] font-black text-slate-400 uppercase tracking-widest">
                                            {{ str($item->economicClassification->name)->limit(15) }}</div>
                                        <div class="text-xs font-black text-slate-700">$
                                            {{ number_format($item->estimated_amount, 0) }}</div>
                                    </div>
                                @endforeach
                                <div class="px-3 py-2 bg-indigo-50 rounded-lg border border-indigo-100">
                                    <div class="text-[8px] font-black text-indigo-400 uppercase tracking-widest">
                                        {{ __('Total Amount') }}</div>
                                    <div class="text-xs font-black text-indigo-700">$
                                        {{ number_format($activity->lineItems->sum('estimated_amount'), 0) }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="md:w-48 bg-slate-50/50 border-l border-slate-50 p-6 flex flex-col justify-center gap-3">
                            
                            @if($activity->status === Activity::STATUS_SUBMITTED || $activity->status === Activity::STATUS_REVIEWED)
                                <button wire:click="review({{ $activity->id }})"
                                    class="w-full py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-[10px] font-black uppercase tracking-widest rounded-xl transition-all">
                                    {{ __('Review') }}
                                </button>
                            @endif

                            @if($activity->status === Activity::STATUS_VALIDATED && (auth()->user()->isMinister() || auth()->user()->isAdministrator()))
                                <button wire:click="approve({{ $activity->id }})"
                                    class="w-full py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-[10px] font-black uppercase tracking-widest rounded-xl transition-all">
                                    {{ __('Approve') }}
                                </button>
                            @endif

                            <button wire:click="reject({{ $activity->id }})"
                                wire:confirm="{{ __('Return this activity to the department for corrections?') }}"
                                class="w-full py-2 border border-slate-200 text-slate-600 hover:bg-slate-100 text-[10px] font-black uppercase tracking-widest rounded-xl transition-all">
                                {{ __('Reject/Revert') }}
                            </button>
                        </div>
                    </div>
                </div>
            @empty
                <div class="p-12 text-center bg-white rounded-2xl border border-dashed border-slate-200">
                    <svg class="mx-auto h-12 w-12 text-slate-200 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                    </svg>
                    <p class="text-slate-400 italic text-sm font-medium">
                        {{ __('No activities matching the criteria were found.') }}</p>
                </div>
            @endforelse
        @endif
    </div>
    </div>
</div>