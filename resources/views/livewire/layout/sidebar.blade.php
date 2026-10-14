<?php

use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;
use App\Models\Activity;
use App\Models\FiscalEnvelope;
use App\Models\SystemSetting;

new class extends Component {
    public $activeEnvelope;
    public $activeYear;
    public int $approvedCount = 0;
    public int $activityCount = 0;

    public function mount()
    {
        $this->activeYear = FiscalEnvelope::getEffectiveYear();
        $this->activeEnvelope = FiscalEnvelope::where('fiscal_year', $this->activeYear)->first();

        if ($this->activeYear) {
            $stats = Activity::where('fiscal_year', $this->activeYear)
                ->selectRaw('COUNT(*) as total, COALESCE(SUM(status = ?), 0) as approved', [Activity::STATUS_APPROVED])
                ->first();
            $this->activityCount = (int) $stats->total;
            $this->approvedCount = (int) $stats->approved;
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
    @php
        $orgName = \App\Models\SystemSetting::getLocalized('org_name', 'Institution Name');
        $orgAbbr = \App\Models\SystemSetting::getLocalized('org_abbr', 'ORG');
    @endphp
    <a href="{{ route('dashboard') }}" wire:navigate title="{{ $orgName }}"
        class="flex items-center gap-3 px-5 py-4 shrink-0 border-b border-white/10 hover:bg-white/5 transition-colors">
        <span class="flex h-10 min-w-10 items-center justify-center rounded-md bg-indigo-600 px-1.5 text-[11px] font-bold tracking-wide text-white" aria-hidden="true">{{ \Illuminate\Support\Str::limit($orgAbbr, 5, '') }}</span>
        <span class="min-w-0">
            <span class="block text-[13px] font-semibold leading-snug text-white line-clamp-3">{{ $orgName }}</span>
        </span>
    </a>

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

        <!-- Working year and how much of its plan is approved -->
        @if($activeEnvelope || $activityCount > 0)
            <div class="rounded-md border border-white/10 bg-white/5 p-3.5">
                <div class="flex items-baseline justify-between">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-nav-muted">{{ __('Fiscal year') }}</p>
                    <p class="text-lg font-semibold text-white tabular-nums">{{ $activeYear }}</p>
                </div>
                @if($activeEnvelope)
                    <p class="mt-0.5 text-xs text-nav-text tabular-nums">{{ __('Fiscal envelope') }} ${{ number_format($activeEnvelope->total_amount / 1000000, 1) }}M</p>
                @endif
                <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-white/10" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $activityCount }}" aria-valuenow="{{ $approvedCount }}" aria-label="{{ __('Approved') }}">
                    <div class="h-full rounded-full bg-emerald-400 transition-all duration-500" style="width: {{ $activityCount > 0 ? round($approvedCount / $activityCount * 100) : 0 }}%"></div>
                </div>
                <p class="mt-1.5 text-xs text-nav-text tabular-nums">{{ $approvedCount }} / {{ $activityCount }} {{ __('Approved') }}</p>
            </div>
        @endif

    </div>

    <!-- Footer -->
    @php
        $sOwner  = \App\Models\SystemSetting::get('system_owner', '');
        $sImplBy = \App\Models\SystemSetting::get('implemented_by', '');
    @endphp
    <div class="px-5 pb-4 pt-3 border-t border-white/10 text-[11px] leading-relaxed text-nav-muted">
        <p>&copy; {{ date('Y') }} {{ $sOwner }}</p>
        @if($sImplBy)<p>{{ __('Implemented by') }} {{ $sImplBy }}</p>@endif
    </div>
</div>
