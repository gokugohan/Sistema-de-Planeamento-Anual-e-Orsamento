@props(['padding' => 'p-5', 'header' => null, 'footer' => null])

<div {{ $attributes->merge(['class' => 'bg-white rounded-xl ring-1 ring-zinc-950/5 shadow-sm overflow-hidden']) }}>
    @if(isset($header) && $header)
        <div class="px-5 py-4 border-b border-zinc-100">
            {{ $header }}
        </div>
    @endif

    <div class="{{ $padding }}">
        {{ $slot }}
    </div>

    @if(isset($footer) && $footer)
        <div class="px-5 py-3 border-t border-zinc-100 bg-zinc-50/50">
            {{ $footer }}
        </div>
    @endif
</div>
