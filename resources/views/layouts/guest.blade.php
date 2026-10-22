@php
    use App\Models\SystemSetting;

    $org = SystemSetting::getLocalized('org_name', 'Institution Name');
    $orgAbbr = SystemSetting::getLocalized('org_abbr', 'ORG');
    // Never show the framework's default name ("Laravel") as the product name.
    $product = SystemSetting::getLocalized('app_name', '');
    if ($product === '' || strcasecmp($product, 'Laravel') === 0) {
        $product = __('Annual Action Plan');
    }
    $tagline = SystemSetting::getLocalized('app_tagline', '');
    $description = SystemSetting::getLocalized('app_description', '');
    $owner = SystemSetting::get('system_owner', '');
    $implementedBy = SystemSetting::get('implemented_by', '');

    // Institution logo (Settings), if one was provided; the browser icon follows it.
    $logoUrl = \App\Support\Branding::logoUrl();

    // The cycle the application supports, in the order users live it.
    $steps = [
        [__('Write the plan'), __('Units write their activities, indicators and budget lines')],
        [__('Review & approve'), __('Prepared, certified and approved by the head of each directorate')],
        [__('Finalize'), __('Each directorate finalizes its approved plan')],
        [__('Report'), __('Report month by month and verify the results against the targets')],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="{{ \App\Support\Branding::faviconUrl() }}">

    <title>{{ __('Sign in') }} &mdash; {{ $product }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="font-sans antialiased bg-white text-zinc-600">
    <a href="#auth-main" class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:bg-white focus:text-indigo-700 focus:px-4 focus:py-2 focus:rounded-md focus:shadow-default">{{ __('Skip to content') }}</a>

    <div class="min-h-screen grid lg:grid-cols-[minmax(420px,5fr)_6fr]">

        {{-- ─────────── Pane 1: who and what ─────────── --}}
        <aside class="hidden lg:flex flex-col relative overflow-hidden bg-gradient-to-br from-indigo-800 via-indigo-900 to-indigo-950 text-indigo-100" aria-label="{{ __('About this system') }}">
            {{-- quiet grid texture, no decoration that competes with the content --}}
            <div class="absolute inset-0 opacity-[0.07] pointer-events-none"
                style="background-image: linear-gradient(#fff 1px, transparent 1px), linear-gradient(90deg, #fff 1px, transparent 1px); background-size: 48px 48px;"></div>
            <div class="absolute inset-x-0 top-0 h-1 bg-indigo-400"></div>

            <div class="relative flex flex-col h-full px-12 xl:px-16 py-12">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-indigo-200">República Democrática de Timor-Leste</p>

                {{-- The institution: its emblem, large, then who and what this system is --}}
                <div class="flex-1 flex flex-col justify-center py-10 max-w-xl">
                    @if($logoUrl)
                        <img src="{{ $logoUrl }}" alt="" class="h-36 xl:h-40 w-auto self-start rounded-2xl bg-white p-4 shadow-xl shadow-black/30">
                    @else
                        <div class="h-28 w-28 rounded-2xl bg-indigo-600 flex items-center justify-center shadow-xl shadow-black/30" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 21h18" /><path d="M5 21V9l7-5 7 5v12" /><path d="M9 21v-6h6v6" />
                            </svg>
                        </div>
                    @endif

                    <p class="mt-8 text-2xl xl:text-[1.75rem] font-bold text-white leading-tight tracking-tight">{{ $org }}</p>
                    <p class="mt-2 text-base font-medium text-indigo-200">{{ $product }}</p>
                    <div class="mt-6 h-px w-16 bg-white/30"></div>
                    <h2 class="mt-6 text-lg font-medium text-white leading-relaxed">
                        {{ $tagline !== '' ? $tagline : __('The Annual Action Plan, from the first activity to the report of the year') }}
                    </h2>
                    @if($description !== '')
                        <p class="mt-3 text-[15px] leading-relaxed text-indigo-100">{{ $description }}</p>
                    @endif

                    <ol class="mt-8 grid grid-cols-2 gap-x-8 gap-y-5" aria-label="{{ __('The annual cycle') }}">
                        @foreach($steps as $i => [$title, $text])
                            <li class="flex gap-3">
                                <span class="mt-0.5 h-6 w-6 shrink-0 rounded-full border border-white/50 bg-white/10 text-xs font-semibold text-white flex items-center justify-center" aria-hidden="true">{{ $i + 1 }}</span>
                                <div>
                                    <p class="text-sm font-semibold text-white">{{ $title }}</p>
                                    <p class="text-sm leading-relaxed text-indigo-200">{{ $text }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </div>

                {{-- Assurance and credits --}}
                <div class="space-y-5">
                    <ul class="flex flex-wrap gap-x-6 gap-y-2 text-[13px] text-indigo-100">
                        <li class="flex items-center gap-2">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-indigo-200" aria-hidden="true"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            {{ __('Role-based access') }}
                        </li>
                        <li class="flex items-center gap-2">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-indigo-200" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/><path d="m9 12 2 2 4-4"/></svg>
                            {{ __('Every change is audited') }}
                        </li>
                    </ul>

                    <div class="flex items-center justify-between gap-6 border-t border-white/20 pt-5">
                        <p class="text-xs leading-relaxed text-indigo-200">
                            &copy; {{ date('Y') }} {{ $owner ?: $org }}<br>{{ __('All rights reserved') }}
                        </p>
                        <p class="text-xs text-indigo-200 text-right leading-tight">{{ __('Implemented by') }}<br><span class="text-white font-medium">{{ $implementedBy ?: \App\Support\Branding::IMPLEMENTER }}</span></p>
                    </div>
                </div>
            </div>
        </aside>

        {{-- ─────────── Pane 2: the task ─────────── --}}
        <div class="flex flex-col min-h-screen bg-white">
            <header class="flex items-center justify-between px-6 sm:px-10 py-5">
                {{-- compact identity when pane 1 is hidden --}}
                <div class="lg:hidden flex items-center gap-3 min-w-0">
                    @if($logoUrl)
                        <img src="{{ $logoUrl }}" alt="" class="h-11 w-auto max-w-[3.25rem] object-contain">
                    @else
                        <div class="h-9 w-9 rounded-md bg-indigo-600 flex items-center justify-center" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18" /><path d="M5 21V9l7-5 7 5v12" /><path d="M9 21v-6h6v6" /></svg>
                        </div>
                    @endif
                    <span class="text-sm font-semibold text-zinc-900 truncate">{{ $orgAbbr ?: $org }}</span>
                </div>

                <nav class="ml-auto flex items-center gap-0.5 p-1 bg-zinc-100 rounded-md" aria-label="{{ __('Language') }}">
                    @foreach(['en' => 'EN', 'pt' => 'PT', 'tet' => 'TET'] as $code => $label)
                        <a href="{{ route('lang.switch', $code) }}" @if(app()->getLocale() === $code) aria-current="true" @endif
                            class="px-2.5 py-1 text-xs font-medium rounded transition-colors {{ app()->getLocale() === $code ? 'bg-white text-zinc-900 shadow-sm' : 'text-zinc-500 hover:text-zinc-800' }}">{{ $label }}</a>
                    @endforeach
                </nav>
            </header>

            <main id="auth-main" class="flex-1 flex items-center justify-center px-6 sm:px-10 py-8">
                <div class="w-full max-w-[26rem]">
                    {{ $slot }}
                </div>
            </main>

            <footer class="px-6 sm:px-10 py-6">
                <div class="mx-auto w-full max-w-[26rem] text-xs text-zinc-400 flex flex-wrap items-center justify-between gap-2">
                    <span>{{ __('Authorised users only. Activity on this system is logged.') }}</span>
                    <span class="lg:hidden">&copy; {{ date('Y') }} {{ $owner ?: $org }}</span>
                </div>
            </footer>
        </div>
    </div>
</body>

</html>
