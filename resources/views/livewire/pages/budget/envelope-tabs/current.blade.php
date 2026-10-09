<div class="space-y-6 animate-in fade-in zoom-in-95 duration-300">
    {{-- Header --}}
    <div class="flex justify-between items-start">
        <div>
            <h3 class="text-xl font-semibold text-zinc-900 tracking-tight leading-none">{{ __('Fiscal Envelope (OGE)') }}</h3>
            <p class="page-subtitle">{{ __('Ministry budget allocation approved by Parliament') }}</p>
        </div>
        <div class="flex items-center gap-3">
            @if($activeEnvelope->status === App\Models\FiscalEnvelope::STATUS_APPROVED && (auth()->user()->isAdministrator() || auth()->user()->isMinister()))
                <x-ui.button wire:click="openRedistributionModal({{ $activeEnvelope->id }})" wire:loading.attr="disabled" variant="black" shadow="lg" size="md">
                    <svg wire:loading.class="animate-spin" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="mr-2"><path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/></svg>
                    <span wire:loading.remove>{{ __('Redistribute OGE') }}</span>
                    <span wire:loading>{{ __('Loading...') }}</span>
                </x-ui.button>
            @endif
            
            {{-- 
            @if((int) $activeEnvelope->fiscal_year === (int) App\Models\SystemSetting::get('planning_active_year'))
                <x-ui.badge variant="black" pulse="true">{{ __('System Active') }}</x-ui.badge>
            @endif
             --}}

            @if($activeEnvelope->isActiveByDate())
                <x-ui.badge variant="green" pulse="true">{{ __('Active Now') }}</x-ui.badge>
            @endif

            @if($activeEnvelope->status === App\Models\FiscalEnvelope::STATUS_APPROVED)
                <x-ui.badge variant="blue">{{ __('Approved') }}</x-ui.badge>
            @elseif($activeEnvelope->status === App\Models\FiscalEnvelope::STATUS_PENDING)
                <x-ui.badge variant="amber">{{ __('Pending Approval') }}</x-ui.badge>
            @elseif($activeEnvelope->status === App\Models\FiscalEnvelope::STATUS_CLOSED)
                <x-ui.badge variant="red">{{ __('Closed') }}</x-ui.badge>
            @else
                <x-ui.badge variant="gray">{{ __('Draft') }}</x-ui.badge>
            @endif

            {{-- Action Buttons --}}
            <div class="flex items-center gap-2">
                {{-- Admin Actions --}}
                @if(auth()->user()->isAdministrator())
                    {{-- Edit Button (Draft/Pending only or always for Admin?) --}}
                    @if($activeEnvelope->status == App\Models\FiscalEnvelope::STATUS_DRAFT)
                        <button wire:click="openModal({{ $activeEnvelope->fiscal_year }})"
                                class="inline-flex items-center gap-2 rounded-lg bg-white px-3 py-2 text-xs font-medium text-zinc-700 shadow-sm border border-zinc-200 hover:bg-zinc-50 hover:border-zinc-300 transition-all">
                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                            {{ __('Edit') }}
                        </button>
                    @endif

                    {{-- Submit for Approval (Draft only) --}}
                    @if($activeEnvelope->status === 'Draft')
                        <button wire:click="submitForApproval({{ $activeEnvelope->id }})"
                                class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-3 py-2 text-xs font-medium text-white shadow-sm hover:bg-indigo-700 transition-all">
                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
                            {{ __('Submit for Approval') }}
                        </button>
                    @endif

                @endif

                {{-- Minister Actions --}}
                @if(auth()->user()->isMinister() && $activeEnvelope->status === App\Models\FiscalEnvelope::STATUS_PENDING)
                    <button wire:click="approve({{ $activeEnvelope->id }})"
                            wire:confirm="{{ __('Are you sure you want to approve this fiscal envelope?') }}"
                            class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-3 py-2 text-xs font-medium text-white shadow-sm hover:bg-emerald-700 transition-all">
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                        {{ __('Approve') }}
                    </button>
                    <button wire:click="reject({{ $activeEnvelope->id }})"
                            wire:confirm="{{ __('Are you sure you want to reject this fiscal envelope?') }}"
                            class="inline-flex items-center gap-2 rounded-lg bg-rose-600 px-3 py-2 text-xs font-medium text-white shadow-sm hover:bg-rose-700 transition-all">
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m18 6-12 12"/><path d="m6 6 12 12"/></svg>
                        {{ __('Reject') }}
                    </button>
                @endif
            </div>
        </div>
    </div>

    @if($activeEnvelope->status === App\Models\FiscalEnvelope::STATUS_PENDING)
        <div class="flex items-start gap-3 px-4 py-3.5 rounded-xl bg-amber-50 border border-amber-100">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                class="flex-shrink-0 text-amber-600 mt-0.5">
                <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/>
                <path d="M12 9v4"/><path d="M12 17h.01"/>
            </svg>
            <div>
                <p class="text-sm font-semibold text-amber-900">{{ __('Pending Ministerial Approval') }}</p>
                <p class="text-xs text-amber-700 opacity-80 mt-0.5">{{ __('This envelope has been submitted and is awaiting the Minister\'s decision.') }}</p>
            </div>
        </div>
    @endif

    {{-- Closed Fiscal Year Alert --}}
    @if($activeEnvelope->status === App\Models\FiscalEnvelope::STATUS_CLOSED)
        <div class="flex items-start gap-3 px-4 py-3.5 rounded-xl bg-zinc-100 border border-zinc-200">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                class="flex-shrink-0 text-zinc-500 mt-0.5">
                <rect width="18" height="11" x="3" y="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
            <div>
                <p class="text-sm font-semibold text-zinc-700">{{ __('Fiscal Year Closed') }}</p>
                <p class="text-xs text-zinc-500 mt-0.5">
                    {{ __('This fiscal year (:from – :to) is closed for adjustments.', [
                        'from' => now()->startOfYear()->format('d F'),
                        'to'   => now()->endOfYear()->format('d F Y'),
                    ]) }}
                </p>
            </div>
        </div>
    @endif
    <!-- OGE Allocation Details Card -->
    <div class="rounded-xl border border-zinc-100 bg-white shadow-sm overflow-hidden">
        <div class="p-8 border-b border-zinc-50 bg-gradient-to-r from-zinc-50/50 to-white">
            <div class="flex items-center gap-3">
                <div class="p-2.5 bg-zinc-100 rounded-xl text-zinc-600">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M7 7h10"/><path d="M7 12h10"/><path d="M7 17h10"/></svg>
                </div>
                <div>
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-zinc-900">{{ __('OGE Allocation Details') }}</h4>
                    <p class="text-[10px] text-zinc-400 mt-0.5 font-medium uppercase tracking-wide">{{ __('Fiscal Year') }} {{ $activeEnvelope->fiscal_year }}</p>
                </div>
            </div>
        </div>
        <div class="p-8">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-y-6 gap-x-12">
                <div>
                    <div class="space-y-4">
                        <div>
                            <p class="text-[10px] font-medium text-zinc-500 uppercase tracking-wide">{{ __('Ministry') }}</p>
                            <p class="text-sm font-bold text-zinc-900 mt-2">{{ __('Ministry of State Administration') }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-medium text-zinc-500 uppercase tracking-wide">{{ __('Approval Date') }}</p>
                            <p class="text-sm font-bold text-zinc-900 mt-2">{{ $activeEnvelope->approved_date ? \Carbon\Carbon::parse($activeEnvelope->approved_date)->format('Y-m-d') : '-' }}</p>
                        </div>
                    </div>
                </div>
                <div>
                    <div class="space-y-4">
                        <div>
                            <p class="text-[10px] font-medium text-zinc-500 uppercase tracking-wide">{{ __('Source Authority') }}</p>
                            <p class="text-sm font-bold text-zinc-900 mt-2">{{ $activeEnvelope->source_authority ?: __('Direcção Nacional do Orçamento (DNO)') }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-medium text-zinc-500 uppercase tracking-wide">{{ __('Reference Number') }}</p>
                            <p class="text-sm font-mono font-bold text-zinc-600 mt-2">{{ $activeEnvelope->reference_number ?? 'N/A' }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-medium text-zinc-500 uppercase tracking-wide">{{ __('Validity Period') }}</p>
                            <p class="text-sm font-bold text-zinc-900 mt-2">
                                {{ $activeEnvelope->start_date ? \Carbon\Carbon::parse($activeEnvelope->start_date)->format('M Y') : 'N/A' }} - 
                                {{ $activeEnvelope->due_date ? \Carbon\Carbon::parse($activeEnvelope->due_date)->format('d M Y') : 'N/A' }}
                            </p>



                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-8 pt-8 border-t border-zinc-100">
                 <p class="text-[10px] font-medium text-zinc-500 uppercase tracking-wide mb-2">{{ __('Total Fiscal Envelope') }}</p>
                 <p class="text-4xl font-semibold text-zinc-900 leading-none tracking-tight">$ {{ number_format($activeEnvelope->total_amount, 2) }}</p>
            </div>
        </div>
    </div>

    
    <!-- Tab Navigation -->
    <div class="flex items-center gap-2 p-1.5 bg-zinc-100/80 w-fit rounded-xl border border-zinc-200/50 backdrop-blur-sm shadow-sm">
        <button wire:click="$set('detailsTab', 'distribution')" 
            class="px-6 py-2.5 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all {{ $detailsTab === 'distribution' ? 'bg-white text-indigo-600 shadow-md ring-1 ring-zinc-200/50' : 'text-zinc-400 hover:text-zinc-600 hover:bg-white/50' }}">
            <div class="flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/>
                </svg>
                {{ __('Distribution Summary') }}
            </div>
        </button>
        <button wire:click="$set('detailsTab', 'categories')" 
            class="px-6 py-2.5 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all {{ $detailsTab === 'categories' ? 'bg-white text-indigo-600 shadow-md ring-1 ring-zinc-200/50' : 'text-zinc-400 hover:text-zinc-600 hover:bg-white/50' }}">
            <div class="flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <rect width="18" height="18" x="3" y="3" rx="2"/><path d="M7 7h10"/><path d="M7 12h10"/><path d="M7 17h10"/>
                </svg>
                {{ __('Economic Categories') }}
            </div>
        </button>
    </div>

    @if($detailsTab === 'distribution')
        <!-- Distribution Summary Section -->
        <div class="rounded-xl border border-zinc-100 bg-white shadow-sm overflow-hidden">
            <div class="p-8 border-b border-zinc-50 bg-gradient-to-r from-indigo-50/30 to-white">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-900 leading-none">{{ __('Distribution Summary') }}</h3>
                        <p class="text-[10px] text-zinc-400 mt-2 font-medium uppercase tracking-wide">{{ __('Fiscal envelope allocation vs. PAA planned amounts by economic category') }}</p>
                    </div>
                    <div class="flex items-center gap-2 px-4 py-2 bg-white rounded-xl border border-zinc-100 shadow-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="text-indigo-600">
                            <path d="M12 2v20"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                        </svg>
                        <span class="text-[10px] font-medium text-zinc-600 uppercase tracking-wide">{{ __('FY') }} {{ $activeEnvelope->fiscal_year }}</span>
                    </div>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs border-collapse">
                    <thead class="bg-zinc-50/50 text-zinc-400 font-semibold uppercase tracking-wide border-b border-zinc-100">
                        <tr>
                            <th class="px-8 py-5">{{ __('Economic Category') }}</th>
                            <th class="px-8 py-5 text-right">{{ __('OGE Allocated') }}</th>
                            <th class="px-8 py-5 text-right">{{ __('PAA Planned') }}</th>
                            <th class="px-8 py-5 text-right">{{ __('Variance') }}</th>
                            <th class="px-8 py-5 text-right">{{ __('Allocation %') }}</th>
                            <th class="px-8 py-5 text-center">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @php
                            $totalOGEAllocated = 0;
                            $totalPAAPlanned = 0;
                        @endphp

                        @foreach($activeEnvelope->categoryAllocations as $allocation)
                            @php
                                $ogeAmount = (float) $allocation->allocated_amount;
                                $paaPlanned = (float) $allocation->planned_amount;
                                $variance = $ogeAmount - $paaPlanned;
                                $allocationPercent = $activeEnvelope->total_amount > 0 ? ($ogeAmount / $activeEnvelope->total_amount) * 100 : 0;
                                $cat = $allocation->economicClassification;

                                $totalOGEAllocated += $ogeAmount;
                                $totalPAAPlanned += $paaPlanned;
                            @endphp

                            <tr class="hover:bg-zinc-50/50 transition-colors group">
                                <td class="px-8 py-5">
                                    <div class="flex items-center gap-3">
                                        <div class="h-10 w-10 rounded-xl bg-gradient-to-br from-indigo-50 to-indigo-100 flex items-center justify-center text-indigo-600 shrink-0 font-black text-[11px] shadow-sm">
                                            {{ $cat->code }}
                                        </div>
                                        <div>
                                            <div class="font-bold text-zinc-900 leading-tight">{{ $cat->name }}</div>
                                            <div class="text-[10px] text-zinc-400 mt-0.5 leading-tight">{{ __($cat->description) }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-8 py-5 text-right">
                                    <div class="font-semibold text-zinc-900">$ {{ number_format($ogeAmount, 2) }}</div>
                                    <div class="text-[9px] text-zinc-400 mt-0.5 uppercase tracking-widest font-bold">{{ __('Envelope') }}</div>
                                </td>
                                <td class="px-8 py-5 text-right">
                                    <div class="font-bold text-indigo-600">$ {{ number_format($paaPlanned, 2) }}</div>
                                    <div class="text-[9px] text-zinc-400 mt-0.5 uppercase tracking-widest font-bold">{{ __('Activities') }}</div>
                                </td>
                                <td class="px-8 py-5 text-right">
                                    <div class="font-bold {{ $variance >= 0 ? 'text-green-600' : 'text-rose-600' }}">
                                        {{ $variance >= 0 ? '+' : '' }}$ {{ number_format($variance, 2) }}
                                    </div>
                                    <div class="text-[9px] {{ $variance >= 0 ? 'text-green-500' : 'text-rose-500' }} mt-0.5 uppercase tracking-widest font-bold">
                                        {{ $variance >= 0 ? __('Surplus') : __('Deficit') }}
                                    </div>
                                </td>
                                <td class="px-8 py-5 text-right">
                                    <div class="flex flex-col items-end gap-2">
                                        <span class="font-semibold text-zinc-900">{{ number_format($allocationPercent, 1) }}%</span>
                                        <div class="h-1.5 w-24 rounded-full bg-zinc-100 overflow-hidden shadow-inner">
                                            <div class="h-full bg-gradient-to-r from-indigo-500 to-indigo-600 transition-all duration-700" style="width: {{ min($allocationPercent, 100) }}%"></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-8 py-5 text-center">
                                    @if(abs($variance) < 0.01)
                                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-green-50 text-green-700 text-[9px] font-black uppercase tracking-[0.15em] border border-green-100">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                            {{ __('Balanced') }}
                                        </span>
                                    @elseif($variance > 0)
                                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-blue-50 text-blue-700 text-[9px] font-black uppercase tracking-[0.15em] border border-blue-100">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 7-7 7 7"/><path d="M12 19V5"/></svg>
                                            {{ __('Over') }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-amber-50 text-amber-700 text-[9px] font-black uppercase tracking-[0.15em] border border-amber-100">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m12 5 0 14"/><path d="m19 12-7 7-7-7"/></svg>
                                            {{ __('Under') }}
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach

                        <!-- Totals Row -->
                        <tr class="bg-gradient-to-r from-gray-50 to-white border-t-2 border-gray-200 font-black">
                            <td class="px-6 py-5">
                                <div class="flex items-center gap-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="text-gray-600">
                                        <path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/>
                                    </svg>
                                    <span class="text-gray-900 uppercase tracking-wider">{{ __('Total') }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-5 text-right">
                                <div class="text-gray-900">$ {{ number_format($totalOGEAllocated, 2) }}</div>
                            </td>
                            <td class="px-6 py-5 text-right">
                                <div class="text-indigo-600">$ {{ number_format($totalPAAPlanned, 2) }}</div>
                            </td>
                            <td class="px-6 py-5 text-right">
                                @php $totalVariance = $totalOGEAllocated - $totalPAAPlanned; @endphp
                                <div class="{{ $totalVariance >= 0 ? 'text-green-600' : 'text-rose-600' }}">
                                    {{ $totalVariance >= 0 ? '+' : '' }}$ {{ number_format($totalVariance, 2) }}
                                </div>
                            </td>
                            <td class="px-6 py-5 text-right">
                                <div class="text-gray-900">100.0%</div>
                            </td>
                            <td class="px-6 py-5"></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Summary Footer -->
            <div class="p-6 border-t border-gray-200 bg-gray-50/30">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="flex items-center gap-4">
                        <div class="h-12 w-12 rounded-xl bg-indigo-100 flex items-center justify-center shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="text-indigo-600">
                                <rect width="20" height="12" x="2" y="6" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01"/><path d="M18 12h.01"/>
                            </svg>
                        </div>
                        <div>
                            <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest">{{ __('Total OGE Allocated') }}</p>
                            <p class="text-lg font-black text-gray-900 leading-none mt-1">$ {{ number_format($totalOGEAllocated, 2) }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="h-12 w-12 rounded-xl bg-blue-100 flex items-center justify-center shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="text-blue-600">
                                <path d="M9 11 4 6l5-5 5 5-5 5Z"/><path d="M13 13 8 8l5-5 5 5-5 5Z"/><path d="M16 16l-5-5 5-5 5 5-5 5Z"/><path d="M11 21l-5-5 5-5 5 5-5 5Z"/>
                            </svg>
                        </div>
                        <div>
                            <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest">{{ __('Total PAA Planned') }}</p>
                            <p class="text-lg font-black text-blue-600 leading-none mt-1">$ {{ number_format($totalPAAPlanned, 2) }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="h-12 w-12 rounded-xl {{ $totalVariance >= 0 ? 'bg-green-100' : 'bg-rose-100' }} flex items-center justify-center shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="{{ $totalVariance >= 0 ? 'text-green-600' : 'text-rose-600' }}">
                                <path d="M12 2v20"/><path d="m17 5-5-3-5 3"/><path d="m17 19-5 3-5-3"/>
                            </svg>
                        </div>
                        <div>
                            <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest">{{ __('Net Variance') }}</p>
                            <p class="text-lg font-black {{ $totalVariance >= 0 ? 'text-green-600' : 'text-rose-600' }} leading-none mt-1">
                                {{ $totalVariance >= 0 ? '+' : '' }}$ {{ number_format($totalVariance, 2) }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if($detailsTab === 'categories')
        <!-- Economic Categories Allocation Table -->
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
            <div class="p-6 border-b border-gray-100">
                <h3 class="text-sm font-bold uppercase tracking-wider text-gray-800 leading-none">{{ __('Economic Categories Allocation') }}</h3>
                <p class="text-xs text-gray-500 mt-2">{{ __('Budget breakdown by economic classification (C1-C5)') }}</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs border-collapse">
                    <thead class="bg-gray-50 text-gray-400 font-medium uppercase tracking-wide border-b border-gray-100">
                        <tr>
                            <th class="px-6 py-4">{{ __('Category') }}</th>
                            <th class="px-6 py-4 text-right">{{ __('Allocated') }}</th>
                            <th class="px-6 py-4 text-right">{{ __('Committed') }}</th>
                            <th class="px-6 py-4 text-right">{{ __('Spent') }}</th>
                            <th class="px-6 py-4 text-right whitespace-nowrap">{{ __('Available') }}</th>
                            <th class="px-6 py-4 text-right">{{ __('Execution Rate') }}</th>
                            <th class="px-6 py-4 text-right">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @php
                            $groupedAllocations = $activeEnvelope->categoryAllocations->groupBy(fn($a) => $a->economicClassification->category_type);
                        @endphp

                        @foreach($groupedAllocations as $type => $allocs)
                            <tr class="bg-gray-50/80">
                                <td colspan="7" class="px-6 py-2">
                                    <div class="flex items-center gap-2">
                                        <span class="inline-flex h-2 w-2 rounded-full {{ $type === 'Current' ? 'bg-blue-500' : 'bg-orange-500' }}"></span>
                                        <span class="text-[10px] font-extrabold uppercase tracking-widest text-gray-600">
                                            {{ $type === 'Current' ? __('Current Expenditure') : __('Capital Expenditure') }}
                                        </span>
                                        <span class="text-[10px] text-gray-400 font-medium lowercase">
                                            ({{ $type === 'Current' ? __('Salaries, Goods and Services and Transfers') : __('Minor Investment and Infrastructure') }})
                                        </span>
                                    </div>
                                </td>
                            </tr>
                            @foreach($allocs as $allocation)
                                @php 
                                                                                                                                $amount = (float) $allocation->allocated_amount;
                                    $committed = (float) $allocation->committed_amount;
                                    $spent = (float) $allocation->spent_amount;
                                    $available = $amount - $spent;
                                    $executionRate = $amount > 0 ? ($spent / $amount) * 100 : 0;
                                    $cat = $allocation->economicClassification;
                                @endphp
                                <tr class="hover:bg-gray-50/50 transition-colors">
                                    <td class="px-6 py-4 flex items-center gap-3">
                                        <div class="h-8 w-8 rounded-lg bg-gray-100 flex items-center justify-center text-gray-500 shrink-0">
                                            @if($cat->code == '10') <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                            @elseif($cat->code == '20') <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>
                                            @elseif($cat->code == '30') <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 15h2a2 2 0 1 0 0-4h-3c-.6 0-1.1.2-1.4.6L3 17"/><path d="m7 21 1.6-1.4c.3-.4.8-.6 1.4-.6h4c1.1 0 2.1-.4 2.8-1.2l4.6-4.4a2 2 0 0 0-2.75-2.91l-4.2 3.9"/><path d="m2 16 6 6"/><circle cx="16" cy="9" r="2.9"/><circle cx="6" cy="5" r="3"/></svg>
                                            @elseif($cat->code == '40') <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/></svg>
                                            @else <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 12-8.373 8.373a1 1 0 1 1-3-3L12 9"/><path d="m18 15 4-4"/><path d="m21.5 11.5-1.914-1.914A2 2 0 0 1 19 8.172V7l-2.26-2.26a6 6 0 0 0-4.202-1.756L9 2.96l.92.82A6.18 6.18 0 0 1 12 8.4V10l2 2h1.172a2 2 0 0 1 1.414.586L18.5 14.5"/></svg> @endif
                                        </div>
                                        <div>
                                            <div class="font-bold text-gray-900 leading-tight">
                                                {{ $cat->code }} - {{ $cat->name }}
                                                @if($cat->code == '40') <span class="ml-2 text-[8px] bg-gray-100 px-1 py-0.5 rounded text-gray-500 uppercase">{{ __('Small Investment') }}</span>
                                                @elseif($cat->code == '50') <span class="ml-2 text-[8px] bg-gray-900 px-1 py-0.5 rounded text-white uppercase">{{ __('Structural Investment') }}</span> @endif
                                            </div>
                                            <div class="text-[10px] text-gray-400 mt-0.5 leading-tight">{{ __($cat->description) }}</div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-right font-bold text-gray-900">$ {{ number_format($amount, 2) }}</td>
                                    <td class="px-6 py-4 text-right text-gray-400">$ {{ number_format($committed, 2) }}<br><span class="text-[9px]">{{ $amount > 0 ? number_format(($committed / $amount) * 100, 1) : 0 }}% {{ __('of total') }}</span></td>
                                    <td class="px-6 py-4 text-right text-gray-400">$ {{ number_format($spent, 2) }}<br><span class="text-[9px]">{{ $executionRate > 0 ? number_format($executionRate, 1) : 0 }}% {{ __('of total') }}</span></td>
                                    <td class="px-6 py-4 text-right {{ $available < 0 ? 'text-red-700' : 'text-green-700' }} font-bold whitespace-nowrap">$ {{ number_format($available, 2) }}</td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex flex-col items-end gap-1">
                                            <span class="font-bold text-gray-900">{{ number_format($executionRate, 1) }}%</span>
                                            <div class="h-1.5 w-20 rounded-full bg-gray-100 overflow-hidden">
                                                <div class="h-full {{ $executionRate > 100 ? 'bg-red-500' : 'bg-green-500' }}" style="width: {{ min($executionRate, 100) }}%"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        @if($activeEnvelope->status === App\Models\FiscalEnvelope::STATUS_DRAFT)
                                            <button wire:click="openModal({{ $activeEnvelope->fiscal_year }})" class="p-1 text-gray-400 hover:text-gray-900 transition-colors border border-gray-100 rounded hover:bg-white hover:shadow-sm">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Allocation Status Section -->
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm font-sans">
            <div class="p-6">
                <h3 class="text-sm font-bold uppercase tracking-wider text-gray-800 mb-6">{{ __('Allocation Status') }}</h3>

                <div class="space-y-4 ml-auto">
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">{{ __('Total Fiscal Envelope') }}</span>
                        <span class="text-sm font-bold text-gray-900">$ {{ number_format($activeEnvelope->total_amount, 2) }}</span>
                    </div>

                    @php 
                                                                                                    $allocatedSum = $activeEnvelope->categoryAllocations->sum('allocated_amount');
                        $unallocated = (float) $activeEnvelope->total_amount - (float) $allocatedSum;
                    @endphp

                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">{{ __('Sum of Category Allocations') }}</span>
                        <span class="text-sm font-bold text-gray-900">$ {{ number_format($allocatedSum, 2) }}</span>
                    </div>

                    <div class="h-px bg-gray-100 w-full"></div>

                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">{{ __('Unallocated Balance') }}</span>
                        <span class="text-sm font-bold {{ $unallocated <= 0.01 ? 'text-green-600' : 'text-gray-900' }}">$ {{ number_format($unallocated, 2) }}</span>
                    </div>
                </div>

                @if($unallocated <= 0.01)
                    <div class="mt-8 bg-green-50 border border-green-100 rounded-lg p-4 flex items-center gap-3">
                        <div class="h-5 w-5 rounded-full bg-green-500 flex items-center justify-center shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                        </div>
                        <div>
                            <p class="text-sm font-bold text-green-900 leading-none">{{ __('Fully allocated') }}</p>
                            <p class="text-xs text-green-700 mt-1">{{ __('All fiscal envelope funds have been allocated to economic categories.') }}</p>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
