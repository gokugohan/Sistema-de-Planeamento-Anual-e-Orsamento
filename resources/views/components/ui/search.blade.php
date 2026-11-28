{{-- The search box of a list: an input with a magnifier, labelled for screen readers by its placeholder.
     <x-ui.search wire:model.live.debounce.300ms="search" :placeholder="__('Search by code or name')" /> --}}
@props(['placeholder' => null, 'id' => 'list-search'])

<div class="relative w-full sm:w-80">
    <label for="{{ $id }}" class="sr-only">{{ $placeholder ?? __('Search') }}</label>
    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" /></svg>
    <input id="{{ $id }}" type="search" {{ $attributes->merge(['class' => 'flux-input pl-9']) }} placeholder="{{ $placeholder ?? __('Search') }}" autocomplete="off">
</div>
