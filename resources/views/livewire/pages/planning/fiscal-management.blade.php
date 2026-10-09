<?php

use Livewire\Volt\Component;
use App\Models\FiscalEnvelope;
use App\Models\FiscalEnvelopeCategoryAllocation;
use App\Models\EconomicClassification;
use App\Models\Activity;

new class extends Component {
    public $activeYear;
    public $envelopes = [];
    public $selectedEnvelopeId = null;
    public $allocations = [];
    public $showCreateModal = false;

    // New Envelope Form
    public $newYear, $newTitle, $newAmount, $newStart, $newDue;

    public function mount()
    {
        $this->loadEnvelopes();
        if (count($this->envelopes) > 0) {
            $this->selectedEnvelopeId = $this->envelopes[0]->id;
            $this->loadAllocations();
        }
    }

    public function loadEnvelopes()
    {
        $this->envelopes = FiscalEnvelope::orderBy('fiscal_year', 'desc')->get();
    }

    public function loadAllocations()
    {
        if ($this->selectedEnvelopeId) {
            $envelope = FiscalEnvelope::findOrFail($this->selectedEnvelopeId);

            // Sync planned from activities
            foreach (EconomicClassification::all() as $cat) {
                $planned = FiscalEnvelopeCategoryAllocation::calculatePlannedAmount($envelope->fiscal_year, $cat->id);
                FiscalEnvelopeCategoryAllocation::updateOrCreate(
                    ['fiscal_envelope_id' => $envelope->id, 'economic_classification_id' => $cat->id],
                    ['planned_amount' => $planned]
                );
            }

            $this->allocations = FiscalEnvelopeCategoryAllocation::where('fiscal_envelope_id', $this->selectedEnvelopeId)
                ->with('economicClassification')
                ->get();
        }
    }

    public function updatedSelectedEnvelopeId()
    {
        $this->loadAllocations();
    }

    public function updateAllocation($id, $amount)
    {
        abort_unless(auth()->user()->canAny(['create_fiscal_envelope', 'approve_fiscal_envelope']), 403);
        $allocation = FiscalEnvelopeCategoryAllocation::findOrFail($id);
        $allocation->update(['allocated_amount' => $amount]);
        $allocation->calculateVariance();
        $this->loadAllocations();
    }

    public function createEnvelope()
    {
        abort_unless(auth()->user()->canAny(['create_fiscal_envelope', 'approve_fiscal_envelope']), 403);
        $envelope = FiscalEnvelope::create([
            'fiscal_year' => $this->newYear,
            'reference_number' => $this->newTitle,
            'total_amount' => $this->newAmount,
            'status' => FiscalEnvelope::STATUS_DRAFT,
            'start_date' => $this->newStart,
            'due_date' => $this->newDue,
        ]);

        $this->loadEnvelopes();
        $this->selectedEnvelopeId = $envelope->id;
        $this->loadAllocations();
        $this->showCreateModal = false;
        $this->dispatch('toast', message: __('Fiscal Envelope created.'), type: 'success');
    }

    public function approveEnvelope()
    {
        abort_unless(auth()->user()->can('approve_fiscal_envelope'), 403);
        if ($this->selectedEnvelopeId) {
            $envelope = FiscalEnvelope::findOrFail($this->selectedEnvelopeId);
            $envelope->update(['status' => FiscalEnvelope::STATUS_APPROVED, 'approved_date' => now()]);
            $this->loadEnvelopes();
            $this->dispatch('toast', message: __('Envelope officially approved and active.'), type: 'success');
        }
    }
}; ?>

<div class="space-y-10 py-6 animate-in fade-in duration-700">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
        <div>
            <h1 class="page-title">{{ __('Fiscal Authority') }}</h1>
            <p class="page-subtitle">
                {{ __('Macro-Budgetary Ceiling & Consolidation Hub') }}
            </p>
        </div>
        <div class="flex gap-3">
            <x-ui.button wire:click="$set('showCreateModal', true)" variant="black" shadow="lg" class="!rounded-xl px-10 h-14">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="mr-3">
                    <path d="M5 12h14" />
                    <path d="M12 5v14" />
                </svg>
                <span class="text-xs font-semibold">{{ __('Establish New Context') }}</span>
            </x-ui.button>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-10">
        <!-- Sidebar: Fiscal Index -->
        <div class="lg:col-span-4 space-y-6">
            <div class="bg-white/80 backdrop-blur-sm rounded-xl p-8 border border-zinc-100 shadow-sm ring-1 ring-zinc-200/50">
                <div class="flex items-center justify-between mb-8">
                    <h3 class="text-[14px] font-semibold text-zinc-400 ">
                        {{ __('Historical Archive') }}
                    </h3>
                    <div class="flex gap-1">
                        <div class="w-1.5 h-1.5 rounded-full bg-indigo-500"></div>
                        <div class="w-1.5 h-1.5 rounded-full bg-zinc-200"></div>
                        <div class="w-1.5 h-1.5 rounded-full bg-zinc-200"></div>
                    </div>
                </div>
                
                <div class="space-y-3 max-h-[600px] overflow-y-auto pr-2 custom-scrollbar">
                    @foreach($envelopes as $env)
                        <button wire:click="$set('selectedEnvelopeId', {{ $env->id }})"
                            class="group w-full text-left p-6 rounded-xl border-2 transition-all duration-500 relative overflow-hidden {{ $selectedEnvelopeId == $env->id ? 'bg-zinc-900 border-zinc-900 shadow-sm ' : 'bg-white border-zinc-50 hover:border-indigo-100 hover:shadow-sm hover:hover:-translate-y-1' }}">
                            
                            @if($selectedEnvelopeId == $env->id)
                                <div class="absolute top-0 right-0 w-40 h-40 bg-indigo-500/20 rounded-full blur-3xl -mr-20 -mt-20 animate-pulse"></div>
                            @endif

                            <div class="flex justify-between items-center relative z-10">
                                <div class="space-y-1">
                                    <div class="text-2xl font-semibold leading-none italic {{ $selectedEnvelopeId == $env->id ? 'text-white' : 'text-zinc-900 group-hover:text-indigo-600' }} transition-colors">
                                        {{ $env->fiscal_year }}
                                    </div>
                                    <div class="text-xs font-semibold {{ $selectedEnvelopeId == $env->id ? 'text-indigo-400' : 'text-zinc-400' }}">
                                        {{ str($env->reference_number)->limit(20) }}
                                    </div>
                                </div>
                                <div class="text-right flex flex-col items-end">
                                    <div class="text-sm font-semibold {{ $selectedEnvelopeId == $env->id ? 'text-white' : 'text-zinc-900' }} tracking-tight">
                                        $ {{ number_format($env->total_amount / 1000000, 1) }}M
                                    </div>
                                    <div class="mt-3">
                                        @php
                                            $stVariant = $env->status === 'Approved' ? 'bg-emerald-500' : 'bg-amber-500';
                                            if($selectedEnvelopeId == $env->id) $stVariant = 'bg-white';
                                        @endphp
                                        <div class="flex items-center gap-2">
                                            <div class="w-1.5 h-1.5 rounded-full {{ $stVariant }} {{ $env->status === 'Draft' ? 'animate-pulse' : '' }}"></div>
                                            <span class="text-xs font-semibold {{ $selectedEnvelopeId == $env->id ? 'text-white' : 'text-zinc-400' }}">
                                                {{ __($env->status) }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </button>
                    @endforeach
                </div>
            </div>
            
            <!-- Quick Insights Card -->
            <!-- <div class="bg-indigo-600 rounded-xl p-8 text-white relative overflow-hidden group">
                <div class="absolute bottom-0 right-0 w-32 h-32 bg-white/10 rounded-full blur-2xl -mr-16 -mb-16 group-hover:bg-white/20 transition-all"></div>
                <h4 class="text-xs font-semibold opacity-60 mb-6">{{ __('System Health') }}</h4>
                <div class="space-y-4">
                    <div class="flex justify-between items-center">
                        <span class="text-xs font-bold">{{ __('Active Cycles') }}</span>
                        <span class="px-2 py-0.5 bg-white/20 rounded-md text-xs font-semibold">0{{ $envelopes->where('status', 'Approved')->count() }}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-xs font-bold">{{ __('Consolidation Rate') }}</span>
                        <span class="px-2 py-0.5 bg-white/20 rounded-md text-xs font-semibold">94.2%</span>
                    </div>
                </div>
            </div> -->
        </div>

        <!-- Main Workspace -->
        <div class="lg:col-span-8 space-y-10">
            @if($this->selectedEnvelopeId)
                @php $env = $envelopes->find($selectedEnvelopeId); @endphp
                
                <!-- Dynamic Hero Workspace -->
                <div class="bg-white rounded-xl p-12 border border-zinc-100 shadow-sm relative overflow-hidden group">
                    <div class="absolute top-0 right-0 w-[600px] h-[600px] bg-zinc-50 rounded-full blur-[120px] -mr-80 -mt-80"></div>
                    
                    <div class="relative z-10">
                        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-12 mb-12">
                            <div class="space-y-6">
                                <div class="flex items-center gap-4">
                                    <div class="flex -space-x-2">
                                        <div class="w-8 h-8 rounded-full bg-zinc-900 border-2 border-white flex items-center justify-center text-xs font-semibold text-white">FY</div>
                                        <div class="w-8 h-8 rounded-full bg-indigo-500 border-2 border-white flex items-center justify-center text-xs font-semibold text-white">CE</div>
                                    </div>
                                    <div class="h-px w-10 bg-zinc-200"></div>
                                    <span class="text-xs font-semibold text-indigo-500 ">{{ __('Authorized Authority') }}</span>
                                </div>
                                <h2 class="text-3xl font-semibold text-zinc-900 leading-none italic group-hover:translate-x-2 transition-transform duration-700">{{ $env->fiscal_year }}</h2>
                                <p class="text-lg font-bold text-zinc-400 tracking-tight tracking-tight">{{ $env->reference_number }}</p>
                            </div>

                            <div class="flex flex-col items-end gap-6">
                                @if($env->status === 'Approved')
                                    <div class="flex items-center gap-4 bg-emerald-50 px-6 py-4 rounded-xl border border-emerald-100">
                                        <div class="w-3 h-3 rounded-full bg-emerald-500 animate-pulse"></div>
                                        <div class="text-right">
                                            <div class="text-xs font-semibold text-emerald-600 ">{{ __('Cycle Finalized') }}</div>
                                            <div class="text-xs font-bold text-zinc-900">{{ __('Approved on') }} {{ optional($env->approved_date)->format('M d, Y') ?: now()->format('M d, Y') }}</div>
                                        </div>
                                    </div>
                                @else
                                    <button wire:click="approveEnvelope"
                                        wire:confirm="{{ __('Seal this fiscal context? This action cannot be reversed.') }}"
                                        class="px-10 py-5 bg-indigo-600 hover:bg-zinc-900 text-white rounded-xl text-xs font-semibold transition-all shadow-sm hover:">
                                        {{ __('Activate Context') }}
                                    </button>
                                @endif
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-10">
                            <div class="bg-zinc-50/50 p-8 rounded-xl border border-zinc-100">
                                <p class="text-xs font-semibold text-zinc-400 mb-3">{{ __('Global OGE Ceiling') }}</p>
                                <div class="text-lg font-semibold text-zinc-900 leading-none">$ {{ number_format($env->total_amount, 0) }}</div>
                            </div>

                            <div class="bg-white p-8 rounded-xl border border-zinc-100 shadow-sm md:col-span-2 flex items-center justify-between">
                                <div class="space-y-4 flex-1 pr-10 border-r border-zinc-100">
                                    <p class="text-xs font-semibold text-indigo-400 ">{{ __('Utilization Strategy') }}</p>
                                    @php
                                        $totalAlloc = collect($allocations)->sum('allocated_amount');
                                        $utilRate = $env->total_amount > 0 ? ($totalAlloc / $env->total_amount) * 100 : 0;
                                    @endphp
                                    <div class="flex items-baseline gap-2">
                                        <span class="text-lg font-semibold text-zinc-900 leading-none">{{ number_format($utilRate, 1) }}%</span>
                                        <span class="text-xs font-bold text-zinc-400 ">{{ __('Consolidated') }}</span>
                                    </div>
                                    <div class="h-1.5 w-full bg-zinc-50 rounded-full overflow-hidden">
                                        <div class="h-full bg-indigo-600 transition-all duration-1000" style="width: {{ $utilRate }}%"></div>
                                    </div>
                                </div>
                                <div class="pl-10 text-right">
                                    <p class="text-xs font-semibold text-zinc-400 mb-2">{{ __('Remaining Gap') }}</p>
                                    <div class="text-lg font-semibold {{ ($env->total_amount - $totalAlloc) < 0 ? 'text-rose-600' : 'text-zinc-900' }}">
                                        $ {{ number_format($env->total_amount - $totalAlloc, 0) }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Strategic Allocation Matrix -->
                <div class="bg-white rounded-xl shadow-sm border border-zinc-100 overflow-hidden ring-1 ring-zinc-100/50 relative">
                    <div class="px-10 py-8 border-b border-zinc-50 bg-zinc-50/30 flex items-center justify-between">
                        <div>
                            <h3 class="text-xs font-semibold text-zinc-900 ">{{ __('Economic Category Distribution') }}</h3>
                            <p class="text-xs font-bold text-zinc-400 mt-1 italic">{{ __('Refining ceilings for programmatic units') }}</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-xs font-semibold text-zinc-400 ">{{ __('Precision Mode') }}</span>
                            <div class="w-8 h-4 bg-indigo-600 rounded-full relative">
                                <div class="absolute right-0.5 top-0.5 w-3 h-3 bg-white rounded-full"></div>
                            </div>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-zinc-100">
                            <thead>
                                <tr class="bg-white">
                                    <th class="px-10 py-6 text-left text-xs font-semibold text-zinc-400">{{ __('Economic Node') }}</th>
                                    <th class="px-10 py-6 text-right text-xs font-semibold text-zinc-400">{{ __('Requirement Audit (Planned)') }}</th>
                                    <th class="px-10 py-6 text-right text-xs font-semibold text-zinc-400">{{ __('Assigned Ceiling') }}</th>
                                    <th class="px-10 py-6 text-right text-xs font-semibold text-zinc-400">{{ __('Audit Variance') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-50 bg-white">
                                @foreach($allocations as $alloc)
                                    <tr class="hover:bg-zinc-50/50 transition-all duration-300 group">
                                        <td class="px-10 py-6">
                                            <div class="flex items-center gap-4">
                                                <div class="w-10 h-10 rounded-xl bg-zinc-100 flex items-center justify-center text-xs font-semibold text-zinc-600 group-hover:bg-indigo-600 group-hover:text-white transition-all duration-500">{{ $alloc->economicClassification->code }}</div>
                                                <div>
                                                    <div class="text-xs font-semibold text-zinc-900 group-hover:text-indigo-600 transition-colors">{{ $alloc->economicClassification->name }}</div>
                                                    <div class="text-xs font-semibold text-zinc-400 mt-1">{{ __('Structural Classification') }}</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-10 py-6 text-right">
                                            <div class="text-xs font-bold text-zinc-500 italic">$ {{ number_format($alloc->planned_amount, 2) }}</div>
                                        </td>
                                        <td class="px-10 py-6 text-right">
                                            <div class="inline-flex items-center relative group/input">
                                                <span class="absolute left-4 text-zinc-300 font-bold text-xs">$</span>
                                                <input type="number" 
                                                    value="{{ (float) $alloc->allocated_amount }}"
                                                    wire:blur="updateAllocation({{ $alloc->id }}, $event.target.value)"
                                                    class="pl-8 pr-4 py-3 w-44 bg-zinc-50 border-transparent focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-500/5 text-right text-xs font-semibold text-zinc-900 rounded-xl transition-all shadow-inner"
                                                    {{ $env->status === 'Approved' ? 'disabled' : '' }}>
                                            </div>
                                        </td>
                                        <td class="px-10 py-6 text-right">
                                            @php $isOver = $alloc->variance_amount < 0; @endphp
                                            <div class="flex items-center justify-end gap-3">
                                                <div class="text-right">
                                                    <div class="text-xs font-semibold {{ $isOver ? 'text-rose-600' : 'text-emerald-600' }}">
                                                        {{ $isOver ? '-' : '+' }} $ {{ number_format(abs($alloc->variance_amount), 2) }}
                                                    </div>
                                                    <div class="text-xs font-semibold opacity-40">{{ $isOver ? __('Over Budget') : __('Under Ceiling') }}</div>
                                                </div>
                                                <div class="w-1.5 h-1.5 rounded-full {{ $isOver ? 'bg-rose-500 ' : 'bg-emerald-500' }}"></div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="bg-white border-t-2 border-zinc-100">
                                    <td class="px-10 py-8 text-xs font-semibold text-indigo-600">{{ __('Aggregated Matrix Totals') }}</td>
                                    <td class="px-10 py-8 text-right">
                                        <div class="text-[13px] font-semibold text-zinc-400">$ {{ number_format(collect($allocations)->sum('planned_amount'), 2) }}</div>
                                    </td>
                                    <td class="px-10 py-8 text-right underline decoration-indigo-500/20 underline-offset-8">
                                        <div class="text-[15px] font-semibold text-zinc-900">$ {{ number_format(collect($allocations)->sum('allocated_amount'), 2) }}</div>
                                    </td>
                                    @php
                                        $finalTotalPlanned = collect($allocations)->sum('planned_amount');
                                        $finalTotalAlloc = collect($allocations)->sum('allocated_amount');
                                        $finalVar = $finalTotalAlloc - $finalTotalPlanned;
                                    @endphp
                                    <td class="px-10 py-8 text-right">
                                        <div class="inline-block px-5 py-2 rounded-xl {{ $finalVar < 0 ? 'bg-rose-50 text-rose-600' : 'bg-emerald-50 text-emerald-600' }} text-xs font-semibold ">
                                            {{ $finalVar < 0 ? '-' : '+' }} $ {{ number_format(abs($finalVar), 2) }}
                                        </div>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            @else
                <div class="bg-white rounded-xl border-2 border-dashed border-zinc-100 p-32 text-center animate-pulse">
                    <div class="w-20 h-20 bg-zinc-50 rounded-full flex items-center justify-center mx-auto mb-8">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-zinc-200"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>
                    </div>
                    <p class="text-zinc-400 italic text-sm font-bold ">
                        {{ __('Initialization Required: Select Fiscal Context') }}</p>
                </div>
            @endif
        </div>
    </div>

    <!-- Create Context Modal -->
    @if($showCreateModal)
        <div class="fixed inset-0 z-[100] flex items-center justify-center p-6 sm:p-24 bg-zinc-900/60 backdrop-blur-sm animate-in fade-in transition-all">
            <div class="bg-white rounded-xl shadow-sm w-full max-w-2xl overflow-hidden border border-white/20 relative">
                <div class="absolute top-0 right-0 w-64 h-64 bg-indigo-500/5 rounded-full blur-[80px] -mr-32 -mt-32"></div>
                
                <div class="px-12 py-10 border-b border-zinc-50 flex justify-between items-center relative z-10">
                    <div>
                        <h3 class="text-3xl font-semibold text-zinc-900 italic ">{{ __('Establish Context') }}</h3>
                        <p class="text-xs font-semibold text-zinc-400 mt-2">
                            {{ __('Seeding new fiscal authority cycle') }}</p>
                    </div>
                    <button wire:click="$set('showCreateModal', false)"
                        class="w-12 h-12 flex items-center justify-center bg-zinc-50 hover:bg-zinc-100 rounded-full text-zinc-400 transition-all group">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="group-hover:rotate-90 transition-transform duration-500">
                            <path d="M18 6 6 18" />
                            <path d="m6 6 12 12" />
                        </svg>
                    </button>
                </div>

                <div class="p-12 space-y-10 relative z-10">
                    <div class="grid grid-cols-2 gap-10">
                        <div class="space-y-3">
                            <label class="text-xs font-semibold text-zinc-400 ml-1">{{ __('Fiscal Year') }}</label>
                            <input type="number" wire:model="newYear"
                                class="w-full bg-zinc-50 border-transparent rounded-xl focus:ring-4 focus:ring-indigo-500/10 focus:bg-white focus:border-indigo-500 font-semibold text-zinc-900 p-4 transition-all shadow-inner">
                        </div>
                        <div class="space-y-3">
                            <label class="text-xs font-semibold text-zinc-400 ml-1">{{ __('Global Ceiling ($)') }}</label>
                            <input type="number" wire:model="newAmount"
                                class="w-full bg-zinc-50 border-transparent rounded-xl focus:ring-4 focus:ring-indigo-500/10 focus:bg-white focus:border-indigo-500 font-semibold text-zinc-900 p-4 transition-all shadow-inner">
                        </div>
                    </div>

                    <div class="space-y-3">
                        <label class="text-xs font-semibold text-zinc-400 ml-1">{{ __('Operational Reference') }}</label>
                        <input type="text" wire:model="newTitle"
                            class="w-full bg-zinc-50 border-transparent rounded-xl focus:ring-4 focus:ring-indigo-500/10 focus:bg-white focus:border-indigo-500 font-semibold text-zinc-900 p-4 transition-all shadow-inner"
                            placeholder="e.g. OGE 2026 Phase A">
                    </div>

                    <div class="grid grid-cols-2 gap-10 pt-6">
                        <div class="space-y-3">
                            <label class="text-xs font-semibold text-zinc-400 ml-1">{{ __('Cycle Start') }}</label>
                            <input type="date" wire:model="newStart"
                                class="w-full bg-zinc-50 border-transparent rounded-xl focus:ring-4 focus:ring-indigo-500/10 focus:bg-white focus:border-indigo-500 font-bold p-4 shadow-inner">
                        </div>
                        <div class="space-y-3">
                            <label class="text-xs font-semibold text-zinc-400 ml-1">{{ __('Final Submission Due') }}</label>
                            <input type="date" wire:model="newDue"
                                class="w-full bg-zinc-50 border-transparent rounded-xl focus:ring-4 focus:ring-indigo-500/10 focus:bg-white focus:border-indigo-500 font-bold p-4 shadow-inner">
                        </div>
                    </div>
                </div>

                <div class="px-12 py-8 bg-zinc-50/50 flex justify-end gap-6 border-t border-zinc-50 relative z-10">
                    <button wire:click="$set('showCreateModal', false)"
                        class="px-8 py-4 text-xs font-semibold text-zinc-400 hover:text-zinc-900 transition-colors">{{ __('Cancel') }}</button>
                    <button wire:click="createEnvelope"
                        class="px-12 py-4 bg-indigo-600 text-white text-xs font-semibold rounded-xl hover:bg-indigo-700 transition-all shadow-sm hover:">
                        {{ __('Initialize Cycle') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>