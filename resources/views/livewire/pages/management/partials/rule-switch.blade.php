{{-- One rule: a plain question, a switch, and the longer explanation on demand. Needs $prop, $question, $details. --}}
<div class="flex items-start justify-between gap-6 border-t border-zinc-100 px-5 py-4 first:border-t-0" x-data="{ more: false }">
    <div class="min-w-0">
        <p id="q-{{ $prop }}" class="text-sm font-medium text-zinc-900">{{ $question }}</p>
        <button type="button" @click="more = ! more" :aria-expanded="more.toString()" class="mt-0.5 text-xs font-medium text-indigo-700 hover:underline">
            <span x-show="! more">{{ __('Details') }}</span><span x-show="more" x-cloak>{{ __('Hide details') }}</span>
        </button>
        <p x-show="more" x-cloak class="mt-1 max-w-2xl text-xs leading-relaxed text-zinc-600">{{ $details }}</p>
    </div>
    <label class="relative mt-0.5 inline-flex shrink-0 cursor-pointer items-center">
        <input type="checkbox" wire:model="{{ $prop }}" class="peer sr-only" role="switch" aria-labelledby="q-{{ $prop }}">
        <span class="h-6 w-11 rounded-full bg-zinc-300 transition-colors peer-checked:bg-indigo-600 peer-focus-visible:ring-2 peer-focus-visible:ring-indigo-600 peer-focus-visible:ring-offset-2"></span>
        <span class="pointer-events-none absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-[#fff] shadow transition-transform peer-checked:translate-x-5"></span>
    </label>
</div>
