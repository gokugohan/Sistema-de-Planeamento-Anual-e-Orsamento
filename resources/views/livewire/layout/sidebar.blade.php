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
            $stats = Activity::where('fiscal_year', $this->activeYear)
                ->selectRaw('COUNT(*) as total, COALESCE(SUM(status = ?), 0) as approved', [Activity::STATUS_APPROVED])
                ->first();
            $total    = (int) $stats->total;
            $approved = (int) $stats->approved;
            $this->executionRate = $total > 0 ? (int) round($approved / $total * 100) : 0;
        }
    }

    public function logout(Logout $logout): void
    {
        $logout();
        $this->redirect('/', navigate: true);
    }
}; ?>

<div class="flex flex-col h-screen w-64 bg-zinc-900 fixed left-0 top-0 overflow-y-auto z-50 transition-transform duration-300 md:translate-x-0"
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
    <div class="flex items-center gap-3 px-6 min-h-16 py-3 shrink-0 border-b border-white/10">
        <div class="w-9 h-9 bg-indigo-600 rounded-md flex items-center justify-center flex-shrink-0">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 2v20" /><path d="m17 5-5-3-5 3" /><path d="m17 19-5 3-5-3" />
                <path d="M2 12h20" /><path d="m5 7 3 5-3 5" /><path d="m19 7-3 5 3 5" />
            </svg>
        </div>
        <div class="min-w-0">
            <p class="text-sm font-semibold text-white leading-snug line-clamp-2">{{ \App\Models\SystemSetting::getLocalized('org_name', 'Institution Name') }}</p>
            <p class="text-[10px] text-nav-muted font-medium uppercase tracking-wider mt-0.5 truncate">{{ \App\Models\SystemSetting::getLocalized('app_tagline', 'Planning & Control') }}</p>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="flex-1 px-4 py-5 space-y-6 overflow-y-auto" aria-label="{{ __('Main navigation') }}">
        @foreach(\App\Support\Navigation::groups(auth()->user()) as $group)
            <div>
                <p class="nav-section-label {{ $group['label'] === 'Testing Zone' ? '!text-amber-500/80' : '' }}">{{ __($group['label']) }}</p>
                <div class="space-y-0.5">
                    @foreach($group['items'] as $item)
                        @if(isset($item['children']))
                            @php $open = \App\Support\Navigation::isActive($item); @endphp
                            <div x-data="{ open: {{ $open ? 'true' : 'false' }} }">
                                <button type="button" @click="open = !open" :aria-expanded="open.toString()"
                                    class="nav-item w-full {{ $open ? 'nav-item-active' : 'nav-item-inactive' }}">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                        class="flex-shrink-0 opacity-70" aria-hidden="true">
                                        <rect width="20" height="12" x="2" y="6" rx="2" /><circle cx="12" cy="12" r="2" />
                                        <path d="M6 12h.01" /><path d="M18 12h.01" />
                                    </svg>
                                    <span class="flex-1 text-left">{{ __($item['label']) }}</span>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                                        class="transition-transform duration-200 opacity-50" :class="open ? 'rotate-180' : ''" aria-hidden="true">
                                        <path d="m6 9 6 6 6-6" />
                                    </svg>
                                </button>
                                <div x-show="open" x-collapse class="mt-0.5 ml-3 pl-4 border-l border-white/10 space-y-0.5">
                                    @foreach($item['children'] as $child)
                                        <x-sidebar-link :href="route($child['route'])" :active="\App\Support\Navigation::isActive($child)"
                                            :icon="$child['icon'] ?? 'circle'">{{ __($child['label']) }}</x-sidebar-link>
                                    @endforeach
                                </div>
                            </div>
                        @else
                            <x-sidebar-link :href="route($item['route'])" :active="\App\Support\Navigation::isActive($item)"
                                :icon="$item['icon'] ?? 'circle'">{{ __($item['label']) }}</x-sidebar-link>
                        @endif
                    @endforeach
                </div>
            </div>
        @endforeach
    </nav>

    <!-- Bottom Widgets -->
    <div class="px-4 py-4 border-t border-white/10 space-y-3">

        <!-- Active Fiscal Year Widget -->
        @if($activeEnvelope)
            <div class="bg-nav-hover/60 rounded-md p-4 border border-white/5">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-[10px] font-medium text-nav-muted uppercase tracking-wider">{{ __('Active Year') }}</p>
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                </div>
                <div class="flex items-baseline gap-2 mb-3">
                    <span class="text-xl font-semibold text-white">{{ $activeEnvelope->fiscal_year }}</span>
                    <span class="text-[11px] text-indigo-300 font-medium">
                        ${{ number_format($activeEnvelope->total_amount / 1000000, 1) }}M
                    </span>
                </div>
                <div class="h-1.5 bg-white/10 rounded-full overflow-hidden">
                    <div class="h-full bg-indigo-500 rounded-full transition-all duration-700"
                        style="width: {{ $executionRate }}%"></div>
                </div>
                <p class="text-[10px] text-nav-muted mt-1.5">{{ $executionRate }}% {{ __('execution rate') }}</p>
            </div>
        @endif

    </div>

    <!-- Footer copyright -->
    @php
        $sOwner  = \App\Models\SystemSetting::get('system_owner', '');
        $sImplBy = \App\Models\SystemSetting::get('implemented_by', '');
    @endphp
    <div class="px-6 pb-4 pt-3 border-t border-white/10">
        <p class="text-[10px] text-nav-muted leading-relaxed">
           &copy; {{ date('Y') }} {{ $sOwner }} &mdash; {{ __('All rights reserved') }}
           <br>
                    @if($sImplBy){{ __('Implemented by') }} {{ $sImplBy }}@endif
        </p>
    </div>
</div>
