@props(['variant' => 'primary', 'size' => 'md', 'type' => 'button', 'shadow' => 'sm', 'className' => ''])

@php
    $baseClasses = 'inline-flex items-center justify-center font-black uppercase tracking-widest transition-all duration-300 disabled:opacity-50 disabled:cursor-not-allowed group rounded-xl overflow-hidden active:scale-[0.98]';

    $variants = [
        'primary' => 'bg-indigo-600 text-white hover:bg-indigo-700 hover:shadow-indigo-500/25 border border-transparent',
        'secondary' => 'bg-white text-slate-700 border border-slate-200 hover:border-indigo-200 hover:bg-slate-50 hover:text-indigo-600 shadow-sm',
        'ghost' => 'text-slate-500 hover:text-indigo-600 hover:bg-indigo-50 border border-transparent',
        'danger' => 'bg-rose-500 text-white hover:bg-rose-600 hover:shadow-rose-500/25 border border-transparent',
        'black' => 'text-white bg-slate-900 icon-white hover:bg-black hover:shadow-black/25 border border-transparent',
        'outline-black' => 'bg-transparent text-slate-900 border-2 border-slate-900 hover:bg-slate-900 hover:text-white',
    ];

    $classes = [''];

    $shadows = [
        'none' => '',
        'sm' => 'shadow-sm',
        'md' => 'shadow-md',
        'lg' => 'shadow-lg',
    ];

    $sizes = [
        'xs' => 'px-3 py-1.5 text-[9px] rounded-lg',
        'sm' => 'px-4 py-2 text-[10px] rounded-xl',
        'md' => 'px-5 py-2.5 text-xs rounded-xl',
        'lg' => 'px-8 py-4 text-sm rounded-2xl',
        'icon' => 'p-2.5 rounded-xl',
    ];

    $classes = $baseClasses . ' ' . $className . ' ' . ($variants[$variant] ?? $variants['primary']) . ' ' . ($sizes[$size] ?? $sizes['md']) . ' ' . ($shadows[$shadow] ?? $shadows['sm']);
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