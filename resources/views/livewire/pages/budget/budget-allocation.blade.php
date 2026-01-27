<?php

use Livewire\Volt\Component;
use App\Models\FiscalEnvelope;
use App\Models\Directorate;
use App\Models\Program;
use App\Models\Activity;
use App\Models\LineItem;
use Livewire\Attributes\On;

new class extends Component {
    public $fiscalYear;
    public $activeEnvelope;
    public $envelopes;
    public $totalAllocated = 0;
    public $remaining = 0;
    public $activeTab = 'hierarchy'; // 'hierarchy', 'department', 'summary', 'economic', 'program'
    public $selectedDirectorateId;

    public function mount()
    {
        $this->fiscalYear = FiscalEnvelope::getEffectiveYear();
        $this->envelopes = FiscalEnvelope::orderBy('fiscal_year', 'desc')->get();
        $this->activeEnvelope = FiscalEnvelope::where('fiscal_year', $this->fiscalYear)->first() ?? $this->envelopes->first();
        if ($this->activeEnvelope) {
            $this->loadStats();
        }

        $firstDir = Directorate::first();
        if ($firstDir) {
            $this->selectedDirectorateId = $firstDir->id;
        }
    }

    public function updatedFiscalYear($year)
    {
        $this->activeEnvelope = FiscalEnvelope::where('fiscal_year', $year)->first();
        $this->loadStats();
    }

    public function loadStats()
    {
        if (!$this->activeEnvelope)
            return;

        $this->totalAllocated = LineItem::whereHas('activity', fn($q) => $q->where('fiscal_year', $this->fiscalYear))
            ->sum('estimated_amount');

        $this->remaining = $this->activeEnvelope->total_amount - $this->totalAllocated;
    }

    public function selectDirectorate($id)
    {
        $this->selectedDirectorateId = $id;
    }

    public function getSummaryDataProperty()
    {
        return Directorate::with([
            'activities' => function ($q) {
                $q->where('fiscal_year', $this->fiscalYear)->with('lineItems');
            }
        ])->get()->map(function ($dir) {
            $allocated = $dir->activities->flatMap->lineItems->sum('estimated_amount');
            $used = $allocated * 0.48; // Simulated usage matching the image
            $remaining = $allocated - $used;
            $usage_pc = $allocated > 0 ? ($used / $allocated) * 100 : 0;

            return [
                'name' => $dir->name,
                'allocated' => $allocated,
                'used' => $used,
                'remaining' => $remaining,
                'usage_pc' => $usage_pc
            ];
        });
    }

    public function getEconomicDataProperty()
    {
        return App\Models\EconomicClassification::with([
            'lineItems' => function ($q) {
                $q->whereHas('activity', fn($aq) => $aq->where('fiscal_year', $this->fiscalYear));
            }
        ])->get()->map(function ($cat) {
            $allocated = $cat->lineItems->sum('estimated_amount');
            $used = $allocated * 0.42; // Simulated
            $remaining = $allocated - $used;
            $usage_pc = $allocated > 0 ? ($used / $allocated) * 100 : 0;

            return [
                'code' => $cat->code,
                'name' => $cat->name,
                'allocated' => $allocated,
                'used' => $used,
                'remaining' => $remaining,
                'usage_pc' => $usage_pc
            ];
        });
    }

    public function getProgramDataProperty()
    {
        return Program::with([
            'activities' => function ($q) {
                $q->where('fiscal_year', $this->fiscalYear)->with('lineItems');
            }
        ])->get()->map(function ($prog) {
            $allocated = $prog->activities->flatMap->lineItems->sum('estimated_amount');
            $used = $allocated * 0.45; // Simulated
            $remaining = $allocated - $used;
            $usage_pc = $allocated > 0 ? ($used / $allocated) * 100 : 0;

            return [
                'code' => $prog->oge_code,
                'name' => $prog->name,
                'allocated' => $allocated,
                'used' => $used,
                'remaining' => $remaining,
                'usage_pc' => $usage_pc
            ];
        });
    }

    public function getDirectoratesProperty()
    {
        return Directorate::with([
            'activities' => function ($query) {
                $query->where('fiscal_year', $this->fiscalYear)->with(['program', 'lineItems']);
            }
        ])->get()->map(function ($directorate) {
            $directorate->total_allocated = $directorate->activities->flatMap->lineItems->sum('estimated_amount');
            $directorate->budget_limit = 2500000; // Placeholder until we have directorate-level envelopes
            return $directorate;
        });
    }

    public function getSelectedDirectorateProperty()
    {
        if (!$this->selectedDirectorateId)
            return null;

        $dir = Directorate::with([
            'activities' => function ($q) {
                $q->where('fiscal_year', $this->fiscalYear)->with(['program', 'lineItems.adjustedBudget']);
            }
        ])->find($this->selectedDirectorateId);

        if (!$dir)
            return null;

        // Group activities by program for the table display
        $dir->grouped_data = $dir->activities->groupBy('program_id')->map(function ($activities) {
            $program = $activities->first()->program;
            $program->type = 'Program';
            $program->allocated = $activities->flatMap->lineItems->sum('estimated_amount');
            $program->used = $program->allocated * 0.45; // Simulated
            $program->remaining = $program->allocated - $program->used;
            $program->usage_pc = $program->allocated > 0 ? ($program->used / $program->allocated) * 100 : 0;

            $program->projects = $activities->map(function ($act) {
                $act->type = 'Project';
                $act->allocated = $act->lineItems->sum('estimated_amount');
                $act->used = $act->allocated * 0.42; // Simulated
                $act->remaining = $act->allocated - $act->used;
                $act->usage_pc = $act->allocated > 0 ? ($act->used / $act->allocated) * 100 : 0;
                return $act;
            });

            return $program;
        });

        return $dir;
    }

}; ?>

<div class="space-y-10 py-6 animate-in fade-in duration-700">
    <!-- Header Section (Harmonized) -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-6 pb-2">
        <div>
            <h1 class="text-4xl font-black text-slate-900 tracking-tight leading-none italic">
                {{ __('Budget Allocation') }}</h1>
            
        </div>
        <div class="flex gap-3">
           {{-- 
            <x-ui.button variant="primary" size="md" class="!rounded-xl px-8 shadow-lg shadow-indigo-600/20">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="mr-2">
                    <line x1="12" x2="12" y1="5" y2="19" />
                    <line x1="5" x2="19" y1="12" y2="12" />
                </svg>
                {{ __('Create Allocation') }}
            </x-ui.button>
             --}}
             <div class="flex items-center gap-4 mt-4">
                <p class="text-slate-400 font-bold uppercase tracking-[0.2em] text-[10px]">
                    {{ __('Redistribution Hub for') }}
                </p>
                <select wire:model.live="fiscalYear"
                    class="bg-slate-100 border-none rounded-lg text-[10px] font-black uppercase tracking-widest py-1 px-3 focus:ring-2 focus:ring-indigo-500/20 cursor-pointer">
                    @foreach($envelopes as $env)
                        <option value="{{ $env->fiscal_year }}">Fiscal Year {{ $env->fiscal_year }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    <!-- Stats Grid (High-Impact Variants) -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
        <!-- Total Envelope -->
        <div
            class="bg-white p-8 rounded-[2rem] border border-slate-100 shadow-sm shadow-indigo-500/5 group hover:shadow-indigo-500/10 transition-all duration-500">
            <div class="flex justify-between items-start mb-6">
                <div class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">
                    {{ __('Fiscal Authority') }}</div>
                <div class="p-2 bg-indigo-50 rounded-xl text-indigo-600"><svg xmlns="http://www.w3.org/2000/svg"
                        width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 2v20" />
                        <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
                    </svg></div>
            </div>
            <div class="text-3xl font-black text-slate-900 leading-none">$
                {{ number_format($activeEnvelope->total_amount ?? 0, 0) }}</div>
            <p class="text-[10px] font-bold text-slate-500 mt-4 italic uppercase tracking-tighter">
                {{ __('Context Authority FY') }} {{ $fiscalYear }}</p>
        </div>

        <!-- Total Allocated -->
        <div
            class="bg-indigo-600 p-8 rounded-[2rem] shadow-xl shadow-indigo-600/20 text-white group hover:scale-[1.02] transition-all duration-500">
            <div class="flex justify-between items-start mb-6">
                <div class="text-[10px] font-black text-indigo-200 uppercase tracking-[0.2em]">
                    {{ __('Distributed load') }}</div>
                <div class="p-2 bg-white/10 rounded-xl text-white"><svg xmlns="http://www.w3.org/2000/svg" width="16"
                        height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                        <polyline points="9 22 9 12 15 12 15 22" />
                    </svg></div>
            </div>
            <div class="text-3xl font-black leading-none">$ {{ number_format($totalAllocated, 0) }}</div>
            @php 
                $allocatedPercent = ($activeEnvelope && $activeEnvelope->total_amount > 0) ? ($totalAllocated / $activeEnvelope->total_amount) * 100 : 0;
            @endphp
            <div class="mt-6 flex items-center gap-3">

                                    <div class="flex-1 h-1.5 bg-white/20 rounded-full overflow-hidden">
                    <div class="h-full bg-white transition-all duration-1000" style="width: {{ $allocatedPercent }}%"></div>
                </div>
                <span class="text-[10px] font-black">{{ number_format($allocatedPercent, 1) }}%</span>
           </div>
        </div>
 
        <!-- Remaining -->
        <div class="bg-slate-900 p-8 rounded-[2rem] shadow-xl shadow-slate-900/20 text-white g
                    roup">
            <div class="flex justify-between items-start mb-6">

                       
                       
                         
                                    <div class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em]">{{ __('Liquidity / Space') }}</div>
                <div class="p-2 bg-slate-800 rounded-xl text-emerald-400"><svg xmlns="http://www.w3.org/2000/svg" width
               ="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></div>
            </div>
           <div class
                   ="text-3xl font-black leading-none {{ $remaining < 0 ? 'text-rose-400' : 'text-emerald-400' }}">$ {{ number_format($remaining, 0) }}</div>
            <div class="mt-4 flex animate-pulse">
                 <span class="px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-400 text-[9px] font-black uppercase tracking-widest">{{ $remaining < 0 ? __('Over Budget') : __('Available Authority') }}</span>
            </div>
       </div>
    </div>
 
    <!-- Main Content Tabs (Harmonized) -->
    <div class="bg-white rounded-[2rem] shadow-sm border border-slate-100 overflow-hidden">
        <div class="p-6 border-b border-slate-50 bg-slate-50/30">
            <div class="flex items-center gap-2 p-1.5 bg-slate-100/80 w-fit rounded-2xl border border-slate-200/50 backdrop-blur-sm">
                <button wire:click="$set('activeTab', 'hierarchy')" 
                    class="px-6 py-2.5 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all {{ $activeTab === 'hierarchy' ? 'bg-white text-indigo-600 shadow-md ring-1 ring-slate-200/50' : 'text-slate-400 hover:text-slate-600 hover:bg-white/50' }}">
                    {{ __('Hierarchy') }}
                </button>
                <button wire:click="$set('activeTab', 'department')" 
                    class="px-6 py-2.5 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all {{ $activeTab === 'department' ? 'bg-white text-indigo-600 shadow-md ring-1 ring-slate-200/50' : 'text-slate-400 hover:text-slate-600 hover:bg-white/50' }}">
                    {{ __('Department') }}
                </button>
                <button wire:click="$set('activeTab', 'summary')" 
                    class="px-6 py-2.5 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all {{ $activeTab === 'summary' ? 'bg-white text-indigo-600 shadow-md ring-1 ring-slate-200/50' : 'text-slate-400 hover:text-slate-600 hover:bg-white/50' }}">
                    {{ __('Summary') }}
                </button>
                <button wire:click="$set('activeTab', 'economic')" 
                    class="px-6 py-2.5 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all {{ $activeTab === 'economic' ? 'bg-white text-indigo-600 shadow-md ring-1 ring-slate-200/50' : 'text-slate-400 hover:text-slate-600 hover:bg-white/50' }}">
                    {{ __('Categories') }}
                </button>
                <button wire:click="$set('activeTab', 'program')" 
                    class="px-6 py-2.5 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all {{ $activeTab === 'program' ? 'bg-white text-indigo-600 shadow-md ring-1 ring-slate-200/50' : 'text-slate-400 hover:text-slate-600 hover:bg-white/50' }}">
                    {{ __('Programs') }}
                </button>
            </div>
        </div>

        <div class="p-6">
            @if($activeTab === 'hierarchy')
                @include('livewire.pages.budget.allocation-tabs.hierarchy')
            @elseif($activeTab === 'department')
                @include('livewire.pages.budget.allocation-tabs.department')
            @elseif($activeTab === 'summary')
                @include('livewire.pages.budget.allocation-tabs.summary')
            @elseif($activeTab === 'economic')
                @include('livewire.pages.budget.allocation-tabs.economic')
            @elseif($activeTab === 'program')
                @include('livewire.pages.budget.allocation-tabs.program')
            @endif
        </div>


    </div>
</div>
