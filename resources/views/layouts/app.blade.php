<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel') }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="font-sans antialiased text-gray-900 overflow-x-hidden" x-data="{ sidebarOpen: false }">
    <div class="flex min-h-screen bg-gray-50/50">
        <!-- Sidebar Navigation -->
        <livewire:layout.sidebar />

        <!-- Mobile Sidebar Overlay -->
        <div x-show="sidebarOpen" x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0" @click="sidebarOpen = false"
            class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-40 md:hidden"></div>

        <!-- Main Workspace (Flexible) -->
        <div class="flex-1 flex flex-col md:ml-64 min-w-0 transition-all duration-300">
            <!-- Top Utility Bar -->
            <livewire:layout.topbar />

            <!-- Dynamic Page Content -->
            <div class="flex-1 overflow-y-auto">
                {{-- Flash Messages --}}
                @if (session()->has('success') || session()->has('error'))
                    <div class="px-8 mt-4">
                        @if (session('success'))
                            <div
                                class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg flex items-center gap-3 animate-in slide-in-from-top-2 duration-300">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                                    <polyline points="22 4 12 14.01 9 11.01" />
                                </svg>
                                <span class="text-sm font-bold">{{ session('success') }}</span>
                            </div>
                        @endif
                        @if (session('error'))
                            <div
                                class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg flex items-center gap-3 animate-in slide-in-from-top-2 duration-300">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10" />
                                    <line x1="12" x2="12" y1="8" y2="12" />
                                    <line x1="12" x2="12.01" y1="16" y2="16" />
                                </svg>
                                <span class="text-sm font-bold">{{ session('error') }}</span>
                            </div>
                        @endif
                    </div>
                @endif

                <!-- Content Area -->
                <main class="px-8 py-2 animate-in fade-in duration-500">
                    <!-- Integrated Page Header -->
                    @if (isset($header))
                        <div class="mb-4">
                            <div class="space-y-1">
                                {{ $header }}
                            </div>
                        </div>
                    @endif

                    <div class="w-full">
                        {{ $slot }}
                    </div>
                </main>
            </div>
        </div>
    </div>
</body>

</html>