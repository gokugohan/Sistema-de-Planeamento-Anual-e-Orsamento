<?php

use Livewire\Volt\Component;

new class extends Component {
    //
}; ?>

<header
    class="h-20 bg-white/80 backdrop-blur-md border-b border-slate-100 sticky top-0 z-40 flex items-center justify-between px-6 md:px-10 shadow-sm shadow-slate-200/20">

    <!-- Mobile Hamburger Toggle -->
    <button @click="sidebarOpen = true"
        class="p-2.5 text-slate-500 hover:bg-slate-50 rounded-xl md:hidden mr-4 border border-slate-100">
        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <line x1="3" x2="21" y1="12" y2="12" />
            <line x1="3" x2="21" y1="6" y2="6" />
            <line x1="3" x2="21" y1="18" y2="18" />
        </svg>
    </button>

    <!-- Left: Search placeholder or Page Context -->
    <div class="flex-1 max-w-md hidden md:block">
        <div class="relative group">
            <span
                class="absolute inset-y-0 left-0 flex items-center pl-4 text-slate-300 group-focus-within:text-indigo-500 transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8" />
                    <path d="m21 21-4.3-4.3" />
                </svg>
            </span>
            <input type="text" placeholder="{{ __('Search financial data, activities, reports...') }}"
                class="w-full h-11 bg-slate-50/50 border-slate-100/50 rounded-2xl pl-12 text-[11px] font-bold text-slate-600 focus:ring-2 focus:ring-indigo-500/10 focus:border-indigo-500 focus:bg-white transition-all placeholder:text-slate-300 placeholder:font-medium">
        </div>
    </div>

    <!-- Right: Notifications & User -->
    <div class="flex items-center gap-8">
        <!-- Language Switcher -->
        <div class="bg-slate-100/50 p-1 rounded-xl flex items-center gap-1 border border-slate-100 shadow-sm">
            <x-ui.button variant="{{ app()->getLocale() === 'en' ? 'secondary' : 'ghost' }}" size="xs"
                href="{{ route('lang.switch', 'en') }}"
                class="!text-[10px] py-1 px-2 h-7 bg-transparent border-none shadow-none">EN</x-ui.button>
            <x-ui.button variant="{{ app()->getLocale() === 'pt' ? 'secondary' : 'ghost' }}" size="xs"
                href="{{ route('lang.switch', 'pt') }}"
                class="!text-[10px] py-1 px-2 h-7 bg-transparent border-none shadow-none">PT</x-ui.button>
            <x-ui.button variant="{{ app()->getLocale() === 'tet' ? 'secondary' : 'ghost' }}" size="xs"
                href="{{ route('lang.switch', 'tet') }}"
                class="!text-[10px] py-1 px-2 h-7 bg-transparent border-none shadow-none">TET</x-ui.button>
        </div>

        <div class="flex items-center gap-4">
            <!-- Notification Center -->
            <livewire:layout.notification-bell />

            <!-- Settings icon -->
            <button
                class="p-2.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-xl transition-all group">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                    class="group-hover:rotate-90 transition-transform">
                    <path
                        d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z" />
                    <circle cx="12" cy="12" r="3" />
                </svg>
            </button>
        </div>

        <div class="h-8 w-px bg-slate-100"></div>

        <!-- User Profile -->
        <a href="{{ route('profile') }}"
            class="flex items-center gap-4 py-1.5 pl-1.5 pr-4 border border-transparent hover:border-slate-100 hover:bg-slate-50/50 rounded-2xl transition-all group">
            <div
                class="h-10 w-10 bg-indigo-600 rounded-xl flex items-center justify-center text-white text-xs font-black shadow-lg shadow-indigo-600/30 ring-1 ring-white/20 group-hover:scale-105 transition-transform">
                {{ substr(auth()->user()->name, 0, 2) }}
            </div>
            <div class="text-left hidden sm:block">
                <p class="text-[11px] font-black text-slate-900 leading-none uppercase tracking-wider">
                    {{ auth()->user()->name }}
                </p>
                <p class="text-[9px] text-slate-400 font-bold mt-1 uppercase tracking-widest">
                    {{ __(auth()->user()->roles->first()?->display_name ?: 'System User') }}
                </p>
            </div>
        </a>
    </div>
</header>