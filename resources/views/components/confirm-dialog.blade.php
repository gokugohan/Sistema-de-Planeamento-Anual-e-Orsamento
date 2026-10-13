{{--
    Confirmation dialog in place of the browser's confirm(). Open it with
    $dispatch('open-modal', '<name>'); the confirm button carries the attributes you pass
    (typically wire:click="action"), and the dialog closes itself when either button is used.
--}}
@props(['name', 'title', 'message', 'confirm', 'variant' => 'primary'])

<x-modal :name="$name" max-width="md">
    <div class="p-6" role="alertdialog" aria-labelledby="{{ $name }}-title" aria-describedby="{{ $name }}-message">
        <h2 id="{{ $name }}-title" class="text-base font-semibold text-zinc-900">{{ $title }}</h2>
        <p id="{{ $name }}-message" class="mt-2 text-sm text-zinc-600">{{ $message }}</p>
        <div class="mt-6 flex justify-end gap-3">
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', '{{ $name }}')">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button :variant="$variant" x-on:click="$dispatch('close-modal', '{{ $name }}')" {{ $attributes }}>{{ $confirm }}</x-ui.button>
        </div>
    </div>
</x-modal>
