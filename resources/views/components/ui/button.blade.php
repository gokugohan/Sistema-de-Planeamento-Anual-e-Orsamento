@props(['variant' => 'primary', 'size' => 'md', 'type' => 'button', 'shadow' => 'sm', 'className' => ''])

@php
    $baseClasses = 'inline-flex items-center justify-center font-medium transition-all duration-150 '
                 . 'disabled:opacity-50 disabled:cursor-not-allowed active:scale-[0.98] focus:outline-none '
                 . 'focus:ring-2 focus:ring-offset-1';

    $variants = [
        'primary'      => 'bg-indigo-600 text-white hover:bg-indigo-700 focus:ring-indigo-500/30 border border-transparent',
        'secondary'    => 'bg-white text-zinc-700 border border-zinc-200 hover:border-zinc-300 hover:bg-zinc-50 hover:text-zinc-900 focus:ring-zinc-300/50',
        'ghost'        => 'text-zinc-500 hover:text-zinc-800 hover:bg-zinc-100 border border-transparent focus:ring-zinc-300/50',
        'danger'       => 'bg-rose-600 text-white hover:bg-rose-700 focus:ring-rose-500/30 border border-transparent',
        'black'        => 'bg-indigo-600 text-white hover:bg-indigo-700 focus:ring-indigo-500/30 border border-transparent', // legacy name: now the primary style
        'outline-black'=> 'bg-transparent text-zinc-900 border-2 border-zinc-900 hover:bg-zinc-900 hover:text-white focus:ring-zinc-700/30',
    ];

    $shadows = [
        'none' => '',
        'sm'   => 'shadow-sm',
        'md'   => 'shadow-md',
        'lg'   => 'shadow-lg',
    ];

    $sizes = [
        'xs'   => 'px-2.5 py-1 text-xs rounded-md',
        'sm'   => 'px-3.5 py-1.5 text-[13px] rounded-lg',
        'md'   => 'px-4 py-2 text-sm rounded-lg',
        'lg'   => 'px-6 py-3 text-sm rounded-xl',
        'icon' => 'p-2 rounded-lg',
    ];

    $classes = implode(' ', array_filter([
        $baseClasses,
        $className,
        $variants[$variant] ?? $variants['primary'],
        $sizes[$size] ?? $sizes['md'],
        $shadows[$shadow] ?? $shadows['sm'],
    ]));
@endphp

@if($attributes->has('href'))
    <a {{ $attributes->merge(['class' => $classes]) }}>
        {{ $slot }}
    </a>
@else
    <button {{ $attributes->merge(['type' => $type, 'class' => $classes]) }}>
        {{ $slot }}
    </button>
@endif
