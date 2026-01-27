<?php

use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;
use App\Models\FiscalEnvelope;
use App\Models\SystemSetting;

new class extends Component {
    public $activeEnvelope;
    public $activeYear;

    public function mount()
    {
        $this->activeYear = FiscalEnvelope::getEffectiveYear();
        $this->activeEnvelope = FiscalEnvelope::where('fiscal_year', $this->activeYear)->first();
    }

    public function logout(Logout $logout): void
    {
        $logout();
        $this->redirect('/', navigate: true);
    }
}; ?>

<div class="flex flex-col h-screen w-64 bg-[#0a0a0b] text-slate-400 border-r border-slate-800/50 fixed left-0 top-0 overflow-y-auto z-50 shadow-2xl transition-transform duration-300 md:translate-x-0"
    :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'">

    <!-- Mobile Close Button -->
    <div class="md:hidden absolute top-6 right-6">
        <button @click="sidebarOpen = false" class="p-2 text-slate-500 hover:text-white transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 6 6 18" />
                <path d="m6 6 12 12" />
            </svg>
        </button>
    </div>

    <!-- Brand / Logo -->
    <div class="p-8 flex items-center gap-4 border-b border-slate-800/30">
        <div class="bg-indigo-600 p-2.5 rounded-xl shadow-xl shadow-indigo-600/30 ring-1 ring-white/10">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none"
                stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 2v20" />
                <path d="m17 5-5-3-5 3" />
                <path d="m17 19-5 3-5-3" />
                <path d="M2 12h20" />
                <path d="m5 7 3 5-3 5" />
                <path d="m19 7-3 5 3 5" />
            </svg>
        </div>
        <div>
            <h1 class="text-xs font-black text-white leading-none uppercase tracking-[0.1em]">{{ __('Ministry') }}</h1>
            <p class="text-[10px] text-slate-500 font-bold uppercase tracking-widest mt-1">{{ __('Budget Hub') }}</p>
        </div>
    </div>

    <!-- Main Navigation -->
    <nav class="flex-1 px-4 py-8 space-y-8">
        <div class="space-y-1.5">
            <p class="text-[10px] font-black text-indigo-500 uppercase tracking-widest px-4 mb-4">{{ __('Core') }}</p>
            <x-sidebar-link :href="route('dashboard')" :active="request()->routeIs('dashboard')"
                icon="layout-dashboard">{{ __('Insights') }}</x-sidebar-link>
            <x-sidebar-link :href="route('admin.entities')" :active="request()->routeIs('admin.entities')"
                icon="building">{{ __('Entities Registry') }}</x-sidebar-link>
        </div>

        <div class="space-y-1.5">
            <p class="text-[10px] font-black text-indigo-500 uppercase tracking-widest px-4 mb-4">
                {{ __('Budget Planning') }}
            </p>
            <x-sidebar-link :href="route('activities.index')" :active="request()->routeIs('activities.*')"
                icon="clipboard-list">{{ __('PAA Activities') }}</x-sidebar-link>

            <!-- Fiscal Envelope with Sub-menu -->
            <div x-data="{ open: {{ request()->routeIs('budget.envelope.*') ? 'true' : 'false' }} }" class="space-y-1">
                <button @click="open = !open"
                    class="w-full flex items-center justify-between gap-3 px-4 py-3 text-sm font-bold transition-all rounded-xl {{ request()->routeIs('budget.envelope.*') ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/50' }}">
                    <div class="flex items-center gap-3">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <rect width="20" height="12" x="2" y="6" rx="2" />
                            <circle cx="12" cy="12" r="2" />
                            <path d="M6 12h.01" />
                            <path d="M18 12h.01" />
                        </svg>
                        <span>{{ __('Fiscal Envelope') }}</span>
                    </div>
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                        class="transition-transform duration-200" :class="open ? 'rotate-180' : ''">
                        <path d="m6 9 6 6 6-6" />
                    </svg>
                </button>

                <div x-show="open" x-collapse class="ml-4 space-y-1 border-l-2 border-slate-700/50 pl-4">
                    <x-sidebar-link :href="route('budget.envelope.current')"
                        :active="request()->routeIs('budget.envelope.current')" icon="circle"
                        class="!text-xs">{{ __('Current Envelope') }}</x-sidebar-link>
                    <x-sidebar-link :href="route('budget.envelope.list')"
                        :active="request()->routeIs('budget.envelope.list')" icon="list"
                        class="!text-xs">{{ __('Envelope List') }}</x-sidebar-link>
                </div>
            </div>

            <x-sidebar-link :href="route('budget.allocation')" :active="request()->routeIs('budget.allocation')"
                icon="layers">{{ __('Budget Allocation') }}</x-sidebar-link>
        </div>

        @if(auth()->user()->hasAnyRole(['Admin', 'Minister']))
            <div class="space-y-1.5 pt-4 border-t border-slate-800/30">
                <p class="text-[10px] font-black text-indigo-500 uppercase tracking-widest px-4 mb-4">
                    {{ __('Governance & Control') }}
                </p>
                <x-sidebar-link :href="route('monitoring.approval')" :active="request()->routeIs('monitoring.approval')"
                    icon="shield-check">{{ __('Verify Progress') }}</x-sidebar-link>

                @if(auth()->user()->hasAnyRole(['Admin', 'Minister']))
                    <x-sidebar-link :href="route('planning.paa-approval')" :active="request()->routeIs('planning.paa-approval')"
                        icon="check-square">{{ __('PAA Tech Review') }}</x-sidebar-link>
                @endif

                @if(auth()->user()->hasAnyRole(['Admin', 'Minister']))
                    <x-sidebar-link :href="route('planning.director-review')"
                        :active="request()->routeIs('planning.director-review')"
                        icon="user-check">{{ __('Allocation Review') }}</x-sidebar-link>
                @endif
            </div>
        @endif

        <div class="space-y-1.5">
            <p class="text-[10px] font-black text-indigo-500 uppercase tracking-widest px-4 mb-4">
                {{ __('Monitoring') }}
            </p>
            <x-sidebar-link :href="route('monitoring.index')" :active="request()->routeIs('monitoring.index')"
                icon="activity">{{ __('Performance') }}</x-sidebar-link>
            <x-sidebar-link :href="route('reports.paa')" :active="request()->routeIs('reports.*')"
                icon="file-bar-chart">{{ __('Reporting') }}</x-sidebar-link>
        </div>

        @if(\App\Models\SystemSetting::get('enable_paa_testing_mode', false))
            <div class="space-y-1.5 animate-in slide-in-from-left duration-500">
                <p class="text-[10px] font-black text-amber-500 uppercase tracking-widest px-4 mb-4">
                    {{ __('Testing Zone') }}
                </p>
                <x-sidebar-link :href="route('testing.index')" :active="request()->routeIs('testing.*')"
                    icon="flask-conical"
                    class="!text-amber-500 hover:!bg-amber-500/10 border-l-2 !border-amber-500 pl-[calc(1rem-2px)]">{{ __('Simulator') }}</x-sidebar-link>
            </div>
        @endif

        <div class="pt-4 space-y-1.5 border-t border-slate-800/30">

            <p class="text-[10px] font-black text-indigo-500 uppercase tracking-widest px-4 mb-4">
                {{ __('Management') }}
            </p>
            <x-sidebar-link :href="route('management.users')" :active="request()->routeIs('management.users')"
                icon="users">{{ __('System Users') }}</x-sidebar-link>
            <x-sidebar-link :href="route('management.settings')" :active="request()->routeIs('management.settings')"
                icon="settings">{{ __('System Settings') }}</x-sidebar-link>
            <x-sidebar-link :href="route('management.entities')" :active="request()->routeIs('management.entities')"
                icon="database">{{ __('Manage Entities') }}</x-sidebar-link>
        </div>
    </nav>

    <!-- Widgets Section -->
    <div class="px-6 py-8 space-y-8 border-t border-slate-800/30 bg-black/20">
        <!-- Quick Overview Card -->
        @if($activeEnvelope)
            <div
                class="bg-indigo-600/5 rounded-2xl p-5 border border-indigo-500/10 transition-all hover:bg-indigo-600/10 group/widget">
                <div class="flex justify-between items-center mb-4">
                    <p class="text-[9px] font-black text-slate-500 uppercase tracking-widest">{{ __('Active Year') }}</p>
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                </div>
                <div class="space-y-4">
                    <div>
                        <div class="flex items-baseline gap-2">
                            <span
                                class="text-2xl font-black text-white tracking-tighter">{{ $activeEnvelope->fiscal_year }}</span>
                            <span class="text-[10px] text-indigo-400 font-bold uppercase tracking-tighter">$
                                {{ number_format($activeEnvelope->total_amount / 1000000, 1) }}M</span>
                        </div>
                    </div>
                    <div class="relative pt-1">
                        @php $percentage = 58; @endphp
                        <div class="overflow-hidden h-1.5 text-xs flex rounded-full bg-slate-800">
                            <div style="width:{{ $percentage }}%"
                                class="shadow-none flex flex-col text-center whitespace-nowrap text-white justify-center bg-indigo-500 rounded-full transition-all duration-1000 group-hover/widget:bg-indigo-400">
                            </div>
                        </div>
                        <p
                            class="text-[9px] font-bold text-slate-500 uppercase mt-2 group-hover/widget:text-indigo-300 transition-colors">
                            {{ $percentage }}% EXECUTION RATE
                        </p>
                    </div>
                </div>
            </div>
        @endif

        <!-- Logout -->
        <button wire:click="logout"
            class="w-full flex items-center gap-3 px-4 py-3 text-rose-500 hover:text-white hover:bg-rose-500 rounded-2xl transition-all font-black text-[10px] uppercase tracking-widest group shadow-sm hover:shadow-rose-500/20">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"
                class="group-hover:rotate-180 transition-transform duration-500">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                <polyline points="16 17 21 12 16 7" />
                <line x1="21" y1="12" x2="9" y2="12" />
            </svg>
            {{ __('Secure Exit') }}
        </button>
    </div>
</div>