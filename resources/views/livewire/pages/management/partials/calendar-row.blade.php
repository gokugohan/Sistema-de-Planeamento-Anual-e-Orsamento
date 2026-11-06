{{-- One step of the calendar: its dates edited in place and saved on their own. Needs $key, $label, $hint, $required, $state. --}}
@php $now = $state($key); @endphp
<div wire:key="cal-{{ $key }}" class="grid gap-3 border-t border-zinc-100 px-5 py-3 first:border-t-0 md:grid-cols-12 md:items-end">
    <div class="md:col-span-4">
        <p class="text-sm font-medium text-zinc-900">
            {{ $label }}
            @if($required)<span class="ml-1 rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-semibold text-indigo-700">{{ __('Required') }}</span>@endif
        </p>
        @if($hint)<p class="text-xs text-zinc-500">{{ $hint }}</p>@endif
    </div>
    <div class="md:col-span-3">
        <label for="o-{{ $key }}" class="field-label">{{ __('Opens on') }}</label>
        <input id="o-{{ $key }}" type="date" wire:model="dates.{{ $key }}.opens" class="flux-input">
        @error("dates.$key.opens")<p class="field-error">{{ $message }}</p>@enderror
    </div>
    <div class="md:col-span-3">
        <label for="c-{{ $key }}" class="field-label">{{ __('Closes on') }}</label>
        <input id="c-{{ $key }}" type="date" wire:model="dates.{{ $key }}.closes" class="flux-input">
        @error("dates.$key.closes")<p class="field-error">{{ $message }}</p>@enderror
    </div>
    <div class="flex items-center justify-between gap-2 md:col-span-2 md:justify-end">
        @if($now)
            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $now[1] }}">{{ $now[0] }}</span>
        @elseif($required)
            <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-800">{{ __('No dates') }}</span>
        @else
            <span class="inline-flex items-center rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-medium text-zinc-700">{{ __('Always open') }}</span>
        @endif
        <x-ui.button type="button" size="sm" variant="secondary" shadow="none" wire:click="saveStep('{{ $key }}')">{{ __('Save') }}</x-ui.button>
    </div>
</div>
