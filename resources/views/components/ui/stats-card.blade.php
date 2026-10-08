@props([
    'label',
    'value',
    'description' => null,
    'trend'       => null,   // 'up' | 'down'
    'trendValue'  => null,
    'icon'        => null,
])

<div {{ $attributes->merge(['class' => 'bg-white rounded-lg border border-zinc-200 shadow-card px-6 py-5']) }}>
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-sm font-medium text-zinc-500 mb-2 truncate">{{ $label }}</p>
            <p class="text-2xl font-semibold text-zinc-900 tracking-tight">{{ $value }}</p>
        </div>

        @if(isset($icon))
            <div class="w-11 h-11 rounded-full bg-zinc-100 flex items-center justify-center text-indigo-600 flex-shrink-0">
                @if($icon === 'envelope')
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect width="20" height="16" x="2" y="4" rx="2"/>
                        <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>
                    </svg>
                @else
                    {{ $icon }}
                @endif
            </div>
        @endif
    </div>

    @if($description || $trend)
        <div class="mt-4 pt-3 border-t border-zinc-200 flex items-center gap-2 flex-wrap">
            @if($trend)
                <span class="inline-flex items-center gap-1 text-xs font-medium
                    {{ $trend === 'up' ? 'text-emerald-600' : 'text-rose-500' }}">
                    @if($trend === 'up')
                        <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/>
                            <polyline points="16 7 22 7 22 13"/>
                        </svg>
                    @else
                        <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="22 17 13.5 8.5 8.5 13.5 2 7"/>
                            <polyline points="16 17 22 17 22 11"/>
                        </svg>
                    @endif
                    {{ $trendValue }}
                </span>
            @endif
            @if($description)
                <p class="text-xs text-zinc-400">{{ $description }}</p>
            @endif
        </div>
    @endif
</div>
