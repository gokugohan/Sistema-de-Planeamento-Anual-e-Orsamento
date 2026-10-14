@props(['padding' => 'p-5', 'header' => null, 'footer' => null, 'title' => null, 'description' => null])

<div {{ $attributes->merge(['class' => 'bg-white rounded-lg border border-zinc-200 shadow-card overflow-hidden']) }}>
    @if($title)
        <div class="flex items-start justify-between gap-4 px-5 py-4 border-b border-zinc-200">
            <div class="min-w-0">
                <h2 class="section-title">{{ $title }}</h2>
                @if($description)
                    <p class="mt-0.5 text-xs text-zinc-500">{{ $description }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @elseif(isset($header) && $header)
        <div class="px-5 py-4 border-b border-zinc-200">
            {{ $header }}
        </div>
    @endif

    <div class="{{ $padding }}">
        {{ $slot }}
    </div>

    @if(isset($footer) && $footer)
        <div class="px-5 py-3 border-t border-zinc-200 bg-zinc-50">
            {{ $footer }}
        </div>
    @endif
</div>
