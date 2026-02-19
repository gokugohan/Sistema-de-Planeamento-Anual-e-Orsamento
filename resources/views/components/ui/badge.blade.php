@props(['variant' => 'indigo', 'pulse' => false])

@php
    $variants = [
        'indigo'  => 'bg-indigo-50 text-indigo-700 ring-indigo-200/60',
        'blue'    => 'bg-blue-50 text-blue-700 ring-blue-200/60',
        'green'   => 'bg-emerald-50 text-emerald-700 ring-emerald-200/60',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200/60',
        'red'     => 'bg-rose-50 text-rose-700 ring-rose-200/60',
        'yellow'  => 'bg-amber-50 text-amber-700 ring-amber-200/60',
        'amber'   => 'bg-amber-50 text-amber-700 ring-amber-200/60',
        'gray'    => 'bg-zinc-100 text-zinc-600 ring-zinc-200/60',
        'black'   => 'bg-zinc-900 text-white ring-transparent',
    ];

    $dotColors = [
        'indigo'  => 'bg-indigo-500',
        'blue'    => 'bg-blue-500',
        'green'   => 'bg-emerald-500',
        'emerald' => 'bg-emerald-500',
        'red'     => 'bg-rose-500',
        'yellow'  => 'bg-amber-500',
        'amber'   => 'bg-amber-500',
        'gray'    => 'bg-zinc-400',
        'black'   => 'bg-white',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 px-2.5 py-0.5 text-[11px] font-medium rounded-full ring-1 ' . ($variants[$variant] ?? $variants['indigo'])]) }}>
    @if($pulse)
        <span class="relative flex h-1.5 w-1.5 flex-shrink-0">
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full {{ $dotColors[$variant] ?? 'bg-current' }} opacity-60"></span>
            <span class="relative inline-flex rounded-full h-1.5 w-1.5 {{ $dotColors[$variant] ?? 'bg-current' }}"></span>
        </span>
    @endif
    {{ $slot }}
</span>
