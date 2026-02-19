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

<body class="font-sans antialiased">
    <div class="min-h-screen flex">

        <!-- ── Left Brand Panel ───────────────────────────────── -->
        <div class="hidden lg:flex lg:w-[460px] xl:w-[520px] flex-col relative overflow-hidden bg-zinc-950 flex-shrink-0">

            <!-- Gradient background -->
            <div class="absolute inset-0 bg-gradient-to-br from-indigo-950 via-zinc-950 to-zinc-950"></div>
            <div class="absolute top-0 left-0 w-[480px] h-[480px] bg-indigo-600/10 rounded-full blur-3xl -translate-x-1/2 -translate-y-1/2"></div>
            <div class="absolute bottom-0 right-0 w-[360px] h-[360px] bg-violet-600/5 rounded-full blur-3xl translate-x-1/3 translate-y-1/3"></div>

            <!-- Subtle grid -->
            <div class="absolute inset-0 opacity-[0.025]"
                style="background-image: linear-gradient(rgba(255,255,255,1) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(255,255,255,1) 1px, transparent 1px);
                    background-size: 40px 40px;">
            </div>

            <div class="relative flex flex-col h-full px-10 py-10">

                <!-- Logo -->
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 bg-indigo-600 rounded-xl flex items-center justify-center shadow-lg shadow-indigo-600/30 ring-1 ring-white/10">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"
                            stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 2v20" /><path d="m17 5-5-3-5 3" /><path d="m17 19-5 3-5-3" />
                            <path d="M2 12h20" /><path d="m5 7 3 5-3 5" /><path d="m19 7-3 5 3 5" />
                        </svg>
                    </div>
                    <div>
                        <span class="text-sm font-semibold text-white">{{ __('Ministry') }}</span>
                        <span class="block text-[10px] text-zinc-500 font-medium uppercase tracking-wider">{{ __('Budget Hub') }}</span>
                    </div>
                </div>

                <!-- Hero copy -->
                <div class="flex-1 flex flex-col justify-center py-12">
                    <div class="inline-flex items-center gap-2 bg-indigo-500/10 border border-indigo-500/20 rounded-full px-3 py-1.5 mb-8 w-fit">
                        <span class="w-1.5 h-1.5 rounded-full bg-indigo-400 animate-pulse"></span>
                        <span class="text-[11px] font-medium text-indigo-300 uppercase tracking-wider">{{ __('Annual Planning System') }}</span>
                    </div>

                    <h1 class="text-4xl xl:text-[2.75rem] font-semibold text-white leading-[1.2] tracking-tight mb-5">
                        {{ __('Strategic Budget') }}<br>
                        <span class="text-gradient">{{ __('Planning & Control') }}</span>
                    </h1>

                    <p class="text-[15px] text-zinc-400 leading-relaxed max-w-xs">
                        {{ __('Unified platform for annual activity planning, fiscal envelope management, and performance monitoring.') }}
                    </p>

                    <!-- Features -->
                    <div class="mt-10 space-y-3.5">
                        @foreach([
                            ['d' => 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 0 2-2h2a2 2 0 0 0 2 2',
                              'label' => __('PAA Activity Management')],
                            ['d' => 'M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z',
                              'label' => __('Fiscal Envelope Control')],
                            ['d' => 'M22 12h-4l-3 9L9 3l-3 9H2',
                              'label' => __('Performance Monitoring')],
                        ] as $f)
                            <div class="flex items-center gap-3">
                                <div class="w-7 h-7 rounded-lg bg-white/5 border border-white/8 flex items-center justify-center flex-shrink-0">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24"
                                        fill="none" stroke="rgb(165 180 252)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="{{ $f['d'] }}" />
                                    </svg>
                                </div>
                                <span class="text-sm text-zinc-400">{{ $f['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                <!-- Footer -->
                <p class="text-[11px] text-zinc-600">
                    &copy; {{ date('Y') }} {{ __('Ministry Budget Planning System') }}
                </p>
            </div>
        </div>

        <!-- ── Right Form Panel ──────────────────────────────── -->
        <div class="flex-1 flex flex-col items-center justify-center bg-zinc-50 px-6 py-12">

            <!-- Mobile logo -->
            <div class="lg:hidden flex items-center gap-3 mb-10">
                <div class="w-9 h-9 bg-indigo-600 rounded-xl flex items-center justify-center shadow-lg shadow-indigo-600/30">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"
                        stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 2v20" /><path d="m17 5-5-3-5 3" /><path d="m17 19-5 3-5-3" />
                        <path d="M2 12h20" /><path d="m5 7 3 5-3 5" /><path d="m19 7-3 5 3 5" />
                    </svg>
                </div>
                <span class="text-base font-semibold text-zinc-900">{{ __('Ministry') }}</span>
            </div>

            <!-- Form card -->
            <div class="w-full max-w-[400px]">
                <div class="bg-white rounded-2xl ring-1 ring-zinc-950/5 shadow-sm p-8">
                    {{ $slot }}
                </div>

                <p class="text-center text-xs text-zinc-400 mt-5">
                    {{ __('Secure government financial platform') }}
                </p>
            </div>
        </div>
    </div>
</body>

</html>
