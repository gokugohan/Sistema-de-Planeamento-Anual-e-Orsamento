@php
    $manuals = \App\Support\Manuals::for(auth()->user());
    $open = array_key_exists(request('m'), $manuals) ? request('m') : array_key_first($manuals);
@endphp
<x-app-layout title="{{ __('Manual') }}">
    <div class="space-y-6">
        <x-ui.page-header :title="__('Manual')" :subtitle="__('How to use the system, to read here or download as a PDF. The manuals are in English.')" />

        <div class="grid gap-4 {{ count($manuals) > 1 ? 'md:grid-cols-2' : '' }}">
            @foreach($manuals as $key => $m)
                @php $file = \App\Support\Manuals::path($key); @endphp
                <x-ui.card padding="p-5" :class="$key === $open && count($manuals) > 1 ? 'ring-2 ring-indigo-600' : ''">
                    <h2 class="text-base font-semibold text-zinc-900">{{ $m['title'] }}</h2>
                    <p class="mt-1 text-sm text-zinc-600">{{ $m['description'] }}</p>
                    @if(is_file($file))
                        <p class="mt-2 text-xs text-zinc-500">{{ __('PDF, :size MB, updated :date', ['size' => number_format(filesize($file) / 1048576, 1), 'date' => date('d/m/Y', filemtime($file))]) }}</p>
                        <div class="mt-4 flex flex-wrap gap-2">
                            @if($key !== $open)
                                <x-ui.button size="md" href="{{ route('manual', ['m' => $key]) }}" wire:navigate>{{ __('Read here') }}</x-ui.button>
                            @endif
                            <x-ui.button variant="secondary" size="md" shadow="none" href="{{ route('manual.file', ['manual' => $key, 'download' => 1]) }}">{{ __('Download PDF') }}</x-ui.button>
                            <x-ui.button variant="secondary" size="md" shadow="none" href="{{ route('manual.file', $key) }}" target="_blank" rel="noopener">{{ __('Open in a new tab') }}</x-ui.button>
                        </div>
                    @else
                        <p class="mt-2 text-sm text-amber-700">{{ __('This manual has not been built on this server yet.') }}</p>
                    @endif
                </x-ui.card>
            @endforeach
        </div>

        @if($open && is_file(\App\Support\Manuals::path($open)))
            <x-ui.card padding="p-0" class="overflow-hidden">
                <iframe src="{{ route('manual.file', $open) }}" title="{{ $manuals[$open]['title'] }}" class="block h-[80vh] w-full border-0" loading="lazy"></iframe>
            </x-ui.card>
        @endif
    </div>
</x-app-layout>
