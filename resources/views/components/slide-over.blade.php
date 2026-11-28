{{--
    A side panel whose open state lives on the server (@if($panelOpen) … @endif): it slides in from the right and
    keeps the page beside it in view, so the list being edited stays visible. Same behaviour as <x-dialog>: it
    announces itself as a dialog, keeps the keyboard inside, closes on Escape or a click on the shade, and stops the
    page from scrolling.

        <x-slide-over close="$set('panelOpen', false)">
            <x-slot:title>Edit program</x-slot:title>
            <x-slot:subtitle>Optional line of context</x-slot:subtitle>
            <form wire:submit="save" class="space-y-5 p-6"> … </form>
            <x-slot:footer> buttons </x-slot:footer>   (optional, stays at the bottom)
        </x-slide-over>
--}}
@props(['close', 'width' => 'xl'])

@php
    $max = ['md' => 'max-w-md', 'lg' => 'max-w-lg', 'xl' => 'max-w-xl', '2xl' => 'max-w-2xl'][$width] ?? 'max-w-xl';
@endphp

<div class="fixed inset-0 z-[100] flex justify-end bg-zinc-900/40"
    x-data x-trap.noscroll="true"
    x-on:keydown.escape.window="$wire.{{ $close }}"
    x-on:click.self="$wire.{{ $close }}">
    <div role="dialog" aria-modal="true" aria-labelledby="panel-title"
        class="flex h-full w-full {{ $max }} flex-col bg-white shadow-default"
        x-init="$el.animate([{ transform: 'translateX(2rem)', opacity: 0 }, { transform: 'none', opacity: 1 }], { duration: 160, easing: 'ease-out' })">
        <div class="flex items-start justify-between gap-4 border-b border-zinc-200 px-6 py-4">
            <div class="min-w-0">
                @isset($eyebrow)<p class="mb-0.5 text-xs font-medium text-zinc-500">{{ $eyebrow }}</p>@endisset
                <h2 id="panel-title" class="text-lg font-semibold text-zinc-900">{{ $title }}</h2>
                @isset($subtitle)
                    <p class="mt-0.5 text-sm text-zinc-500">{{ $subtitle }}</p>
                @endisset
            </div>
            <button type="button" wire:click="{{ $close }}" aria-label="{{ __('Close') }}"
                class="-mr-2 shrink-0 rounded-md p-2 text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-zinc-900">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" />
                </svg>
            </button>
        </div>

        <div class="flex-1 overflow-y-auto">
            {{ $slot }}
        </div>

        @isset($footer)
            <div class="flex items-center justify-end gap-3 border-t border-zinc-200 bg-zinc-50 px-6 py-3">{{ $footer }}</div>
        @endisset
    </div>
</div>
