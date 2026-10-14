{{--
    A dialog that asks for a short text before an action: a required reason (returning an activity)
    or an optional note (approving, reopening). Open it with $dispatch('open-modal', '<name>').
    The text is sent to the Livewire property named in `property`, then the `action` method runs;
    the server checks it again, so the button being disabled is a convenience, not the rule.
--}}
@props(['name', 'title', 'label', 'confirm', 'action', 'property', 'message' => null, 'required' => false, 'variant' => 'primary'])

<x-modal :name="$name" max-width="md">
    <div class="p-6" x-data="{ text: '' }" role="dialog" aria-labelledby="{{ $name }}-title">
        <h2 id="{{ $name }}-title" class="text-base font-semibold text-zinc-900">{{ $title }}</h2>
        @if($message)
            <p class="mt-2 text-sm text-zinc-600">{{ $message }}</p>
        @endif

        <label for="{{ $name }}-text" class="field-label mt-4">
            {{ $label }}
            @unless($required)<span class="font-normal text-zinc-500">({{ __('optional') }})</span>@endunless
        </label>
        <textarea id="{{ $name }}-text" x-model="text" rows="3" maxlength="1000" class="flux-input"></textarea>

        <div class="mt-6 flex justify-end gap-3">
            <x-ui.button variant="secondary" x-on:click="text = ''; $dispatch('close-modal', '{{ $name }}')">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button :variant="$variant"
                x-bind:disabled="{{ $required ? 'text.trim().length < 3' : 'false' }}"
                x-on:click="$wire.{{ $property }} = text; $wire.{{ $action }}(); text = ''; $dispatch('close-modal', '{{ $name }}')">{{ $confirm }}</x-ui.button>
        </div>
    </div>
</x-modal>
