{{--
    A dialog whose open state lives on the server (@if($modalOpen) … @endif): the overlay, the panel,
    a title, a close button and the keyboard behaviour. It announces itself as a dialog, keeps the
    keyboard inside while it is open, closes on Escape or a click outside, and stops the page behind
    it from scrolling.

        <x-dialog max-width="2xl" close="$set('modalOpen', false)">
            <x-slot:title>Create unit</x-slot:title>
            <x-slot:subtitle>Optional line of context</x-slot:subtitle>
            <form wire:submit="save" class="p-6 space-y-5"> … </form>
        </x-dialog>

    `close` is the Livewire expression that closes it. Labels inside should use `for`/`id` (see .field-label).
--}}
@props(['close', 'maxWidth' => '2xl'])

@php
    $width = ['md' => 'max-w-md', 'lg' => 'max-w-lg', 'xl' => 'max-w-xl', '2xl' => 'max-w-2xl', '3xl' => 'max-w-3xl', '4xl' => 'max-w-4xl', '5xl' => 'max-w-5xl'][$maxWidth] ?? 'max-w-2xl';
@endphp

<div class="fixed inset-0 z-[100] flex items-start justify-center overflow-y-auto bg-zinc-900/50 p-4 sm:items-center"
    x-data x-trap.noscroll="true"
    x-on:keydown.escape.window="$wire.{{ $close }}"
    x-on:click.self="$wire.{{ $close }}">
    <div role="dialog" aria-modal="true" aria-labelledby="dialog-title"
        class="my-6 w-full {{ $width }} overflow-hidden rounded-lg bg-white shadow-default">
        <div class="flex items-start justify-between gap-4 border-b border-zinc-200 px-6 py-4">
            <div class="min-w-0">
                <h2 id="dialog-title" class="text-lg font-semibold text-zinc-900">{{ $title }}</h2>
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

        <div class="max-h-[calc(100vh-10rem)] overflow-y-auto">
            {{ $slot }}
        </div>
    </div>
</div>
