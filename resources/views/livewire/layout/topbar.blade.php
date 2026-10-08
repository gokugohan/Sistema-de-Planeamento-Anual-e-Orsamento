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

    <!-- Search -->
    <div class="hidden md:flex items-center gap-2 flex-1 max-w-sm h-10 px-3 bg-transparent border-b border-transparent
        focus-within:border-indigo-600 transition-all">
        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
            class="text-zinc-400 flex-shrink-0">
            <circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" />
        </svg>
        <input type="text" placeholder="{{ __('Search activities, reports...') }}"
            class="flex-1 bg-transparent text-sm text-zinc-700 placeholder:text-zinc-400 focus:outline-none min-w-0">
    </div>

    <!-- Page Breadcrumb -->
    <div
        x-data="{ pageTitle: '' }"
        x-init="
            function sync() {
                const parts = document.title.split(' \u2014 ');
                pageTitle = parts.length > 1 ? parts[0].trim() : '';
            }
            sync();
            document.addEventListener('livewire:navigated', sync);
        "
        class="hidden md:flex items-center gap-2 text-sm"
        x-show="pageTitle"
        x-cloak
    >
        <span class="text-zinc-300">/</span>
        <span x-text="pageTitle" class="font-medium text-zinc-700 truncate max-w-[200px]"></span>
    </div>

    <!-- Right Side -->
    <div class="ml-auto flex items-center gap-1">

        <!-- Language Switcher -->
        <div class="flex items-center gap-0.5 p-1 bg-zinc-100 rounded-md">
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
        <div class="w-px h-5 bg-zinc-200 mx-2"></div>

        <!-- Notification Bell -->
        <livewire:layout.notification-bell />

        <!-- Settings -->
        <a href="{{ route('management.settings') }}"
            class="p-2 text-zinc-400 hover:text-zinc-600 hover:bg-zinc-50 rounded-lg transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z" />
                <circle cx="12" cy="12" r="3" />
            </svg>
        </a>

        <!-- Spacer -->
        <div class="w-px h-5 bg-zinc-200 mx-1"></div>

        <!-- User Profile -->
        <div x-data="{ open: false }" class="relative">
            <button @click="open = !open" @click.outside="open = false"
                class="flex items-center gap-2.5 pl-1 pr-3 py-1 rounded-xl hover:bg-zinc-50 border border-transparent hover:border-zinc-200/60 transition-all group">
                <div
                    class="w-10 h-10 bg-indigo-600 rounded-full flex items-center justify-center text-white text-xs font-semibold flex-shrink-0">
                    {{ strtoupper(substr(auth()->user()->name, 0, 2)) }}
                </div>
                <div class="hidden sm:block text-left">
                    <p class="text-[12px] font-semibold text-zinc-800 leading-none">{{ auth()->user()->name }}</p>
                    <p class="text-[10px] text-zinc-400 mt-0.5">
                        {{ __(auth()->user()->roles->first()?->display_name ?: 'System User') }}
                    </p>
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                    class="hidden sm:block text-zinc-400 transition-transform duration-200 ml-0.5" :class="open ? 'rotate-180' : ''">
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
