<?php

use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;

new class extends Component {
    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<nav x-data="{ open: false }" class="bg-white border-b border-gray-100">
    <!-- Primary Navigation Menu -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('dashboard') }}" wire:navigate>
                        <x-application-logo class="block h-9 w-auto fill-current text-gray-800" />
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </x-nav-link>

                    <!-- Admin: Entities -->
                    <x-nav-link :href="route('admin.entities')" :active="request()->routeIs('admin.entities')"
                        wire:navigate>
                        {{ __('Entities') }}
                    </x-nav-link>

                    <!-- Management Dropdown -->
                    <div class="hidden sm:flex sm:items-center sm:ms-4">
                        <x-dropdown align="right" width="48">
                            <x-slot name="trigger">
                                <button
                                    class="inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-gray-500 hover:text-gray-700 hover:border-gray-300 focus:outline-none focus:text-gray-700 focus:border-gray-300 transition duration-150 ease-in-out">
                                    {{ __('Management') }}
                                    <div class="ms-1">
                                        <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg"
                                            viewBox="0 0 20 20">
                                            <path fill-rule="evenodd"
                                                d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                clip-rule="evenodd" />
                                        </svg>
                                    </div>
                                </button>
                            </x-slot>
                            <x-slot name="content">
                                <x-dropdown-link :href="route('management.settings')" wire:navigate>
                                    {{ __('System Settings') }}
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('management.users')" wire:navigate>
                                    {{ __('Manage Users') }}
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('management.entities')" wire:navigate>
                                    {{ __('Manage Entities') }}
                                </x-dropdown-link>
                            </x-slot>
                        </x-dropdown>
                    </div>

                    <!-- 2. Planeamento do PAA -->
                    <x-nav-link :href="route('activities.index')" :active="request()->routeIs('activities.*')"
                        wire:navigate>
                        {{ __('2. Planeamento') }}
                    </x-nav-link>

                    <!-- 3. Orçamento (Consolidated) -->
                    <x-nav-link :href="route('budget.consolidation')" :active="request()->routeIs('budget.*')"
                        wire:navigate>
                        {{ __('3. Orçamento') }}
                    </x-nav-link>

                    <!-- 4. Monitorização e Execução -->
                    <x-nav-link :href="route('monitoring.index')" :active="request()->routeIs('monitoring.*')"
                        wire:navigate>
                        {{ __('4. Monitorização') }}
                    </x-nav-link>

                    <!-- APERFEIÇOAMENTO: Approval Dropdown -->
                    @if(auth()->user()->hasAnyRole(['Admin', 'Planning', 'Budget', 'Director']))
                        <div class="hidden sm:flex sm:items-center sm:ms-4">
                            <x-dropdown align="right" width="48">
                                <x-slot name="trigger">
                                    <button
                                        class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-black rounded-md text-indigo-600 bg-white hover:text-indigo-800 focus:outline-none transition ease-in-out duration-150">
                                        {{ __('Gov. Control') }}
                                        <div class="ms-1">
                                            <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg"
                                                viewBox="0 0 20 20">
                                                <path fill-rule="evenodd"
                                                    d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                    clip-rule="evenodd" />
                                            </svg>
                                        </div>
                                    </button>
                                </x-slot>
                                <x-slot name="content">
                                    <x-dropdown-link :href="route('monitoring.approval')" wire:navigate>
                                        {{ __('Progress Approval') }}
                                    </x-dropdown-link>
                                    @if(auth()->user()->hasAnyRole(['Admin', 'Planning']))
                                        <x-dropdown-link :href="route('planning.paa-approval')" wire:navigate>
                                            {{ __('PAA Approval') }}
                                        </x-dropdown-link>
                                        <x-dropdown-link :href="route('planning.fiscal')" wire:navigate>
                                            {{ __('Fiscal Hub') }}
                                        </x-dropdown-link>
                                    @endif
                                </x-slot>
                            </x-dropdown>
                        </div>
                    @endif

                    <!-- 5. Relatórios e Integração -->
                    <x-nav-link :href="route('reports.paa')" :active="request()->routeIs('reports.*')" wire:navigate>
                        {{ __('5. Relatórios') }}
                    </x-nav-link>

                    @if(App\Models\SystemSetting::get('enable_paa_testing_mode', false))
                        <x-nav-link :href="route('testing.index')" :active="request()->routeIs('testing.*')" wire:navigate
                            class="!text-amber-600 font-bold border-amber-600/20 bg-amber-50/50">
                            {{ __('🧪 Testing Mode') }}
                        </x-nav-link>
                    @endif
                </div>
            </div>

            <!-- Settings Dropdown -->
            <div class="hidden sm:flex sm:items-center sm:ms-6">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button
                            class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 bg-white hover:text-gray-700 focus:outline-none transition ease-in-out duration-150">
                            <div x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name"
                                x-on:profile-updated.window="name = $event.detail.name"></div>

                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg"
                                    viewBox="0 0 20 20">
                                    <path fill-rule="evenodd"
                                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                        clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile')" wire:navigate>
                            {{ __('Profile') }}
                        </x-dropdown-link>

                        <!-- Authentication -->
                        <button wire:click="logout" class="w-full text-start">
                            <x-dropdown-link>
                                {{ __('Log Out') }}
                            </x-dropdown-link>
                        </button>
                    </x-slot>
                </x-dropdown>
            </div>

            <!-- Hamburger -->
            <div class="-me-2 flex items-center sm:hidden">
                <button @click="open = ! open"
                    class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 focus:text-gray-500 transition duration-150 ease-in-out">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex"
                            stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round"
                            stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="pt-2 pb-3 space-y-1">
            <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')" wire:navigate>
                {{ __('Dashboard') }}
            </x-responsive-nav-link>

            <!-- Admin: Entities -->
            <x-responsive-nav-link :href="route('admin.entities')" :active="request()->routeIs('admin.entities')"
                wire:navigate>
                {{ __('Entities') }}
            </x-responsive-nav-link>

            <!-- Management Section -->
            <div class="pt-4 pb-1 border-t border-gray-200 bg-gray-50/50">
                <div class="px-4 text-xs font-black text-gray-400 uppercase tracking-widest mb-2">
                    {{ __('System Management') }}
                </div>
                <x-responsive-nav-link :href="route('management.settings')"
                    :active="request()->routeIs('management.settings')" wire:navigate>
                    {{ __('System Settings') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('management.users')"
                    :active="request()->routeIs('management.users')" wire:navigate>
                    {{ __('Manage Users') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('management.entities')"
                    :active="request()->routeIs('management.entities')" wire:navigate>
                    {{ __('Manage Entities') }}
                </x-responsive-nav-link>
            </div>

            <!-- 2. Planeamento do PAA -->
            <x-responsive-nav-link :href="route('activities.index')" :active="request()->routeIs('activities.*')"
                wire:navigate>
                {{ __('2. Planeamento') }}
            </x-responsive-nav-link>

            <!-- 3. Orçamento (Consolidated) -->
            <x-responsive-nav-link :href="route('budget.consolidation')" :active="request()->routeIs('budget.*')"
                wire:navigate>
                {{ __('3. Orçamento') }}
            </x-responsive-nav-link>

            <!-- 4. Monitorização e Execução -->
            <x-responsive-nav-link :href="route('monitoring.index')" :active="request()->routeIs('monitoring.*')"
                wire:navigate>
                {{ __('4. Monitorização') }}
            </x-responsive-nav-link>

            @if(auth()->user()->hasAnyRole(['Admin', 'Planning', 'Budget', 'Director']))
                <div class="pt-4 pb-2 border-t border-indigo-100 bg-indigo-50/30">
                    <div class="px-4 text-[10px] font-black text-indigo-400 uppercase tracking-widest mb-2">
                        {{ __('Governance & Control') }}
                    </div>
                    <x-responsive-nav-link :href="route('monitoring.approval')"
                        :active="request()->routeIs('monitoring.approval')" wire:navigate>
                        {{ __('Progress Approval') }}
                    </x-responsive-nav-link>
                    @if(auth()->user()->hasAnyRole(['Admin', 'Planning']))
                        <x-responsive-nav-link :href="route('planning.paa-approval')"
                            :active="request()->routeIs('planning.paa-approval')" wire:navigate>
                            {{ __('PAA Selection Approval') }}
                        </x-responsive-nav-link>
                        <x-responsive-nav-link :href="route('planning.fiscal')" :active="request()->routeIs('planning.fiscal')"
                            wire:navigate>
                            {{ __('Fiscal Management Hub') }}
                        </x-responsive-nav-link>
                    @endif
                </div>
            @endif

            <!-- 5. Relatórios e Integração -->
            <x-responsive-nav-link :href="route('reports.paa')" :active="request()->routeIs('reports.*')" wire:navigate>
                {{ __('5. Relatórios') }}
            </x-responsive-nav-link>

            @if(App\Models\SystemSetting::get('enable_paa_testing_mode', false))
                <x-responsive-nav-link :href="route('testing.index')" :active="request()->routeIs('testing.*')"
                    wire:navigate class="text-amber-600 font-bold bg-amber-50 border-l-4 border-amber-500">
                    {{ __('🧪 Testing Mode') }}
                </x-responsive-nav-link>
            @endif
        </div>

        <!-- Responsive Settings Options -->
        <div class="pt-4 pb-1 border-t border-gray-200">
            <div class="px-4">
                <div class="font-medium text-base text-gray-800"
                    x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name"
                    x-on:profile-updated.window="name = $event.detail.name"></div>
                <div class="font-medium text-sm text-gray-500">{{ auth()->user()->email }}</div>
            </div>

            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile')" wire:navigate>
                    {{ __('Profile') }}
                </x-responsive-nav-link>

                <!-- Authentication -->
                <button wire:click="logout" class="w-full text-start">
                    <x-responsive-nav-link>
                        {{ __('Log Out') }}
                    </x-responsive-nav-link>
                </button>
            </div>
        </div>
    </div>
</nav>