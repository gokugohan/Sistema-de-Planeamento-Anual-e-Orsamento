@props(['variant' => 'indigo', 'pulse' => false])

@php
    $variants = [
        'indigo' => 'bg-indigo-50 text-indigo-700 border-indigo-100/50',
        'blue' => 'bg-blue-50 text-blue-700 border-blue-100/50',
        'green' => 'bg-emerald-50 text-emerald-700 border-emerald-100/50',
        'red' => 'bg-rose-50 text-rose-700 border-rose-100/50',
        'yellow' => 'bg-amber-50 text-amber-700 border-amber-100/50',
        'amber' => 'bg-amber-50 text-amber-700 border-amber-100/50',
        'gray' => 'bg-slate-100 text-slate-600 border-slate-200/50',
        'black' => 'bg-slate-900 text-white border-transparent',
    ];

    $dotColors = [
        'indigo' => 'bg-indigo-500',
        'blue' => 'bg-blue-500',
        'green' => 'bg-emerald-500',
        'red' => 'bg-rose-500',
        'yellow' => 'bg-amber-500',
        'amber' => 'bg-amber-500',
        'gray' => 'bg-slate-400',
        'black' => 'bg-white',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 px-3 py-1 text-[9px] font-black uppercase tracking-widest rounded-lg border shadow-sm/5 ' . ($variants[$variant] ?? $variants['indigo'])]) }}>
    @if($pulse)
        <span class="relative flex h-1.5 w-1.5">
            <span
                class="animate-ping absolute inline-flex h-full w-full rounded-full {{ $dotColors[$variant] ?? 'bg-current' }} opacity-75"></span>
            <span class="relative inline-flex rounded-full h-1.5 w-1.5 {{ $dotColors[$variant] ?? 'bg-current' }}"></span>
        </span>
    @endif
    {{ $slot }}
</span>