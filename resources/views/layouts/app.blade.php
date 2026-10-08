<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@if(!empty($title)){{ $title }} &mdash; @endif{{ \App\Models\SystemSetting::getLocalized('app_name', config('app.name')) }} &mdash; {{ \App\Models\SystemSetting::getLocalized('app_tagline', '') }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="font-sans antialiased bg-body text-zinc-600" x-data="{ sidebarOpen: false }">

    <!-- Livewire Request Progress Bar -->
    <div id="lwbar" class="fixed top-0 left-0 z-[200] h-[2px] bg-indigo-600 pointer-events-none opacity-0 transition-[width,opacity] duration-300 ease-out" style="width:0%"></div>
    <script>
        document.addEventListener('livewire:init', () => {
            let bar = document.getElementById('lwbar'), t;
            Livewire.hook('commit', ({ succeed, fail }) => {
                clearTimeout(t);
                bar.style.width = '0%'; bar.style.opacity = '1';
                requestAnimationFrame(() => { bar.style.width = '40%'; });
                succeed(() => {
                    bar.style.width = '100%';
                    t = setTimeout(() => { bar.style.opacity = '0'; setTimeout(() => bar.style.width = '0%', 300); }, 250);
                });
                fail(() => {
                    bar.style.background = 'rgb(239 68 68)';
                    bar.style.width = '100%';
                    t = setTimeout(() => { bar.style.opacity = '0'; setTimeout(() => { bar.style.width = '0%'; bar.style.background = ''; }, 300); }, 600);
                });
            });
        });
        document.addEventListener('livewire:navigating', () => {
            let bar = document.getElementById('lwbar');
            bar.style.opacity = '1'; bar.style.width = '60%';
        });
        document.addEventListener('livewire:navigated', () => {
            let bar = document.getElementById('lwbar');
            bar.style.width = '100%';
            setTimeout(() => { bar.style.opacity = '0'; setTimeout(() => bar.style.width = '0%', 300); }, 250);
        });
    </script>

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
        <div class="flex-1 flex flex-col md:ml-64 min-w-0">

            <!-- Top Bar -->
            <livewire:layout.topbar />

            <!-- Page Content -->
            <div class="flex-1 overflow-y-auto">

                <!-- Main Content Area -->
                <main class="mx-auto w-full max-w-screen-2xl p-4 md:p-6 2xl:p-10 animate-in fade-in duration-300">
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

    <!-- Toast Notifications -->
    <div
        x-data="{
            toasts: [],
            add(message, type) {
                const id = Date.now() + Math.random();
                this.toasts.push({ id, message, type: type || 'info' });
                setTimeout(() => this.remove(id), 4500);
            },
            remove(id) {
                this.toasts = this.toasts.filter(t => t.id !== id);
            }
        }"
        @toast.window="add($event.detail.message, $event.detail.type)"
        x-init="
            @if(session('success')) $nextTick(() => add(@js(session('success')), 'success')) @endif
            @if(session('error'))   $nextTick(() => add(@js(session('error')),   'error'))   @endif
            @if(session('message')) $nextTick(() => add(@js(session('message')), 'info'))    @endif
        "
        class="fixed top-4 right-4 z-[60] flex flex-col gap-2.5 w-80 pointer-events-none"
        aria-live="polite"
    >
        <template x-for="toast in toasts" :key="toast.id">
            <div
                x-show="true"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0 translate-x-8"
                x-transition:enter-end="opacity-100 translate-x-0"
                x-transition:leave="transition ease-in duration-200"
                x-transition:leave-start="opacity-100 translate-x-0"
                x-transition:leave-end="opacity-0 translate-x-8"
                class="flex items-start gap-3 px-4 py-3.5 bg-white rounded-md border border-zinc-200 shadow-default pointer-events-auto"
            >
                <!-- Icon -->
                <div
                    class="flex-shrink-0 w-7 h-7 rounded-full flex items-center justify-center mt-0.5"
                    :class="{
                        'bg-emerald-100': toast.type === 'success',
                        'bg-rose-100':    toast.type === 'error',
                        'bg-indigo-100':  toast.type === 'info'
                    }"
                >
                    <svg
                        class="w-3.5 h-3.5"
                        :class="{
                            'text-emerald-600': toast.type === 'success',
                            'text-rose-600':    toast.type === 'error',
                            'text-indigo-600':  toast.type === 'info'
                        }"
                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                    >
                        <!-- success: check -->
                        <g x-show="toast.type === 'success'">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                            <polyline points="22 4 12 14.01 9 11.01"/>
                        </g>
                        <!-- error: X -->
                        <g x-show="toast.type === 'error'">
                            <line x1="18" y1="6" x2="6" y2="18"/>
                            <line x1="6" y1="6" x2="18" y2="18"/>
                        </g>
                        <!-- info: i -->
                        <g x-show="toast.type === 'info'">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" x2="12" y1="8" y2="12"/>
                            <line x1="12" x2="12.01" y1="16" y2="16"/>
                        </g>
                    </svg>
                </div>

                <!-- Message -->
                <p class="flex-1 text-sm text-zinc-700 leading-relaxed pt-0.5" x-text="toast.message"></p>

                <!-- Dismiss -->
                <button
                    @click="remove(toast.id)"
                    class="flex-shrink-0 text-zinc-400 hover:text-zinc-600 transition-colors mt-0.5"
                    aria-label="Dismiss"
                >
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
        </template>
    </div>
</body>

</html>
