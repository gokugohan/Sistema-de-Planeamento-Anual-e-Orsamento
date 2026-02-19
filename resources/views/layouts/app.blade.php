<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel') }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="font-sans antialiased bg-zinc-50 text-zinc-900" x-data="{ sidebarOpen: false }">
    <div class="flex min-h-screen">

        <!-- Sidebar Navigation -->
        <livewire:layout.sidebar />

        <!-- Mobile Sidebar Overlay -->
        <div
            x-show="sidebarOpen"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            @click="sidebarOpen = false"
            class="fixed inset-0 bg-zinc-950/40 backdrop-blur-sm z-40 md:hidden"
        ></div>

        <!-- Main Workspace -->
        <div class="flex-1 flex flex-col md:ml-60 min-w-0">

            <!-- Top Bar -->
            <livewire:layout.topbar />

            <!-- Page Content -->
            <div class="flex-1 overflow-y-auto">

                <!-- Flash Messages -->
                @if (session()->has('success') || session()->has('error'))
                    <div class="px-6 pt-4 space-y-2">
                        @if (session('success'))
                            <div class="alert alert-success">
                                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                                    stroke-linejoin="round" class="flex-shrink-0">
                                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                                    <polyline points="22 4 12 14.01 9 11.01" />
                                </svg>
                                <span>{{ session('success') }}</span>
                            </div>
                        @endif
                        @if (session('error'))
                            <div class="alert alert-error">
                                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                                    stroke-linejoin="round" class="flex-shrink-0">
                                    <circle cx="12" cy="12" r="10" />
                                    <line x1="12" x2="12" y1="8" y2="12" />
                                    <line x1="12" x2="12.01" y1="16" y2="16" />
                                </svg>
                                <span>{{ session('error') }}</span>
                            </div>
                        @endif
                    </div>
                @endif

                <!-- Main Content Area -->
                <main class="px-6 py-6 animate-in fade-in duration-300">
                    @if (isset($header))
                        <div class="mb-6">
                            {{ $header }}
                        </div>
                    @endif

                    {{ $slot }}
                </main>
            </div>
        </div>
    </div>
</body>

</html>
