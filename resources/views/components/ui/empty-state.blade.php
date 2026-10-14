{{-- Said when a list has nothing to show: what happened and what to do next. --}}
@props(['title', 'text' => null])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center px-6 py-14 text-center']) }}>
    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-zinc-100 text-zinc-400" aria-hidden="true">
        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <path d="M22 12h-6l-2 3h-4l-2-3H2" /><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z" />
        </svg>
    </div>
    <p class="mt-4 text-sm font-semibold text-zinc-900">{{ $title }}</p>
    @if($text)
        <p class="mt-1 max-w-md text-sm text-zinc-500">{{ $text }}</p>
    @endif
    @if(trim((string) $slot) !== '')
        <div class="mt-5 flex flex-wrap justify-center gap-2">{{ $slot }}</div>
    @endif
</div>
