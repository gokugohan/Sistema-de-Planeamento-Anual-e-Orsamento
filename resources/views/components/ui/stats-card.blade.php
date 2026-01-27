@props([
    'label',
    'value',
    'description' => null,
    'trend' => null, // 'up', 'down'
    'trendValue' => null,
    'icon' => null,
])

<div {{ $attributes->merge(['class' => 'premium-card p-6 rounded-3xl flex flex-col justify-between']) }}>
    <div class="flex justify-between items-start mb-4">
        <div>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ $label }}</p>
            <h3 class="text-2xl font-black text-slate-900 tracking-tight">{{ $value }}</h3>
        </div>
        @if(isset($icon))
            <div class="w-10 h-10 rounded-xl bg-indigo-50 flex items-center justify-center text-indigo-600 shadow-sm shadow-indigo-100/50">
                @if($icon === 'envelope')
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                @else
                    {{ $icon }}
                @endif
            </div>
        @endif
    </div>

    @if($description || $trend)
        <div class="flex items-center gap-2 mt-auto">
            @if($trend)
                <span class="inline-flex items-center gap-1 text-[10px] font-bold {{ $trend === 'up' ? 'text-emerald-500' : 'text-rose-500' }}">
                    @if($trend === 'up')
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
                    @else
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 17 13.5 8.5 8.5 13.5 2 7"/><polyline points="16 17 22 17 22 11"/></svg>
                    @endif
                    {{ $trendValue }}
                </span>
            @endif
            <p class="text-[10px] text-slate-400 font-medium italic">{{ $description }}</p>
        </div>
    @endif
</div>