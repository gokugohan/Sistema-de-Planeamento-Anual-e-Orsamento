{{-- A small icon button for a row of a list (edit, delete, add), with its name for screen readers and as a tooltip.
     <x-ui.icon-action icon="edit" :label="__('Edit').' 502'" wire:click="edit(1)" />
     A delete asks first when `confirm` is given (wire:confirm). --}}
@props(['icon' => 'edit', 'label', 'confirm' => null])

@php
    $tone = $icon === 'delete' ? 'hover:bg-rose-50 hover:text-rose-700' : 'hover:bg-indigo-50 hover:text-indigo-700';
@endphp
<button type="button" {{ $attributes->merge(['class' => "inline-flex h-8 w-8 items-center justify-center rounded-md text-zinc-500 transition-colors $tone focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600"]) }}
    aria-label="{{ $label }}" title="{{ $label }}" @if($confirm) wire:confirm="{{ $confirm }}" @endif>
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        @switch($icon)
            @case('delete')<path d="M3 6h18" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" /><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />@break
            @case('plus')<path d="M12 5v14" /><path d="M5 12h14" />@break
            @default<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z" /><path d="m15 5 4 4" />
        @endswitch
    </svg>
</button>
