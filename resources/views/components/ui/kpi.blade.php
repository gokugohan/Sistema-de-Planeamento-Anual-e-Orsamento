@props([
    'label',
    'value',
    'sub' => null,          // one line under the value
    'progress' => null,     // 0-100: thin bar at the bottom
    'tone' => 'indigo',     // indigo | emerald | amber | rose | zinc: colour of the bar and the accent
    'href' => null,
])

@php
    $bar = ['indigo' => 'bg-indigo-600', 'emerald' => 'bg-emerald-500', 'amber' => 'bg-amber-500', 'rose' => 'bg-rose-500', 'zinc' => 'bg-zinc-400'][$tone] ?? 'bg-indigo-600';
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} @if($href) href="{{ $href }}" wire:navigate @endif
    {{ $attributes->merge(['class' => 'block bg-white rounded-lg border border-zinc-200 shadow-card px-5 py-4 ' . ($href ? 'hover:border-indigo-300 transition-colors' : '')]) }}>
    <p class="text-sm font-medium text-zinc-500">{{ $label }}</p>
    <p class="mt-1.5 text-2xl font-bold tracking-tight text-zinc-900 tabular-nums">{{ $value }}</p>
    @if($sub)
        <p class="mt-1 text-xs text-zinc-500">{{ $sub }}</p>
    @endif
    @if($progress !== null)
        <div class="mt-3 h-1.5 rounded-full bg-zinc-100 overflow-hidden" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) $progress }}" aria-label="{{ $label }}">
            <div class="h-full rounded-full {{ $bar }}" style="width: {{ max(0, min(100, $progress)) }}%"></div>
        </div>
    @endif
</{{ $tag }}>
