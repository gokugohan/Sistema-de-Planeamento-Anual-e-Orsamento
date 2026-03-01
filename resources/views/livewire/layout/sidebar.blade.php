<?php

use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;
use App\Models\Activity;
use App\Models\FiscalEnvelope;
use App\Models\SystemSetting;

new class extends Component {
    public $activeEnvelope;
    public $activeYear;
    public int $executionRate = 0;

    public function mount()
    {
        $this->activeYear = FiscalEnvelope::getEffectiveYear();
        $this->activeEnvelope = FiscalEnvelope::where('fiscal_year', $this->activeYear)->first();

        if ($this->activeYear) {
            $total    = Activity::where('fiscal_year', $this->activeYear)->count();
            $approved = Activity::where('fiscal_year', $this->activeYear)
                ->where('status', Activity::STATUS_APPROVED)->count();
            $this->executionRate = $total > 0 ? (int) round($approved / $total * 100) : 0;
        }
    }

    public function logout(Logout $logout): void
    {
        $logout();
        $this->redirect('/', navigate: true);
    }
}; ?>

<div class="flex flex-col h-screen w-60 bg-zinc-900 border-r border-white/5 fixed left-0 top-0 overflow-y-auto z-50 transition-transform duration-300 md:translate-x-0"
    :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'">

    <!-- Mobile Close -->
    <div class="md:hidden absolute top-4 right-4">
        <button @click="sidebarOpen = false"
            class="p-1.5 text-zinc-500 hover:text-white hover:bg-white/5 rounded-lg transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 6 6 18" /><path d="m6 6 12 12" />
            </svg>
        </button>
    </div>

    <!-- Brand -->
    <div class="flex items-center gap-3 px-5 py-5 border-b border-white/5">
        <div class="w-8 h-8 bg-indigo-600 rounded-lg flex items-center justify-center shadow-lg shadow-indigo-600/20 ring-1 ring-white/10 flex-shrink-0">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 2v20" /><path d="m17 5-5-3-5 3" /><path d="m17 19-5 3-5-3" />
                <path d="M2 12h20" /><path d="m5 7 3 5-3 5" /><path d="m19 7-3 5 3 5" />
            </svg>
        </div>
        <div class="min-w-0">
            <p class="text-sm font-semibold text-white leading-none">{{ \App\Models\SystemSetting::getLocalized('org_name', 'Ministry of Finance') }}</p>
            <p class="text-[10px] text-zinc-500 font-medium uppercase tracking-wider mt-0.5">{{ \App\Models\SystemSetting::getLocalized('app_tagline', 'Planning & Control') }}</p>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="flex-1 px-3 py-4 space-y-5 overflow-y-auto">

        <!-- Core -->
        <div>
            <p class="nav-section-label">{{ __('Core') }}</p>
            <div class="space-y-0.5">
                <x-sidebar-link :href="route('dashboard')" :active="request()->routeIs('dashboard')"
                    icon="layout-dashboard">{{ __('Insights') }}</x-sidebar-link>
                <x-sidebar-link :href="route('admin.entities')" :active="request()->routeIs('admin.entities')"
                    icon="building">{{ __('Entities Registry') }}</x-sidebar-link>
            </div>
        </div>

        <!-- Budget Planning -->
        <div>
            <p class="nav-section-label">{{ __('Budget Planning') }}</p>
            <div class="space-y-0.5">
                <x-sidebar-link :href="route('activities.index')" :active="request()->routeIs('activities.*')"
                    icon="clipboard-list">{{ __('PAA Activities') }}</x-sidebar-link>

                <!-- Fiscal Envelope sub-menu -->
                <div x-data="{ open: {{ request()->routeIs('budget.envelope.*') ? 'true' : 'false' }} }">
                    <button @click="open = !open"
                        class="nav-item w-full {{ request()->routeIs('budget.envelope.*') ? 'nav-item-active' : 'nav-item-inactive' }}">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            class="flex-shrink-0 opacity-70">
                            <rect width="20" height="12" x="2" y="6" rx="2" />
                            <circle cx="12" cy="12" r="2" />
                            <path d="M6 12h.01" /><path d="M18 12h.01" />
                        </svg>
                        <span class="flex-1 text-left">{{ __('Fiscal Envelope') }}</span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                            class="transition-transform duration-200 opacity-50" :class="open ? 'rotate-180' : ''">
                            <path d="m6 9 6 6 6-6" />
                        </svg>
                    </button>

                    <div x-show="open" x-collapse class="mt-0.5 ml-3 pl-4 border-l border-white/10 space-y-0.5">
                        <x-sidebar-link :href="route('budget.envelope.current')"
                            :active="request()->routeIs('budget.envelope.current')"
                            icon="circle">{{ __('Current Envelope') }}</x-sidebar-link>
                        <x-sidebar-link :href="route('budget.envelope.list')"
                            :active="request()->routeIs('budget.envelope.list')"
                            icon="list">{{ __('Envelope List') }}</x-sidebar-link>
                    </div>
                </div>

                <x-sidebar-link :href="route('budget.allocation')" :active="request()->routeIs('budget.allocation')"
                    icon="layers">{{ __('Budget Allocation') }}</x-sidebar-link>
            </div>
        </div>

        <!-- Governance (role-gated) -->
        @if(auth()->user()->hasAnyRole(['Admin', 'Minister']))
            <div>
                <p class="nav-section-label">{{ __('Governance') }}</p>
                <div class="space-y-0.5">
                    <x-sidebar-link :href="route('monitoring.approval')" :active="request()->routeIs('monitoring.approval')"
                        icon="shield-check">{{ __('Verify Progress') }}</x-sidebar-link>
                    <x-sidebar-link :href="route('planning.paa-approval')" :active="request()->routeIs('planning.paa-approval')"
                        icon="check-square">{{ __('PAA Tech Review') }}</x-sidebar-link>
                    <x-sidebar-link :href="route('planning.director-review')"
                        :active="request()->routeIs('planning.director-review')"
                        icon="user-check">{{ __('Allocation Review') }}</x-sidebar-link>
                </div>
            </div>
        @endif

        <!-- Monitoring -->
        <div>
            <p class="nav-section-label">{{ __('Monitoring') }}</p>
            <div class="space-y-0.5">
                <x-sidebar-link :href="route('monitoring.index')" :active="request()->routeIs('monitoring.index')"
                    icon="activity">{{ __('Performance') }}</x-sidebar-link>
                <x-sidebar-link :href="route('reports.paa')" :active="request()->routeIs('reports.*')"
                    icon="file-bar-chart">{{ __('Reporting') }}</x-sidebar-link>
            </div>
        </div>

        <!-- Testing Zone -->
        @if(\App\Models\SystemSetting::get('enable_paa_testing_mode', false))
            <div>
                <p class="text-[10px] font-semibold text-amber-500/70 uppercase tracking-[0.1em] px-3 mb-1">
                    {{ __('Testing Zone') }}
                </p>
                <div class="space-y-0.5">
                    <x-sidebar-link :href="route('testing.index')" :active="request()->routeIs('testing.*')"
                        icon="flask-conical"
                        class="!text-amber-400 hover:!bg-amber-500/10 hover:!text-amber-300">{{ __('Simulator') }}</x-sidebar-link>
                </div>
            </div>
        @endif

        <!-- Management -->
        <div class="pt-1 border-t border-white/5">
            <p class="nav-section-label mt-4">{{ __('Management') }}</p>
            <div class="space-y-0.5">
                <x-sidebar-link :href="route('management.users')" :active="request()->routeIs('management.users')"
                    icon="users">{{ __('System Users') }}</x-sidebar-link>
                <x-sidebar-link :href="route('management.settings')" :active="request()->routeIs('management.settings')"
                    icon="settings">{{ __('Settings') }}</x-sidebar-link>
                <x-sidebar-link :href="route('management.entities')" :active="request()->routeIs('management.entities')"
                    icon="database">{{ __('Manage Entities') }}</x-sidebar-link>
            </div>
        </div>
    </nav>

    <!-- Bottom Widgets -->
    <div class="px-3 py-4 border-t border-white/5 space-y-3">

        <!-- Active Fiscal Year Widget -->
        @if($activeEnvelope)
            <div class="bg-white/5 rounded-xl p-4 ring-1 ring-white/8">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-[10px] font-medium text-zinc-500 uppercase tracking-wider">{{ __('Active Year') }}</p>
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                </div>
                <div class="flex items-baseline gap-2 mb-3">
                    <span class="text-xl font-semibold text-white">{{ $activeEnvelope->fiscal_year }}</span>
                    <span class="text-[11px] text-indigo-400 font-medium">
                        ${{ number_format($activeEnvelope->total_amount / 1000000, 1) }}M
                    </span>
                </div>
                <div class="h-1 bg-white/10 rounded-full overflow-hidden">
                    <div class="h-full bg-indigo-500 rounded-full transition-all duration-700"
                        style="width: {{ $executionRate }}%"></div>
                </div>
                <p class="text-[10px] text-zinc-500 mt-1.5">{{ $executionRate }}% {{ __('execution rate') }}</p>
            </div>
        @endif

    </div>

    <!-- Footer copyright -->
    @php
        $sOwner  = \App\Models\SystemSetting::get('system_owner', '');
        $sImplBy = \App\Models\SystemSetting::get('implemented_by', '');
    @endphp
    <div class="px-5 pb-4 pt-2 border-t border-white/5">
        <p class="text-[10px] text-zinc-500 leading-relaxed">
           &copy; {{ date('Y') }} {{ $sOwner }} &mdash; {{ __('All rights reserved') }}
           <br>
                    {{ $sImplBy ? __('Implemented by') . ' ' . $sImplBy : 'N/A' }}
        </p>
    </div>
</div>
