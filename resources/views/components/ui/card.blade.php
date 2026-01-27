@props(['padding' => 'p-6', 'header' => null, 'footer' => null])

<div {{ $attributes->merge(['class' => 'premium-card rounded-3xl overflow-hidden']) }}>
    @if(isset($header) && $header)
        <div class="px-8 py-5 border-b border-slate-50 bg-slate-50/30">
            {{ $header }}
        </div>
    @endif

    <div class="{{ $padding }}">
        {{ $slot }}
    </div>

    @if(isset($footer) && $footer)
        <div class="px-8 py-4 border-t border-slate-50 bg-slate-50/10">
            {{ $footer }}
        </div>
    @endif
</div>