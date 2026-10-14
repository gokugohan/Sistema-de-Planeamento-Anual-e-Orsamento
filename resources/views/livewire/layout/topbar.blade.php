<?php

use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;

new class extends Component {
    public function logout(Logout $logout): void
    {
        $logout();
        $this->redirect('/', navigate: true);
    }
}; ?>

<header
    class="h-16 bg-white border-b border-zinc-200 sticky top-0 z-40 flex items-center gap-4 px-4 md:px-6 shadow-card">

    <!-- Mobile Menu Toggle -->
    <button @click="sidebarOpen = true"
        class="p-2 text-zinc-400 hover:text-zinc-600 hover:bg-zinc-50 rounded-lg transition-colors md:hidden flex-shrink-0">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="3" x2="21" y1="6" y2="6" /><line x1="3" x2="21" y1="12" y2="12" /><line x1="3" x2="21" y1="18" y2="18" />
        </svg>
    </button>

    {{-- Breadcrumbs: where am I? --}}
    @php $crumbs = \App\Support\Navigation::breadcrumbs(auth()->user()); @endphp
    <nav aria-label="{{ __('Breadcrumb') }}" class="hidden md:block min-w-0 flex-1">
        @if($crumbs)
            <ol class="flex items-center gap-1.5 text-sm min-w-0">
                @foreach($crumbs as $crumb)
                    <li class="flex items-center gap-1.5 min-w-0">
                        @if(!$loop->first)<span class="text-zinc-300" aria-hidden="true">/</span>@endif
                        @if($crumb['url'] && !$loop->last)
                            <a href="{{ $crumb['url'] }}" wire:navigate class="text-zinc-500 hover:text-indigo-600 truncate">{{ $crumb['label'] }}</a>
                        @else
                            <span class="{{ $loop->last ? 'font-medium text-zinc-900' : 'text-zinc-500' }} truncate" @if($loop->last) aria-current="page" @endif>{{ $crumb['label'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        @else
            <span class="text-sm font-medium text-zinc-900">{{ __('Dashboard') }}</span>
        @endif
    </nav>

    {{-- Quick jump: Ctrl/⌘+K or "/" opens a searchable list of the pages this user can open --}}
    <div class="ml-auto md:ml-0 md:shrink-0" x-data="{
            links: @js(\App\Support\Navigation::quickLinks(auth()->user())), open: false, query: '', active: 0,
            get results() {
                const q = this.query.trim().toLowerCase();
                return q ? this.links.filter(l => (l.label + ' ' + l.group + ' ' + l.hint).toLowerCase().includes(q)) : this.links;
            },
            show() { this.open = true; this.query = ''; this.active = 0; this.$nextTick(() => this.$refs.input.focus()); },
            move(d) { const n = this.results.length; if (n) this.active = (this.active + d + n) % n; },
            go() { const l = this.results[this.active]; if (l) { this.open = false; window.location.href = l.url; } },
        }"
        @keydown.window.ctrl.k.prevent="show()" @keydown.window.meta.k.prevent="show()"
        @keydown.window="if ($event.key === '/' && !['INPUT','TEXTAREA','SELECT'].includes(document.activeElement.tagName) && !document.activeElement.isContentEditable) { $event.preventDefault(); show() }">
        <button type="button" @click="show()"
            class="flex items-center gap-2 h-9 pl-3 pr-2 rounded-md border border-zinc-200 text-sm text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 transition-colors"
            aria-label="{{ __('Go to a page') }}">
            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" /></svg>
            <span class="hidden 2xl:inline">{{ __('Go to…') }}</span>
            <kbd class="hidden 2xl:inline text-[10px] px-1.5 py-0.5 rounded border border-zinc-200 bg-zinc-50 text-zinc-500 font-sans">Ctrl K</kbd>
        </button>

        <template x-teleport="body">
            <div x-show="open" x-cloak x-trap.noscroll="open" @keydown.escape.window="open = false"
                class="fixed inset-0 z-[100] flex items-start justify-center p-4 pt-[12vh]" role="dialog" aria-modal="true" aria-label="{{ __('Go to a page') }}">
                <div class="absolute inset-0 bg-zinc-950/40" @click="open = false"></div>
                <div class="relative w-full max-w-lg bg-white rounded-lg border border-zinc-200 shadow-default overflow-hidden">
                    <input x-ref="input" x-model="query" type="text" placeholder="{{ __('Type to find a page…') }}"
                        @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.enter.prevent="go()"
                        class="w-full px-4 py-3 text-sm border-0 border-b border-zinc-200 focus:ring-0 focus:outline-none" autocomplete="off">
                    <ul class="max-h-80 overflow-y-auto py-1" role="listbox">
                        <template x-for="(link, i) in results" :key="link.url">
                            <li role="option" :aria-selected="i === active">
                                <a :href="link.url" @mouseenter="active = i" @click="open = false"
                                    :class="i === active ? 'bg-indigo-50' : ''" class="flex flex-col px-4 py-2">
                                    <span class="text-sm font-medium text-zinc-900" x-text="link.label"></span>
                                    <span class="text-xs text-zinc-500"><span x-text="link.group"></span><span x-show="link.hint"> · </span><span x-text="link.hint"></span></span>
                                </a>
                            </li>
                        </template>
                        <li x-show="results.length === 0" class="px-4 py-6 text-sm text-center text-zinc-500">{{ __('No page matches.') }}</li>
                    </ul>
                </div>
            </div>
        </template>
    </div>
    <!-- Right Side -->
    <div class="ml-auto flex items-center gap-1 md:shrink-0">

        <!-- Fiscal year: the year every page works in -->
        <div x-data class="flex items-center gap-2 mr-2">
            <label for="fiscal-year" class="hidden 2xl:block whitespace-nowrap text-xs font-medium text-zinc-500">{{ __('Fiscal year') }}</label>
            <select id="fiscal-year" @change="window.location.href = $event.target.value"
                class="h-9 pl-3 pr-8 text-sm font-semibold text-zinc-900 bg-white border border-zinc-300 rounded-md focus:border-indigo-600 focus:ring-2 focus:ring-indigo-600/25" aria-label="{{ __('Fiscal year') }}">
                @php $fy = \App\Models\FiscalEnvelope::getEffectiveYear(); @endphp
                @foreach(\App\Models\FiscalEnvelope::availableYears() as $year)
                    <option value="{{ route('fiscal-year.switch', $year) }}" @selected($year === $fy)>{{ $year }}</option>
                @endforeach
            </select>
        </div>

        <!-- Language Switcher -->
        <div class="hidden sm:flex items-center gap-0.5 p-1 bg-zinc-100 rounded-md">
            @foreach(['en' => 'EN', 'pt' => 'PT', 'tet' => 'TET'] as $code => $label)
                <a href="{{ route('lang.switch', $code) }}"
                    class="px-2.5 py-1 text-[11px] font-medium rounded-md transition-all
                    {{ app()->getLocale() === $code
                        ? 'bg-white text-zinc-900 shadow-sm ring-1 ring-zinc-950/5'
                        : 'text-zinc-500 hover:text-zinc-700' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>

        <!-- Spacer -->
        <div class="hidden sm:block w-px h-5 bg-zinc-200 mx-2"></div>

        <!-- Notification Bell -->
        <livewire:layout.notification-bell />

        <!-- Settings -->
        <a href="{{ route('management.settings') }}"
            class="hidden lg:inline-flex p-2 text-zinc-500 hover:text-zinc-800 hover:bg-zinc-100 rounded-lg transition-colors" aria-label="{{ __('General Settings') }}">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z" />
                <circle cx="12" cy="12" r="3" />
            </svg>
        </a>

        <!-- Spacer -->
        <div class="hidden lg:block w-px h-5 bg-zinc-200 mx-1"></div>

        <!-- User Profile -->
        <div x-data="{ open: false }" class="relative">
            <button @click="open = !open" @click.outside="open = false"
                class="flex items-center gap-2.5 pl-1 pr-3 py-1 rounded-xl hover:bg-zinc-50 border border-transparent hover:border-zinc-200/60 transition-all group">
                <div
                    class="w-9 h-9 bg-indigo-600 rounded-full flex items-center justify-center text-white text-xs font-semibold flex-shrink-0" aria-hidden="true">
                    {{ strtoupper(substr(auth()->user()->name, 0, 2)) }}
                </div>
                <div class="hidden xl:block text-left whitespace-nowrap">
                    <p class="text-[13px] font-semibold text-zinc-900 leading-none">{{ auth()->user()->name }}</p>
                    <p class="text-[11px] text-zinc-500 mt-1">
                        {{ __(auth()->user()->roles->first()?->display_name ?: 'System User') }}
                    </p>
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                    class="hidden xl:block text-zinc-400 transition-transform duration-200 ml-0.5" :class="open ? 'rotate-180' : ''">
                    <path d="m6 9 6 6 6-6" />
                </svg>
            </button>

            <!-- Dropdown -->
            <div x-show="open" x-transition x-cloak
                class="absolute top-full mt-2 right-0 w-56 bg-white rounded-md border border-zinc-200 shadow-default py-1 z-50">
                <a href="{{ route('profile') }}"
                    class="flex items-center gap-2.5 px-3 py-2 text-sm text-zinc-700 hover:text-zinc-900 hover:bg-zinc-50 transition-colors rounded-lg mx-1">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        class="flex-shrink-0 text-zinc-400">
                        <circle cx="12" cy="8" r="5" /><path d="M20 21a8 8 0 1 0-16 0" />
                    </svg>
                    {{ __('Profile') }}
                </a>
                {{-- On a phone the language switch lives here, so the bar stays within the screen --}}
                <div class="sm:hidden mx-1 mt-1 border-t border-zinc-100 pt-1">
                    <p class="px-3 py-1 text-[11px] font-semibold uppercase tracking-wide text-zinc-500">{{ __('Language') }}</p>
                    <div class="flex gap-1 px-2 pb-1">
                        @foreach(['en' => 'EN', 'pt' => 'PT', 'tet' => 'TET'] as $code => $label)
                            <a href="{{ route('lang.switch', $code) }}" class="flex-1 rounded-md px-2 py-1.5 text-center text-xs font-medium {{ app()->getLocale() === $code ? 'bg-indigo-600 text-white' : 'bg-zinc-100 text-zinc-700' }}">{{ $label }}</a>
                        @endforeach
                    </div>
                </div>
                <div class="my-1 border-t border-zinc-100 mx-1"></div>
                <button wire:click="logout"
                    class="w-full flex items-center gap-2.5 px-3 py-2 text-sm text-zinc-500 hover:text-rose-600 hover:bg-rose-50 transition-colors rounded-lg mx-1 group">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        class="flex-shrink-0 group-hover:translate-x-0.5 transition-transform">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                        <polyline points="16 17 21 12 16 7" />
                        <line x1="21" y1="12" x2="9" y2="12" />
                    </svg>
                    {{ __('Sign out') }}
                </button>
            </div>
        </div>
    </div>
</header>
